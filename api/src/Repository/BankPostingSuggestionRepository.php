<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\DbErrorLogger;
use PDO;
use PDOException;

/**
 * Repository pro bank_posting_suggestions — fronta návrhů zaúčtování + protokol
 * automatiky (mini-epic AUTOMATIZACE).
 *
 * Unikátnost „max 1 pending na transakci" vynucuje DB (PERSISTENT generovaný
 * sloupec `pending_tx` + UNIQUE uq_bps_pending) — createIfNoPending insert prostě
 * zkusí a chytá SQLSTATE 23000 (žádný check-then-insert race, M2).
 */
final class BankPostingSuggestionRepository
{
    public const AI_SOURCES = ['knn', 'llm'];

    private const COLS = 'id, supplier_id, bank_transaction_id, rule_id, source, debit_account_code,
        credit_account_code, amount, description, status, note, journal_entry_id, reviewed_by,
        reviewed_at, confidence, detector, operation_type, tax_advance_schedule_id,
        ai_reasoning, ai_model, ai_provider, ai_prompt_version, batch_id,
        snoozed_until, snooze_reason, snoozed_by, created_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * Vloží pending návrh, pokud pro tx žádný pending neexistuje. Při souběhu
     * (23000 na uq_bps_pending) vrátí existující pending — bez duplicity.
     *
     * @param array{supplier_id:int, bank_transaction_id:int, rule_id:?int, source:string,
     *   debit_account_code:string, credit_account_code:string, amount:float|string,
     *   description?:?string, note?:?string} $data
     * @return array{id:int, created:bool}
     */
    public function createIfNoPending(array $data): array
    {
        $pdo = $this->db->pdo();
        try {
            // Kolize na uq_bps_pending znamená „návrh pro tuhle transakci už visí" —
            // ošetřený a očekávaný výsledek. Bez tohohle rozsahu ji logovací PDO
            // zapíše jako ERROR dřív, než se k ní dostane catch níž.
            DbErrorLogger::expectingDuplicates(['uq_bps_pending'], fn (): bool => $pdo->prepare(
                'INSERT INTO bank_posting_suggestions
                    (supplier_id, bank_transaction_id, rule_id, source, debit_account_code,
                     credit_account_code, amount, description, status, note, confidence, detector,
                     operation_type, tax_advance_schedule_id, ai_reasoning, ai_model,
                     ai_provider, ai_prompt_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['supplier_id'],
                $data['bank_transaction_id'],
                $data['rule_id'] ?? null,
                $data['source'],
                $data['debit_account_code'],
                $data['credit_account_code'],
                $data['amount'],
                $data['description'] ?? null,
                $data['status'] ?? 'pending',
                $data['note'] ?? null,
                $data['confidence'] ?? null,
                $data['detector'] ?? null,
                $data['operation_type'] ?? null,
                $data['tax_advance_schedule_id'] ?? null,
                $data['ai_reasoning'] ?? null,
                $data['ai_model'] ?? null,
                $data['ai_provider'] ?? null,
                $data['ai_prompt_version'] ?? null,
            ]));
            return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $existing = $this->pendingForTx((int) $data['supplier_id'], (int) $data['bank_transaction_id']);
            if ($existing === null) {
                throw $e;
            }
            return ['id' => (int) $existing['id'], 'created' => false];
        }
    }

