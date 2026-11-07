<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use PDO;

final class MembershipBatchRepository
{
    private const COLUMNS = 'id,supplier_id,created_by,confirmed_by,confirmed_at,batch_uid,idempotency_key,request_hash,template_json,selection_mode,selection_json,seed_cursor,seed_until,phase,approval_token,approval_expires_at,member_count,created_at';
    private const ITEM_COLUMNS = 'member_id,status,amount_cents,preview_hash,expected_case_version,operation_id,deferred_cents,deferral_date,error_code,error_message,attempts';
    public function __construct(private readonly Connection $db) {}

    public function find(int $supplier, int $id, bool $lock = false): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM nonprofit_membership_batches WHERE supplier_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->row($row);
    }

    public function byKey(int $supplier, string $key): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM nonprofit_membership_batches WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
        $q->execute([$supplier, $key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->row($row);
    }

    public function recent(int $supplier, int $before = PHP_INT_MAX): array
    {
        $q = $this->db->pdo()->prepare("SELECT id,phase,member_count,created_at,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.title_key')) title_key,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.period_from')) period_from,
            JSON_UNQUOTE(JSON_EXTRACT(template_json,'$.period_to')) period_to
            FROM nonprofit_membership_batches WHERE supplier_id=? AND id<? ORDER BY id DESC LIMIT 20");
        $q->execute([$supplier, $before]);
        return array_map(static function (array $row): array {
            foreach (['id', 'member_count'] as $field) $row[$field] = (int) $row[$field];
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insert(int $supplier, string $key, string $hash, array $template, string $mode, int $actor, array $ids = []): int
    {
        $q = $this->db->pdo()->prepare('SELECT id FROM nonprofit_members FORCE INDEX(uq_nm_tenant) WHERE supplier_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$supplier]);
        $until = (int) $q->fetchColumn();
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_batches(supplier_id,created_by,idempotency_key,request_hash,template_json,selection_mode,batch_uid,selection_json,seed_until,created_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier, $actor, $key, $hash, NonprofitTaxRules::canonicalJson($template), $mode, bin2hex(random_bytes(16)), NonprofitTaxRules::canonicalJson($ids), $until]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function assertSelection(int $supplier, array $ids): void
    {
        $found = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $q = $this->db->pdo()->prepare('SELECT id FROM nonprofit_members WHERE supplier_id=? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $q->execute([$supplier, ...$chunk]);
            $found += count($q->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($found !== count($ids)) throw new MembershipException('nonprofit_membership_batch_selection_invalid', 'Výběr obsahuje člena mimo tuto organizaci.', 422);
    }

    /** Právě jedna trvale uložená stránka; další požadavek naváže po commitu. */
    public function seed(int $supplier, int $batch, int $maximum = 5000): void
    {
        // Vlastní zámek dávky: žádný supplier lock během skenování členů a historie.
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $batch, $maximum): void {
            $current = $this->find($supplier, $batch, true);
            if ($current['phase'] !== 'seeding') return;
            $mode = $current['selection_mode']; $ids = $current['member_ids'];
            $date = $current['template']['accounting_date'];
            $cents = JournalLineAmount::cents($current['template']['amount']);
            $after = $current['seed_cursor']; $count = 0; $pending = 0; $rejected = 0;
            $selection = '';
            $params = [$supplier, $after, $current['seed_until']];
            if ($mode === 'selected') {
                $selection = ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...$ids);
            }
            $q = $this->db->pdo()->prepare('SELECT id FROM nonprofit_members FORCE INDEX(uq_nm_tenant) WHERE supplier_id=? AND id>? AND id<=?' . $selection . ' ORDER BY id LIMIT 500');
            $q->execute($params);
            $page = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            $rows = [];
            foreach (array_chunk($page, 50) as $chunk) {
                $contexts = (new MembershipRepository($this->db))->batchContexts($supplier, $chunk);
                foreach ($chunk as $member) {
                    $error = $contexts[$member]['error'] ?? null;
                    $revision = $error === null ? MembershipRevisionTimeline::at($contexts[$member]['history'], $date) : null;
                    // Neprůkazná historie je viditelná chyba; pouze prokazatelně neaktivní člen se nevybírá.
                    if ($mode === 'active' && $error === null && $revision !== null && $revision['status'] !== 'active') continue;
                    if ($revision === null && $error === null) $error = new MembershipException('nonprofit_membership_inactive', 'Členství není k účetnímu datu aktivní.', 409);
                    if ($current['member_count'] + ++$count > $maximum) throw new MembershipException('nonprofit_membership_batch_limit', 'Výběr přesahuje maximální velikost dávky.', 422);
                    $status = $error === null ? 'pending' : 'rejected';
                    $error === null ? ++$pending : ++$rejected;
                    $rows[] = [$supplier, $batch, $member, $cents, $status, $error?->errorCode, $error === null ? null : mb_substr($error->getMessage(), 0, 2000)];
                }
            }
            if ($rows !== []) {
                $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_batch_members(supplier_id,batch_id,member_id,amount_cents,status,error_code,error_message) VALUES' . implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?)')));
                $q->execute(array_merge(...$rows));
            }
            if ($page !== []) $after = $page[array_key_last($page)];
            $this->db->pdo()->prepare('UPDATE nonprofit_membership_batches SET phase=?,seed_cursor=?,member_count=member_count+?,pending_count=pending_count+?,rejected_count=rejected_count+? WHERE supplier_id=? AND id=?')
                ->execute([count($page) < 500 ? 'preparing' : 'seeding', $after, $count, $pending, $rejected, $supplier, $batch]);
        });
    }

    public function workPage(int $supplier, int $batch, string $status, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        // Stav je součást prefixu indexu: pokračování přeskočí i velký již dokončený začátek dávky.
        $lock=$this->db->pdo()->inTransaction() ? ' FOR UPDATE' : '';
        $q = $this->db->pdo()->prepare('SELECT ' . self::ITEM_COLUMNS . " FROM nonprofit_membership_batch_members FORCE INDEX(ix_nmbm_status) WHERE supplier_id=? AND batch_id=? AND status=? ORDER BY member_id LIMIT {$limit}{$lock}");
        $q->execute([$supplier, $batch, $status]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function prepared(int $supplier, int $batch, array $rows): void
    {
        if ($rows === []) return;
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $batch, $rows): void {
            if ($this->find($supplier, $batch, true)['phase'] !== 'preparing') return;
            $this->changeRows($supplier, $batch, $rows, 'pending');
        });
    }

    public function workItem(int $supplier, int $batch, int $member): ?array
    {
        $q = $this->db->pdo()->prepare('SELECT ' . self::ITEM_COLUMNS . " FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=? AND status='ready' FOR UPDATE");
        $q->execute([$supplier, $batch, $member]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Zamčené původní hodnoty a CAS zajistí jediný přírůstek i při opakování stránky. */
    private function changeRows(int $supplier, int $batch, array $rows, string $from): void
    {
        $ids = array_column($rows, 'member_id');
        $q = $this->db->pdo()->prepare('SELECT ' . self::ITEM_COLUMNS . ' FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND status=? FOR UPDATE');
        $q->execute([$supplier, $batch, ...$ids, $from]);
        $old = array_column($q->fetchAll(PDO::FETCH_ASSOC), null, 'member_id');
        $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($old[$row['member_id']])));
        if ($rows === []) return;
        $delta = array_fill_keys(['pending', 'ready', 'created', 'existing', 'rejected', 'planned_cents', 'created_cents', 'deferred_cents'], 0);
        $date = null;
        foreach ($rows as &$row) {
            $row += ['amount_cents' => $old[$row['member_id']]['amount_cents'], 'preview_hash' => null, 'expected_case_version' => null,
                'operation_id' => null, 'deferred_cents' => 0, 'deferral_date' => null, 'error_code' => null, 'error_message' => null];
            foreach ([[$old[$row['member_id']], -1], [$row, 1]] as [$value, $sign]) {
                $delta[$value['status']] += $sign;
                if (in_array($value['status'], ['ready', 'created'], true)) {
                    $delta['planned_cents'] += $sign * (int) $value['amount_cents'];
                    $delta['deferred_cents'] += $sign * (int) $value['deferred_cents'];
                    if ((int) $value['deferred_cents'] > 0) $date = $value['deferral_date'];
                }
                if ($value['status'] === 'created') $delta['created_cents'] += $sign * (int) $value['amount_cents'];
            }
        }
        unset($row);
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batch_members i
            JOIN nonprofit_membership_batches b ON b.supplier_id=i.supplier_id AND b.id=i.batch_id
            JOIN JSON_TABLE(?, '$[*]' COLUMNS(
              member_id BIGINT PATH '$.member_id', status VARCHAR(16) COLLATE utf8mb4_unicode_ci PATH '$.status', amount_cents BIGINT PATH '$.amount_cents',
              preview_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PATH '$.preview_hash', expected_case_version INT PATH '$.expected_case_version',
              operation_id BIGINT PATH '$.operation_id', deferred_cents BIGINT PATH '$.deferred_cents', deferral_date DATE PATH '$.deferral_date',
              error_code VARCHAR(100) COLLATE utf8mb4_unicode_ci PATH '$.error_code', error_message VARCHAR(2000) COLLATE utf8mb4_unicode_ci PATH '$.error_message'
            )) p ON p.member_id=i.member_id
            SET i.status=p.status,i.amount_cents=p.amount_cents,i.preview_hash=p.preview_hash,i.expected_case_version=p.expected_case_version,
                i.operation_id=p.operation_id,i.deferred_cents=p.deferred_cents,i.deferral_date=p.deferral_date,i.error_code=p.error_code,i.error_message=p.error_message
            WHERE i.supplier_id=? AND i.batch_id=? AND i.status=?");
        $q->execute([NonprofitTaxRules::canonicalJson($rows), $supplier, $batch, $from]);
        $q = $this->db->pdo()->prepare('UPDATE nonprofit_membership_batches SET pending_count=pending_count+?,ready_count=ready_count+?,created_count=created_count+?,existing_count=existing_count+?,rejected_count=rejected_count+?,planned_cents=planned_cents+?,created_cents=created_cents+?,deferred_cents=deferred_cents+?,deferral_date=IF(deferred_cents=0,NULL,COALESCE(?,deferral_date)) WHERE supplier_id=? AND id=?');
        $q->execute([...array_values($delta), $date, $supplier, $batch]);
    }

    public function finishPreparation(int $supplier, int $batch): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches b SET phase='ready',approval_token=?,approval_expires_at=UTC_TIMESTAMP(6)+INTERVAL 24 HOUR
            WHERE b.supplier_id=? AND b.id=? AND b.phase='preparing' AND NOT EXISTS(
              SELECT 1 FROM nonprofit_membership_batch_members i FORCE INDEX(ix_nmbm_status) WHERE i.supplier_id=b.supplier_id AND i.batch_id=b.id AND i.status='pending')");
        $q->execute([bin2hex(random_bytes(32)), $supplier, $batch]);
    }

    public function beginPosting(int $supplier, int $batch, string $token, int $actor): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches SET phase='posting',confirmed_by=COALESCE(confirmed_by,?),confirmed_at=COALESCE(confirmed_at,UTC_TIMESTAMP(6)) WHERE supplier_id=? AND id=? AND phase='ready' AND approval_token=? AND approval_expires_at>UTC_TIMESTAMP(6)");
        $q->execute([$actor, $supplier, $batch, $token]);
    }

    public function posted(int $supplier, int $batch, int $member, string $status, ?int $operation, ?string $code = null, ?string $message = null): void
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $batch, $member, $status, $operation, $code, $message): void {
            $this->find($supplier, $batch, true);
            $q = $this->db->pdo()->prepare('SELECT ' . self::ITEM_COLUMNS . " FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=? AND status='ready' FOR UPDATE");
            $q->execute([$supplier, $batch, $member]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row === false) return;
            $row['status'] = $status; $row['operation_id'] = $operation; $row['error_code'] = $code;
            $row['error_message'] = $message === null ? null : mb_substr($message, 0, 2000);
            $this->changeRows($supplier, $batch, [$row], 'ready');
        });
    }

    public function finishPosting(int $supplier, int $batch): void
    {
        $q = $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches b SET phase='finished' WHERE b.supplier_id=? AND b.id=? AND b.phase='posting'
            AND NOT EXISTS(SELECT 1 FROM nonprofit_membership_batch_members i FORCE INDEX(ix_nmbm_status) WHERE i.supplier_id=b.supplier_id AND i.batch_id=b.id AND i.status='ready')");
        $q->execute([$supplier, $batch]);
    }

    public function approvalValid(int $supplier, int $batch, string $token): bool
    {
        // Schválení nesmí pocházet ze snímku před souběžným zrušením či obnovením náhledu.
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Ověření schválení vyžaduje transakci.');
        $q = $this->db->pdo()->prepare("SELECT id FROM nonprofit_membership_batches WHERE supplier_id=? AND id=? AND approval_token=? AND approval_expires_at>UTC_TIMESTAMP(6) AND phase IN ('ready','posting','finished') FOR UPDATE");
        $q->execute([$supplier, $batch, $token]);
        return $q->fetchColumn() !== false;
    }

    public function attempts(int $supplier, int $batch, int $member): int
    {
        $this->db->pdo()->prepare("UPDATE nonprofit_membership_batch_members SET attempts=attempts+1 WHERE supplier_id=? AND batch_id=? AND member_id=? AND status='ready' AND attempts<3")->execute([$supplier, $batch, $member]);
        $q = $this->db->pdo()->prepare('SELECT attempts FROM nonprofit_membership_batch_members WHERE supplier_id=? AND batch_id=? AND member_id=?');
        $q->execute([$supplier, $batch, $member]);
        return (int) $q->fetchColumn();
    }

    /** Obnova nepřepisuje již potvrzený finanční důkaz ani klíč jeho replaye. */
    public function renewPreview(int $supplier, int $batch): void
    {
        $current = $this->find($supplier, $batch, true);
        if (!in_array($current['phase'], ['preparing', 'ready', 'posting'], true)) {
            throw new MembershipException('nonprofit_membership_batch_not_ready', 'Tuto dávku nelze znovu připravit.', 409);
        }
        while (($items = $this->workPage($supplier, $batch, 'ready', 50)) !== []) {
            // Finanční commit mohl předcházet zápisu výsledku při starším běhu.
            $q = $this->db->pdo()->prepare("SELECT i.member_id,o.id operation_id FROM nonprofit_membership_batch_members i
                JOIN nonprofit_membership_charge_commands c ON c.supplier_id=i.supplier_id AND c.idempotency_key=CONCAT('batch-',?,'-member-',i.member_id)
                JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.command_id=c.id
                WHERE i.supplier_id=? AND i.batch_id=? AND i.member_id IN (" . implode(',', array_fill(0, count($items), '?')) . ') FOR UPDATE');
            $q->execute([$current['batch_uid'], $supplier, $batch, ...array_column($items, 'member_id')]);
            $committed = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($items as &$item) {
                $item['status'] = isset($committed[$item['member_id']]) ? 'created' : 'pending';
                $item['operation_id'] = $committed[$item['member_id']] ?? null;
                if ($item['status'] === 'pending') {
                    $item['preview_hash'] = null; $item['expected_case_version'] = null;
                    $item['deferred_cents'] = 0; $item['deferral_date'] = null;
                    $item['error_code'] = null; $item['error_message'] = null;
                }
            }
            unset($item);
            $this->changeRows($supplier, $batch, $items, 'ready');
        }
        $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches SET phase='preparing',approval_token=NULL,approval_expires_at=NULL WHERE supplier_id=? AND id=?")->execute([$supplier, $batch]);
    }

    public function cancel(int $supplier, int $batch): void
    {
        $this->find($supplier, $batch, true);
        $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches SET phase='cancelled',approval_token=NULL,approval_expires_at=NULL WHERE supplier_id=? AND id=? AND phase<>'finished'")->execute([$supplier, $batch]);
    }

    public function summary(int $supplier, int $batch, bool $current = false): array
    {
        $q = $this->db->pdo()->prepare('SELECT member_count total,pending_count pending,ready_count ready,created_count created,existing_count existing,rejected_count rejected,CAST(planned_cents AS CHAR) planned_cents,CAST(created_cents AS CHAR) created_cents,CAST(deferred_cents AS CHAR) deferred_cents,deferral_date FROM nonprofit_membership_batches WHERE supplier_id=? AND id=?' . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $batch]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        foreach (['total', 'pending', 'ready', 'created', 'existing', 'rejected'] as $field) $row[$field] = (int) $row[$field];
        return $row;
    }

    public function page(int $supplier, int $batch, int $after, bool $current = false): array
    {
        $q = $this->db->pdo()->prepare("SELECT i.member_id,i.status,i.amount_cents,i.operation_id,i.error_code,i.error_message,m.member_number,c.company_name
            FROM nonprofit_membership_batch_members i JOIN nonprofit_members m ON m.supplier_id=i.supplier_id AND m.id=i.member_id
            JOIN clients c ON c.supplier_id=m.supplier_id AND c.id=m.client_id
            WHERE i.supplier_id=? AND i.batch_id=? AND i.member_id>? ORDER BY i.member_id LIMIT 50" . ($current ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $batch, $after]);
        return array_map(static function (array $row): array {
            $row['member_id'] = (int) $row['member_id'];
            $row['amount_cents'] = (int) $row['amount_cents'];
            $row['operation_id'] = $row['operation_id'] === null ? null : (int) $row['operation_id'];
            return $row;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    private function row(array $row): array
    {
        foreach (['id', 'supplier_id', 'created_by', 'member_count', 'seed_cursor', 'seed_until'] as $field) $row[$field] = (int) $row[$field];
        $row['confirmed_by'] = $row['confirmed_by'] === null ? null : (int) $row['confirmed_by'];
        $row['template'] = json_decode($row['template_json'], true, flags: JSON_THROW_ON_ERROR);
        $row['member_ids'] = json_decode($row['selection_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['template_json'], $row['selection_json']);
        return $row;
    }
}