    /**
     * Protokolový řádek automatiky (status='auto_posted' + journal_entry_id). Nemá
     * pending_tx (NULL) → unikátnost pending neblokuje. Vrací id.
     *
     * @param array{supplier_id:int, bank_transaction_id:int, rule_id:?int, source:string,
     *   debit_account_code:string, credit_account_code:string, amount:float|string,
     *   description?:?string, journal_entry_id:int} $data
     */
    public function createAutoPosted(array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
                'INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, rule_id, source, debit_account_code,
                 credit_account_code, amount, description, status, journal_entry_id, reviewed_at,
                 confidence, detector, operation_type, tax_advance_schedule_id, note,
                 ai_reasoning, ai_model, ai_provider, ai_prompt_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "auto_posted", ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['supplier_id'],
            $data['bank_transaction_id'],
            $data['rule_id'] ?? null,
            $data['source'],
            $data['debit_account_code'],
            $data['credit_account_code'],
            $data['amount'],
            $data['description'] ?? null,
            $data['journal_entry_id'],
            $data['confidence'] ?? null,
            $data['detector'] ?? null,
            $data['operation_type'] ?? null,
            $data['tax_advance_schedule_id'] ?? null,
            $data['note'] ?? null,
            $data['ai_reasoning'] ?? null,
            $data['ai_model'] ?? null,
            $data['ai_provider'] ?? null,
            $data['ai_prompt_version'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function pendingForTx(int $supplierId, int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND status IN ("pending","needs_input","blocked")
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** @param array{debit_account_code:string,credit_account_code:string,amount:float|string,description:?string,note:?string,status?:string,confidence?:float,detector?:string,operation_type?:string} $data */
    public function refreshPendingTransfer(int $supplierId, int $id, array $data): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions
                SET debit_account_code = ?, credit_account_code = ?, amount = ?, description = ?, note = ?,
                    status = ?, confidence = ?, detector = ?, operation_type = ?
              WHERE supplier_id = ? AND id = ? AND source = "transfer"
                AND status IN ("pending","needs_input","blocked")'
        );
        $stmt->execute([
            $data['debit_account_code'], $data['credit_account_code'], $data['amount'],
            $data['description'], $data['note'], $data['status'] ?? 'pending',
            $data['confidence'] ?? null, $data['detector'] ?? null, $data['operation_type'] ?? null,
            $supplierId, $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_suggestions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findForUpdate(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_suggestions
              WHERE supplier_id = ? AND id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function findByEntryId(int $supplierId, int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_suggestions
              WHERE supplier_id = ? AND journal_entry_id = ? AND status IN ("approved","auto_posted")
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** True pokud pro (tx, rule) existuje odmítnutý návrh (M3a: nenabízet znovu). */
    public function hasRejected(int $supplierId, int $txId, int $ruleId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND rule_id = ? AND status = "rejected"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId, $ruleId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Per-detector reject memory pro návrhy bez rule_id. */
    public function hasRejectedSource(int $supplierId, int $txId, string $source): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND source = ? AND status = "rejected"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId, $source]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasRejectedDetector(int $supplierId, int $txId, string $detector): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND detector = ? AND status = "rejected"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId, $detector]);
        return $stmt->fetchColumn() !== false;
    }

    /** Pending návrhy tx → superseded (ignore / unpost / manual post; M3c). */
    public function supersedePendingForTx(int $supplierId, int $txId, ?string $note = null): int
    {
        return $this->supersede($supplierId, $txId, ['pending', 'needs_input', 'blocked'], $note);
    }

    /** Po stornu/smazání zachová původní rozhodnutí v protokolu a vrátí kontaci do fronty. */
    public function requeueReversedForTx(
        int $supplierId,
        int $txId,
        int $journalEntryId,
        string $note = 'reversed_by_user',
    ): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM bank_posting_suggestions
              WHERE supplier_id=? AND bank_transaction_id=? AND journal_entry_id=?
                AND status IN ("approved","auto_posted")
              ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$supplierId, $txId, $journalEntryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return false;

        $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions SET status="superseded", note=?
              WHERE id=? AND supplier_id=?'
        )->execute([$note, (int) $row['id'], $supplierId]);
        $this->createIfNoPending([
            'supplier_id' => $supplierId,
            'bank_transaction_id' => $txId,
            'rule_id' => $row['rule_id'] === null ? null : (int) $row['rule_id'],
            'source' => (string) $row['source'],
            'debit_account_code' => (string) $row['debit_account_code'],
            'credit_account_code' => (string) $row['credit_account_code'],
            'amount' => (float) $row['amount'],
            'description' => $row['description'],
            'status' => 'pending',
            'note' => null,
            'confidence' => $row['confidence'] === null ? null : (float) $row['confidence'],
            'detector' => $row['detector'],
            'operation_type' => $row['operation_type'],
            'tax_advance_schedule_id' => $row['tax_advance_schedule_id'] === null ? null : (int) $row['tax_advance_schedule_id'],
        ]);
        return true;
    }

    /** Pending i auto_posted návrhy tx → superseded (H3 rewrite přes match). */
    public function supersedeMatchedForTx(int $supplierId, int $txId, string $note = 'overwritten_by_match'): int
    {
        return $this->supersede($supplierId, $txId, ['pending', 'needs_input', 'blocked', 'auto_posted'], $note);
    }

    /**
     * @param list<string> $statuses
     */
    private function supersede(int $supplierId, int $txId, array $statuses, ?string $note): int
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $sql = 'UPDATE bank_posting_suggestions SET status = "superseded"'
            . ($note !== null ? ', note = ?' : '')
            . " WHERE supplier_id = ? AND bank_transaction_id = ? AND status IN ({$in})";
        $params = [];
        if ($note !== null) {
            $params[] = $note;
        }
        $params[] = $supplierId;
        $params[] = $txId;
        foreach ($statuses as $s) {
            $params[] = $s;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Approve → zaúčtováno. Podmínka status='pending' je pojistka proti souběhu (M2). */
    public function markApproved(int $supplierId, int $id, int $entryId, ?int $reviewedBy): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions
                SET status = "approved", journal_entry_id = ?, reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ? AND supplier_id = ? AND status IN ("pending","needs_input","blocked")'
        );
        $stmt->execute([$entryId, $reviewedBy, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function assignBatch(int $supplierId, int $id, string $batchId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions SET batch_id = ?
              WHERE id = ? AND supplier_id = ? AND status = "approved"'
        );
        $stmt->execute([$batchId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function findBatch(int $supplierId, string $batchId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ',
                    (SELECT je.reversed_by FROM journal_entries je
                      WHERE je.id = bank_posting_suggestions.journal_entry_id
                        AND je.supplier_id = bank_posting_suggestions.supplier_id) reversed_by
               FROM bank_posting_suggestions
              WHERE supplier_id = ? AND batch_id = ?
              ORDER BY id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $batchId]);
        return array_map(function (array $row): array {
            $cast = $this->cast($row);
            $cast['reversed_by'] = $row['reversed_by'] === null ? null : (int) $row['reversed_by'];
            return $cast;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function snooze(
        int $supplierId,
        int $id,
        ?string $until,
        ?string $reason,
        ?int $userId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions
                SET snoozed_until = ?, snooze_reason = ?, snoozed_by = ?
              WHERE id = ? AND supplier_id = ? AND status IN ("pending","needs_input","blocked")'
        );
        $stmt->execute([$until, $reason, $until === null ? null : $userId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function markRejected(int $supplierId, int $id, ?string $note, ?int $reviewedBy): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions
                SET status = "rejected", note = COALESCE(?, note), reviewed_by = ?, reviewed_at = NOW()
              WHERE id = ? AND supplier_id = ? AND status IN ("pending","needs_input","blocked")'
        );
        $stmt->execute([$note, $reviewedBy, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Přepíše důvod u nevyřízeného návrhu, aniž by se sáhlo na cokoli dalšího.
     *
     * Slouží periodickému přepočtu ({@see \MyInvoice\Service\Accounting\Bank\StaleSuggestionSweep}):
     * `createIfNoPending()` u existujícího pending návrhu vrátí jen jeho id a poznámku NEMĚNÍ,
     * takže by ve frontě zůstal důvod, který už neplatí („předpis chybí" u závazku, který
     * mezitím vznikl).
     */
    public function refreshNote(int $supplierId, int $id, ?string $note): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_posting_suggestions SET note = ?
              WHERE id = ? AND supplier_id = ? AND status IN ("pending","needs_input","blocked")
                AND NOT (note <=> ?)'
        );
        $stmt->execute([$note, $id, $supplierId, $note]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Nevyřízené návrhy, jejichž uložený důvod mohlo pozdější zaúčtování změnit
     * (předpis závazku chybí / nestačí). Vrací je napříč firmami s podvojným
     * účetnictvím, chronologicky.
     *
     * Vynechává vše, co by přepočet neměl oživit:
     *   - odložený (snooze) návrh — uživatel řekl „teď ne",
     *   - transakci s živým zápisem — přepočet by přepisoval hotové účetnictví,
     *   - transakci s odmítnutým návrhem nebo lidským zásahem v `accounting_corrections`
     *     (`reject`, `approve_override`, `manual_post`, `unpost`) — člověk už rozhodl.
     *
     * @param list<string> $notes
     * @return list<array{suggestion_id:int, supplier_id:int, tx_id:int, posted_at:string, note:?string}>
     */
    public function transientPendingCandidates(array $notes, ?int $supplierId = null): array
    {
        if ($notes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($notes), '?'));
        $sql = "SELECT s.id, s.supplier_id, s.bank_transaction_id, s.note, bt.posted_at
                  FROM bank_posting_suggestions s
                  JOIN supplier sup ON sup.id = s.supplier_id AND sup.accounting_mode = 'double_entry'
                  JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
                 WHERE s.status IN ('pending','needs_input','blocked')
                   AND s.note IN ({$in})
                   AND (s.snoozed_until IS NULL OR s.snoozed_until <= NOW())
                   AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                    WHERE je.supplier_id = s.supplier_id AND je.source_type = 'bank'
                                      AND je.source_id = s.bank_transaction_id AND je.reversed_by IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM bank_posting_suggestions rej
                                    WHERE rej.supplier_id = s.supplier_id
                                      AND rej.bank_transaction_id = s.bank_transaction_id
                                      AND rej.status = 'rejected')
                   AND NOT EXISTS (SELECT 1 FROM accounting_corrections c
                                    WHERE c.supplier_id = s.supplier_id
                                      AND c.entity_type = 'bank_transaction'
                                      AND c.entity_id = s.bank_transaction_id
                                      AND c.event_type IN ('reject','approve_override','manual_post','unpost'))";
        $params = $notes;
        if ($supplierId !== null) {
            $sql .= ' AND s.supplier_id = ?';
            $params[] = $supplierId;
        }
        $sql .= ' ORDER BY s.supplier_id, bt.posted_at, s.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'suggestion_id' => (int) $r['id'],
            'supplier_id'   => (int) $r['supplier_id'],
            'tx_id'         => (int) $r['bank_transaction_id'],
            'posted_at'     => (string) $r['posted_at'],
            'note'          => $r['note'] === null ? null : (string) $r['note'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Spárované pohyby, které nemají ANI zápis, ANI jakýkoli návrh.
     *
     * Tady končí úhrada dokladu, jehož předpis ještě nebyl v deníku:
     * {@see \MyInvoice\Service\Accounting\Bank\BankPostingService::matchedOutcome} vrátí
     * skip `document_not_posted` a NEZALOŽÍ návrh — důvod tedy nikde není uložený a podle
     * poznámky se takový pohyb dohledat nedá. Po doúčtování dokladu se ale sám od sebe
     * nikdo nezeptá znovu, proto je pro periodický přepočet potřeba tenhle druhý vstup.
     *
     * Scope je stejný jako u {@see unpostedWithoutSuggestion()} (vlastnictví výpisu přes
     * resolver NEBO explicitní vazba na doklad), navíc omezený na SPÁROVANÉ pohyby: jen
     * ty jdou v enginu větví `matchedOutcome`. Nespárované by spadly do `applyRules()`,
     * kde by se z fronty nic nespravilo a jen by se znovu volala AI.
     *
     * @return list<array{suggestion_id:null, supplier_id:int, tx_id:int, posted_at:string, note:null}>
     */
    public function matchedUnpostedWithoutSuggestion(int $supplierId): array
    {
        $scopeSql = "bt.source = 'statement'
            AND bt.match_status <> 'ignored'
            AND (
                bt.match_status IN ('auto_exact','auto_partial','manual')
                OR EXISTS (SELECT 1 FROM invoice_payments alloc_ip
                            WHERE alloc_ip.supplier_id = ? AND alloc_ip.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM payment_matches alloc_pm
                            WHERE alloc_pm.supplier_id = ? AND alloc_pm.bank_transaction_id = bt.id)
            )
            AND NOT EXISTS (
                SELECT 1 FROM journal_entries je
                 WHERE je.supplier_id = ? AND je.source_type = 'bank'
                   AND je.source_id = bt.id AND je.reversed_by IS NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM bank_posting_suggestions s2
                 WHERE s2.bank_transaction_id = bt.id
                   AND s2.status IN ('pending','needs_input','blocked','approved','auto_posted','rejected')
            )
            AND NOT EXISTS (
                SELECT 1 FROM accounting_corrections c
                 WHERE c.supplier_id = ? AND c.entity_type = 'bank_transaction' AND c.entity_id = bt.id
                   AND c.event_type IN ('reject','approve_override','manual_post','unpost')
            )
            AND (
                " . BankStatementOwnershipResolver::sql() . "
                OR EXISTS (SELECT 1 FROM invoice_payments ip
                            WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM payment_matches pm
                            WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM invoices i
                            WHERE i.supplier_id = ? AND i.id = bt.matched_invoice_id)
            )";
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.posted_at
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE {$scopeSql}
              ORDER BY bt.posted_at, bt.id"
        );
        $stmt->execute(array_merge(
            [$supplierId, $supplierId, $supplierId, $supplierId],
            BankStatementOwnershipResolver::params($supplierId),
            [$supplierId, $supplierId, $supplierId],
        ));
        return array_map(static fn (array $r): array => [
            'suggestion_id' => null,
            'supplier_id'   => $supplierId,
            'tx_id'         => (int) $r['id'],
            'posted_at'     => (string) $r['posted_at'],
            'note'          => null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countPending(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_posting_suggestions WHERE supplier_id = ? AND status = "pending"'
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{pending:int,needs_input:int} */
    public function queueCounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, COUNT(*) AS total FROM bank_posting_suggestions
              WHERE supplier_id = ? AND status IN ("pending","needs_input","blocked") GROUP BY status'
        );
        $stmt->execute([$supplierId]);
        $out = ['pending' => 0, 'needs_input' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string) $row['status'] === 'pending') {
                $out['pending'] += (int) $row['total'];
            } else {
                $out['needs_input'] += (int) $row['total'];
            }
        }
        return $out;
    }

    public function scheduleIdForTx(int $supplierId, int $txId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tax_advance_schedule_id FROM bank_posting_suggestions
              WHERE supplier_id = ? AND bank_transaction_id = ? AND tax_advance_schedule_id IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Stránkovaný seznam s JOINy (transakce, výpis, pravidlo) a živě počítaným
     * period_closed (JOIN accounting_periods — L5). Vrací obohacené řádky.
     *
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(int $supplierId, string $status, ?string $account, int $limit, int $offset): array
    {
        $where = ['s.supplier_id = ?'];
        $params = [$supplierId];
        if ($status === 'needs_input') {
            $where[] = 's.status IN ("needs_input","blocked")';
        } else {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        if ($account !== null && $account !== '') {
            $where[] = 'bs.account_number = ?';
            $params[] = $account;
        }
        $whereSql = implode(' AND ', $where);

        $pdo = $this->db->pdo();
        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM bank_posting_suggestions s
               JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT s.id, s.source, s.rule_id, s.debit_account_code, s.credit_account_code,
                       s.amount, s.status, s.note, s.journal_entry_id, s.confidence, s.detector,
                       s.operation_type, s.tax_advance_schedule_id, s.ai_reasoning, s.ai_model,
                       s.ai_provider, s.ai_prompt_version, s.created_at,
                       bt.id AS tx_id, bt.posted_at AS tx_posted_at, bt.amount AS tx_amount, bt.currency AS tx_currency,
                       bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name, bt.description AS tx_description,
                       bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                       bt.statement_id, bs.currency AS statement_currency,
                       r.name AS rule_name,
                       c.created_at AS correction_created_at,
                       c.suggested_debit AS correction_suggested_debit,
                       c.suggested_credit AS correction_suggested_credit,
                       c.final_debit AS correction_final_debit,
                       c.final_credit AS correction_final_credit,
                       je.document_no,
                       EXISTS(SELECT 1 FROM accounting_periods p
                               WHERE p.supplier_id = s.supplier_id
                                 AND bt.posted_at BETWEEN p.starts_on AND p.ends_on
                                 AND p.status <> 'open') AS period_closed
                  FROM bank_posting_suggestions s
                  JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
                  JOIN bank_statements   bs ON bs.id = bt.statement_id
                  LEFT JOIN bank_posting_rules r ON r.id = s.rule_id
                  LEFT JOIN accounting_corrections c ON c.id = (
                    SELECT c2.id FROM accounting_corrections c2
                     WHERE s.note LIKE 'corrected_from:#%'
                       AND c2.supplier_id = s.supplier_id
                       AND c2.entity_type = 'bank_transaction'
                       AND c2.entity_id = CAST(SUBSTRING_INDEX(s.note, '#', -1) AS UNSIGNED)
                       AND c2.event_type IN ('approve_override','manual_post')
                     ORDER BY c2.created_at DESC, c2.id DESC LIMIT 1
                  )
                  LEFT JOIN journal_entries je ON je.id = s.journal_entry_id
                 WHERE {$whereSql}
                 ORDER BY bt.posted_at DESC, s.id DESC
                 LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map(function (array $r): array {
            $txCurrency = $r['tx_currency'] ?? $r['statement_currency'] ?? 'CZK';
            return [
                'id'          => (int) $r['id'],
                'source'      => (string) $r['source'],
                'rule_id'     => $r['rule_id'] === null ? null : (int) $r['rule_id'],
                'rule_name'   => $r['rule_name'] === null ? null : (string) $r['rule_name'],
                'transaction' => [
                    'id'                   => (int) $r['tx_id'],
                    'posted_at'            => (string) $r['tx_posted_at'],
                    'amount'               => (float) $r['tx_amount'],
                    'currency'             => (string) $txCurrency,
                    'counterparty_account' => $r['counterparty_account'] === null ? null : (string) $r['counterparty_account'],
                    'counterparty_bank'    => $r['counterparty_bank'] === null ? null : (string) $r['counterparty_bank'],
                    'counterparty_name'    => $r['counterparty_name'] === null ? null : (string) $r['counterparty_name'],
                    'description'          => $r['tx_description'] === null ? null : (string) $r['tx_description'],
                    'variable_symbol'      => $r['variable_symbol'] === null ? null : (string) $r['variable_symbol'],
                    'constant_symbol'      => $r['constant_symbol'] === null ? null : (string) $r['constant_symbol'],
                    'specific_symbol'      => $r['specific_symbol'] === null ? null : (string) $r['specific_symbol'],
                    'statement_id'         => (int) $r['statement_id'],
                ],
                'debit_account_code'  => (string) $r['debit_account_code'],
                'credit_account_code' => (string) $r['credit_account_code'],
                'amount'              => (float) $r['amount'],
                'status'              => (string) $r['status'],
                'note'                => $r['note'] === null ? null : (string) $r['note'],
                'confidence'          => $r['confidence'] === null ? null : (float) $r['confidence'],
                'detector'            => $r['detector'] === null ? null : (string) $r['detector'],
                'operation_type'      => $r['operation_type'] === null ? null : (string) $r['operation_type'],
                'tax_advance_schedule_id' => $r['tax_advance_schedule_id'] === null ? null : (int) $r['tax_advance_schedule_id'],
                'ai_reasoning'         => $r['ai_reasoning'] === null ? null : (string) $r['ai_reasoning'],
                'ai_model'             => $r['ai_model'] === null ? null : (string) $r['ai_model'],
                'ai_provider'          => $r['ai_provider'] === null ? null : (string) $r['ai_provider'],
                'ai_prompt_version'    => $r['ai_prompt_version'] === null ? null : (string) $r['ai_prompt_version'],
                'journal_entry_id'    => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'],
                'document_no'         => $r['document_no'] === null ? null : (string) $r['document_no'],
                'period_closed'       => (bool) $r['period_closed'],
                'correction'          => $r['correction_created_at'] === null ? null : [
                    'created_at' => (string) $r['correction_created_at'],
                    'suggested_debit' => $r['correction_suggested_debit'] === null ? null : (string) $r['correction_suggested_debit'],
                    'suggested_credit' => $r['correction_suggested_credit'] === null ? null : (string) $r['correction_suggested_credit'],
                    'final_debit' => $r['correction_final_debit'] === null ? null : (string) $r['correction_final_debit'],
                    'final_credit' => $r['correction_final_credit'] === null ? null : (string) $r['correction_final_credit'],
                ],
                'created_at'          => (string) $r['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Všechny skutečné bankovní pohyby bez aktivního zápisu, včetně těch, pro které
     * nevznikl návrh kontace. Ignorované pohyby a provizorní e-mailová avíza se
     * neúčtují, proto do fronty nepatří.
     *
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    /**
     * @param array{year?:?int, q?:?string, scope?:string, account?:?string} $filters
     *   year    — kalendářní rok pohybu (NULL = všechny),
     *   q       — fulltext přes protistranu, VS, popis a číslo účtu,
     *   scope   — 'unposted' (výchozí, jen nezaúčtované) | 'all' (všechny pohyby na všech účtech).
     *   account — náš zdrojový účet (bs.account_number, normalizováno stejně jako u BankStatementAction::list).
     */
    public function paginateUnposted(int $supplierId, int $limit, int $offset, array $filters = []): array
    {
        $unpostedOnly = ($filters['scope'] ?? 'unposted') !== 'all';
        $scopeSql = "bt.source = 'statement'
            AND bt.match_status <> 'ignored'"
            . ($unpostedOnly ? "
            AND NOT EXISTS (
                SELECT 1 FROM journal_entries je
                 WHERE je.supplier_id = ? AND je.source_type = 'bank'
                   AND je.source_id = bt.id AND je.reversed_by IS NULL
            )" : '') . "
            AND (
                " . BankStatementOwnershipResolver::sql() . "
                OR EXISTS (SELECT 1 FROM invoice_payments ip
                            WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM payment_matches pm
                            WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM invoices i
                            WHERE i.supplier_id = ? AND i.id = bt.matched_invoice_id)
            )";
        // SEC-01: vlastnictví výpisu rozhoduje bs.supplier_id (legacy NULL jen při
        // jednoznačném vlastníkovi účtu), ne pouhá shoda čísla účtu.
        $scopeParams = $unpostedOnly ? [$supplierId] : [];
        array_push($scopeParams, ...BankStatementOwnershipResolver::params($supplierId));
        array_push($scopeParams, $supplierId, $supplierId, $supplierId);

        $year = isset($filters['year']) && (int) $filters['year'] > 0 ? (int) $filters['year'] : null;
        if ($year !== null) {
            $scopeSql .= ' AND YEAR(bt.posted_at) = ?';
            $scopeParams[] = $year;
        }
        $account = isset($filters['account']) ? trim((string) $filters['account']) : '';
        if ($account !== '') {
            $scopeSql .= " AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                              = TRIM(LEADING '0' FROM REGEXP_REPLACE(?, '[^0-9]', ''))";
            $scopeParams[] = $account;
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            // Escape wildcardů, ať uživatelský vstup nedělá slow-query ani nečekanou shodu.
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $scopeSql .= " AND (bt.counterparty_name LIKE ? OR bt.description LIKE ?
                             OR bt.counterparty_account LIKE ? OR bt.variable_symbol LIKE ?"
                . ($digits !== '' ? " OR REPLACE(CAST(ABS(bt.amount) AS CHAR), '.00', '') = ?" : '') . ')';
            array_push($scopeParams, $like, $like, $like, $like);
            if ($digits !== '') {
                $scopeParams[] = $digits;
            }
        }
        $pdo = $this->db->pdo();

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE {$scopeSql}"
        );
        $countStmt->execute($scopeParams);
        $total = (int) $countStmt->fetchColumn();

        // account_number/bank_code/account_label = NÁŠ zdrojový účet výpisu (bs.*), stejný
        // vzor jako BankStatementAction::detail/list — bank_code autoritativně z currencies
        // (GPC výpisy ho neukládají), label z konfigurovaného účtu dodavatele.
        // matched_* sloupce (JOIN invoices/clients/payment_matches/purchase_invoices) — stejné
        // JOINy jako BankStatementAction::detail(), ať „Všechny pohyby" umí zobrazit spárovanou
        // fakturu/dodavatele stejně jako detail výpisu (#52).
        $stmt = $pdo->prepare(
            "SELECT bt.id, bt.source AS transaction_source, bt.statement_id, bt.posted_at, bt.amount,
                    COALESCE(bt.currency, bs.currency, 'CZK') AS currency,
                    bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                    bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                    bt.description, bt.bank_ref, bt.match_status, bt.matched_invoice_id, bt.matched_at,
                    bs.account_number,
                    COALESCE(
                      (SELECT cur.bank_code FROM currencies cur
                        WHERE cur.supplier_id = ?
                          AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                            = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                          AND cur.bank_code IS NOT NULL AND cur.bank_code <> ''
                        LIMIT 1),
                      bs.bank_code
                    ) AS bank_code,
                    (SELECT cur.label FROM currencies cur
                      WHERE cur.supplier_id = ?
                        AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                          = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                        AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                      LIMIT 1) AS account_label,
                    (
                        NOT EXISTS (
                            SELECT 1 FROM accounting_periods ap
                             WHERE ap.supplier_id = ?
                               AND bt.posted_at BETWEEN ap.starts_on AND ap.ends_on
                               AND ap.status = 'open'
                        )
                        OR EXISTS (
                            SELECT 1 FROM accounting_supplier_settings aset
                             WHERE aset.supplier_id = ? AND aset.locked_until IS NOT NULL
                               AND bt.posted_at <= aset.locked_until
                        )
                    ) AS period_closed,
                    i.varsymbol AS matched_varsymbol, i.amount_to_pay AS matched_invoice_amount,
                    c.company_name AS matched_client_name,
                    pm.purchase_invoice_id AS matched_purchase_invoice_id,
                    COALESCE(NULLIF(p.vendor_invoice_number, ''), p.varsymbol) AS matched_purchase_ref,
                    vc.company_name AS matched_vendor_name
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
          LEFT JOIN invoices i ON i.id = bt.matched_invoice_id
          LEFT JOIN clients c ON c.id = i.client_id
          LEFT JOIN (SELECT bank_transaction_id, MIN(id) AS min_id
                       FROM payment_matches GROUP BY bank_transaction_id) pmx
                 ON pmx.bank_transaction_id = bt.id
          LEFT JOIN payment_matches pm ON pm.id = pmx.min_id
          LEFT JOIN purchase_invoices p ON p.id = pm.purchase_invoice_id
          LEFT JOIN clients vc ON vc.id = p.vendor_id
              WHERE {$scopeSql}
              ORDER BY bt.posted_at DESC, bt.id DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute(array_merge(
            [$supplierId, $supplierId, $supplierId, $supplierId],
            $scopeParams,
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sloučená úhrada (split): jedna transakce může mít platby na VÍCE vystavených
        // faktur (migrace 0119) — stejný dopočet jako BankStatementAction::detail().
        $matchedByTx = [];
        $txIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($txIds !== []) {
            $ph = implode(',', array_fill(0, count($txIds), '?'));
            $mp = $pdo->prepare(
                "SELECT p.bank_transaction_id AS tx_id, p.invoice_id, p.amount,
                        i.varsymbol, i.invoice_type, c.company_name AS client_name
                   FROM invoice_payments p
                   JOIN invoices i ON i.id = p.invoice_id
              LEFT JOIN clients c ON c.id = i.client_id
                  WHERE p.bank_transaction_id IN ({$ph})
               ORDER BY p.id"
            );
            $mp->execute($txIds);
            foreach ($mp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $matchedByTx[(int) $r['tx_id']][] = [
                    'invoice_id'   => (int) $r['invoice_id'],
                    'varsymbol'    => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
                    'invoice_type' => (string) $r['invoice_type'],
                    'amount'       => (float) $r['amount'],
                    'client_name'  => $r['client_name'] !== null ? (string) $r['client_name'] : null,
                ];
            }
        }

        $items = array_map(static function (array $row) use ($matchedByTx): array {
            return [
                'id' => (int) $row['id'],
                'source' => (string) $row['transaction_source'],
                'statement_id' => (int) $row['statement_id'],
                'posted_at' => (string) $row['posted_at'],
                'amount' => (float) $row['amount'],
                'currency' => (string) $row['currency'],
                'variable_symbol' => $row['variable_symbol'] === null ? null : (string) $row['variable_symbol'],
                'constant_symbol' => $row['constant_symbol'] === null ? null : (string) $row['constant_symbol'],
                'specific_symbol' => $row['specific_symbol'] === null ? null : (string) $row['specific_symbol'],
                'counterparty_account' => $row['counterparty_account'] === null ? null : (string) $row['counterparty_account'],
                'counterparty_bank' => $row['counterparty_bank'] === null ? null : (string) $row['counterparty_bank'],
                'counterparty_name' => $row['counterparty_name'] === null ? null : (string) $row['counterparty_name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'bank_ref' => $row['bank_ref'] === null ? null : (string) $row['bank_ref'],
                'matched_invoice_id' => $row['matched_invoice_id'] === null ? null : (int) $row['matched_invoice_id'],
                'matched_varsymbol' => $row['matched_varsymbol'] === null ? null : (string) $row['matched_varsymbol'],
                'matched_invoice_amount' => $row['matched_invoice_amount'] === null ? null : (float) $row['matched_invoice_amount'],
                'matched_client_name' => $row['matched_client_name'] === null ? null : (string) $row['matched_client_name'],
                'matched_purchase_invoice_id' => $row['matched_purchase_invoice_id'] === null ? null : (int) $row['matched_purchase_invoice_id'],
                'matched_purchase_ref' => $row['matched_purchase_ref'] === null ? null : (string) $row['matched_purchase_ref'],
                'matched_vendor_name' => $row['matched_vendor_name'] === null ? null : (string) $row['matched_vendor_name'],
                'matched_invoices' => $matchedByTx[(int) $row['id']] ?? [],
                'match_status' => (string) $row['match_status'],
                'matched_at' => $row['matched_at'] === null ? null : (string) $row['matched_at'],
                'account_number' => (string) $row['account_number'],
                'bank_code' => $row['bank_code'] === null ? null : (string) $row['bank_code'],
                'account_label' => $row['account_label'] === null ? null : (string) $row['account_label'],
                // Doplní BankPostingSuggestionAction přes BankPostingService::transactionPostingInfo
                // (stejná logika jako StatementDetail — 'posted' i 'suggested', ne jen suggestion).
                'posting' => null,
                'period_closed' => (bool) $row['period_closed'],
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }

    public function unpostedCount(int $supplierId): int
    {
        return $this->paginateUnposted($supplierId, 1, 0)['total'];
    }

    /**
     * Roky, ve kterých firma má bankovní pohyby — pro nabídku filtru (ať UI nenabízí prázdné roky).
     *
     * @return list<int>
     */
    public function transactionYears(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT YEAR(bt.posted_at) y
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.supplier_id = ? AND bt.source = 'statement' AND bt.posted_at IS NOT NULL
              ORDER BY y DESC"
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Naše účty pro filtr „Náš účet" v záložce Všechny pohyby — stejný vzor jako
     * BankStatementAction::list() (účty z currencies, ne surové account_number
     * z výpisů — tím máme každý účet 1×, autoritativní bank_code a pořadí jako v adminu).
     *
     * @return list<array{account_number:string, bank_code:?string, label:?string}>
     */
    public function transactionAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT cur.account_number, cur.bank_code, cur.label
               FROM currencies cur
              WHERE cur.supplier_id = ?
                AND cur.account_number IS NOT NULL AND cur.account_number <> ''
                AND EXISTS (
                    SELECT 1 FROM bank_statements bs
                     WHERE TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(cur.account_number, '[^0-9]', ''))
                       AND " . BankStatementOwnershipResolver::sql() . "
                )
              ORDER BY cur.code, cur.is_default DESC, cur.label"
        );
        // SEC-01: účet do filtru jen tehdy, když k němu supplier reálně vidí výpis.
        $stmt->execute(array_merge([$supplierId], BankStatementOwnershipResolver::params($supplierId)));
        return array_map(static fn (array $a): array => [
            'account_number' => (string) $a['account_number'],
            'bank_code'      => $a['bank_code'] !== null ? (string) $a['bank_code'] : null,
            'label'          => $a['label'] !== null ? (string) $a['label'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Reálné bankovní pohyby, pro které automatika NEVYTVOŘILA vůbec žádný návrh
     * (skip „bez suggestion" — {@see \MyInvoice\Service\Accounting\Bank\BankPostingService}
     * reasons `no_rule`/`fx_not_supported`) ani nemají žádný aktivní/uzavřený zápis.
     * Featura H — dosud jediné neviditelné pohyby fronty ručního doúčtování.
     *
     * Stejný scope jako {@see paginateUnposted()} (tracked účet NEBO vazba na fakturu/platbu),
     * protože bank_statements.supplier_id je jen best-effort dopočet (E8) — spoléhat se na
     * něj přímo by mohlo vrátit cizí řádky nebo naopak nespravedlivě vynechat vlastní.
     *
     * @return list<array<string,mixed>>
     */
    public function unpostedWithoutSuggestion(int $supplierId): array
    {
        $scopeSql = "bt.source = 'statement'
            AND bt.match_status <> 'ignored'
            AND NOT EXISTS (
                SELECT 1 FROM journal_entries je
                 WHERE je.supplier_id = ? AND je.source_type = 'bank'
                   AND je.source_id = bt.id AND je.reversed_by IS NULL
            )
            AND NOT EXISTS (
                SELECT 1 FROM bank_posting_suggestions s2
                 WHERE s2.bank_transaction_id = bt.id
                   AND s2.status IN ('pending','needs_input','blocked','approved','auto_posted')
            )
            AND (
                " . BankStatementOwnershipResolver::sql() . "
                OR EXISTS (SELECT 1 FROM invoice_payments ip
                            WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM payment_matches pm
                            WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id)
                OR EXISTS (SELECT 1 FROM invoices i
                            WHERE i.supplier_id = ? AND i.id = bt.matched_invoice_id)
            )";
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount,
                    COALESCE(bt.currency, bs.currency, 'CZK') AS currency,
                    bt.counterparty_name, bt.counterparty_account, bt.variable_symbol, bt.description
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE {$scopeSql}
              ORDER BY bt.posted_at DESC, bt.id DESC"
        );
        // 1× journal_entries + PARAM_COUNT resolveru + 3× vazba na fakturu/platbu.
        $stmt->execute(array_merge(
            [$supplierId],
            BankStatementOwnershipResolver::params($supplierId),
            [$supplierId, $supplierId, $supplierId],
        ));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'statement_id' => (int) $r['statement_id'],
            'posted_at' => (string) $r['posted_at'],
            'amount' => (float) $r['amount'],
            'currency' => (string) $r['currency'],
            'counterparty_name' => $r['counterparty_name'] === null ? null : (string) $r['counterparty_name'],
            'counterparty_account' => $r['counterparty_account'] === null ? null : (string) $r['counterparty_account'],
            'variable_symbol' => $r['variable_symbol'] === null ? null : (string) $r['variable_symbol'],
            'description' => $r['description'] === null ? null : (string) $r['description'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['bank_transaction_id'] = (int) $r['bank_transaction_id'];
        $r['rule_id'] = $r['rule_id'] === null ? null : (int) $r['rule_id'];
        $r['amount'] = (float) $r['amount'];
        $r['journal_entry_id'] = $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'];
        $r['reviewed_by'] = $r['reviewed_by'] === null ? null : (int) $r['reviewed_by'];
        $r['confidence'] = $r['confidence'] === null ? null : (float) $r['confidence'];
        $r['tax_advance_schedule_id'] = $r['tax_advance_schedule_id'] === null ? null : (int) $r['tax_advance_schedule_id'];
        $r['snoozed_by'] = $r['snoozed_by'] === null ? null : (int) $r['snoozed_by'];
        return $r;
    }
}
