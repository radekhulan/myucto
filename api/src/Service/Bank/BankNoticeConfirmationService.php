<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\Accounting\GoPay\GoPayService;
use PDO;

/** Potvrzení avíz je oddělené od párování dokladů. Neúplné převzetí vždy zůstává ke kontrole. */
final class BankNoticeConfirmationService
{
    private const LINKS = [
        ['gopay_clearings', 'bank_transaction_id'],
        ['payroll_payment_matches', 'bank_transaction_id'],
        ['tax_advance_schedules', 'matched_transaction_id'],
        ['bank_transfer_matches', 'in_transaction_id'],
        ['bank_transfer_matches', 'out_transaction_id'],
        ['purchase_invoice_submissions', 'bank_transaction_id'],
        ['document_requests', 'bank_transaction_id'],
        ['fuelings', 'source_bank_transaction_id'],
        ['de_movement_classification', 'bank_transaction_id'],
        ['other_item_allocations', 'bank_transaction_id'],
        ['payroll_payment_settlement_signals', 'bank_transaction_id'],
    ];

    public function __construct(private readonly Connection $db, private readonly ?GoPayService $gopay = null) {}

    /** Sdílený registr blokujících vazeb pro detail i dávkové seznamy. */
    public static function blockingLinks(): array
    {
        return self::LINKS;
    }

    /** Stejné vazby používá dávkový souhrn i aktuální kontrola před DELETE. */
    public static function deletionDependencies(string $transactionId = '?'): array
    {
        $conditions = [];
        foreach ([...self::LINKS, ['invoice_payments', 'bank_transaction_id'], ['payment_matches', 'bank_transaction_id'], ['gopay_clearings', 'payout_match_transaction_id'], ['client_bank_accounts', 'last_bank_transaction_id'], ['bank_counterparty_observations', 'bank_transaction_id']] as [$table, $column]) {
            $conditions[$table][] = "{$column}={$transactionId}";
        }
        foreach (['journal_entries' => "source_type IN ('bank','card_settlement','card_writeoff') AND source_id={$transactionId}", 'cash_journal_notes' => "source_type='bank' AND source_id={$transactionId} AND deleted_at IS NULL", 'de_movement_classification_history' => "source_type='bank' AND source_id={$transactionId}", 'document_links' => "entity_type='bank_transaction' AND entity_id={$transactionId}", 'document_dimensions' => "doc_type='bank_transaction' AND doc_id={$transactionId}", 'document_dimension_splits' => "doc_type='bank_transaction' AND doc_id={$transactionId}"] as $table => $where) $conditions[$table][] = $where;
        $conditions['bank_transactions'][] = "id={$transactionId} AND (match_status IN ('auto_exact','auto_partial','manual') OR matched_invoice_id IS NOT NULL)";
        return $conditions;
    }

    private function deletionBlockers(int $noticeId): array
    {
        $blockers = [];
        foreach (self::deletionDependencies() as $table => $conditions) {
            $where = implode(' OR ', array_map(static fn (string $condition): string => '(' . $condition . ')', $conditions));
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1" . $this->lockingRead());
            $stmt->execute(array_fill(0, count($conditions), $noticeId));
            if ($stmt->fetchColumn() !== false) $blockers[] = 'dependency_' . $table;
        }
        return $blockers;
    }

    /** Volat před otevřením transakce; stejný zámek drží import autoritativního výpisu. */
    public function lockSupplier(int $supplierId): string
    {
        if ($supplierId <= 0) throw new BankNoticeConfirmationException('unknown_supplier', 'Chybí firma bankovního pohybu.');
        $name = NamedLockName::for($this->db, 'bank-import', (string) $supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() !== 1) throw new BankNoticeConfirmationException('bank_busy', 'Probíhá jiná změna bankovních pohybů. Opakujte akci později.');
        return $name;
    }

    public function unlockSupplier(string $name): void
    {
        $this->db->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }

    /** @return array<string,mixed> */
    public function inspectNotice(int $supplierId, int $noticeId): array
    {
        $assessment = $this->evaluateNotice($supplierId, $noticeId);
        $record = $this->record($supplierId, $noticeId);
        // GET zobrazuje uložený životní cyklus stejně jako SQL filtry. Rekonstrukce
        // starších dat probíhá výslovnou kontrolou; náhled má vlastní proposed_*.
        if ($record === null) {
            $assessment['confirmation_state'] = 'pending';
            $assessment['confirmation_reason'] = 'not_checked';
        } elseif ($record['state'] !== 'confirmed') {
            $assessment['confirmation_state'] = $record['state'];
            $assessment['confirmation_reason'] = $record['reason'];
        } elseif ($record['authoritative_transaction_id'] !== null) {
            $assessment['confirmation_state'] = 'confirmed';
        }
        return $assessment;
    }

    /** Posouzení aktuálních dat; samo nikdy nezapisuje ani nepotvrzuje převzetí. */
    private function evaluateNotice(int $supplierId, int $noticeId): array
    {
        $notice = $this->notice($supplierId, $noticeId);
        if ($notice === null) throw new BankNoticeConfirmationException('notice_not_found', 'Avízo nebylo nalezeno.', 404);
        $record = $this->record($supplierId, $noticeId);
        if (($record['state'] ?? null) === 'confirmed') {
            $target = $this->transaction($supplierId, (int) $record['authoritative_transaction_id']);
            // Datum ani částka nesmí zneplatnit auditované ruční potvrzení nebo
            // převzetí karetní blokace, které již prošlo pravidly reconcileru.
            if ($target !== null && $this->isAuthoritative($target) && $this->sameAccount($notice, $target)
                && $this->sameCurrency($notice, $target)
                && (abs((float) $notice['amount'] - (float) $target['amount']) <= 0.005 || in_array($record['method'], ['manual','card_fx'], true))) {
                $blockers = $this->sourceBlockers($notice, $target);
                if ($blockers === []) return $this->inspection('confirmed', null, $target, [], []);
                return $this->inspection('review', $blockers[0], $target, [$target], $blockers);
            }
            return $this->inspection('review', 'authoritative_removed', null, [], ['authoritative_removed']);
        }
        if (($record['reason'] ?? '') === 'authoritative_removed') {
            return $this->inspection('review', 'authoritative_removed', null, $this->candidates($notice), ['authoritative_removed']);
        }
        $candidates = $this->candidates($notice);
        if ($candidates === []) return $this->inspection('pending', 'no_candidate', null, [], []);
        $reconciler = new EmailNoticeReconciler($this->db, $this->gopay);
        $hasEvidence = $this->hasPayments($noticeId) || in_array($notice['match_status'], ['auto_exact','auto_partial','manual'], true);
        $exact = array_values(array_filter($candidates, static fn (array $r): bool => abs((float) $notice['amount'] - (float) $r['amount']) <= 0.005));
        $eligible = [];
        foreach ($candidates as $candidate) {
            $selected = $hasEvidence ? $reconciler->findTakeoverCandidate((int) $candidate['id']) : null;
            if (($hasEvidence && (int) ($selected['id'] ?? 0) === $noticeId)
                || (!$hasEvidence && $reconciler->findSupersededNotice((int) $candidate['id']) === $noticeId)) $eligible[] = $candidate;
        }
        // Stejnou preferenci přesné částky používá i původní takeover reconcileru.
        $eligibleExact = array_values(array_filter($eligible, static fn (array $r): bool => abs((float) $notice['amount'] - (float) $r['amount']) <= 0.005));
        $pool = $eligibleExact !== [] ? $eligibleExact : $eligible;
        if (count($pool) > 1 || count($exact) > 1) return $this->inspection('review', 'ambiguous_candidates', null, $candidates, ['ambiguous_candidates']);
        $target = $pool[0] ?? $exact[0] ?? null;
        if ($target === null) return $this->inspection('review', 'amount_difference', null, $candidates, ['amount_difference']);
        $blockers = $this->sourceBlockers($notice, $target);
        $reserved = $this->db->pdo()->prepare('SELECT id FROM bank_notice_confirmations WHERE authoritative_transaction_id = ? AND (notice_transaction_id IS NULL OR notice_transaction_id <> ?)' . $this->lockingRead());
        $reserved->execute([(int) $target['id'], $noticeId]);
        if ($reserved->fetchColumn() !== false) $blockers[] = 'target_reserved';
        if ($pool === []) {
            $covered = $reconciler->coveredPeriod((int) $target['statement_id']);
            if (!$hasEvidence && ($notice['posted_at'] < ($covered['covered_from'] ?? '') || $notice['posted_at'] > ($covered['covered_to'] ?? ''))) {
                $blockers[] = 'notice_outside_statement_period';
            } else {
                $blockers[] = 'ambiguous_notices';
            }
        }
        if ($blockers !== []) return $this->inspection('review', $blockers[0], null, $candidates, array_values(array_unique($blockers)));
        return $this->inspection('pending', 'ready', $target, $candidates, []);
    }

    /** @return array<string,mixed> */
    public function inspectStatement(int $supplierId, int $statementId): array
    {
        $this->assertNoticeStatement($supplierId, $statementId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_transactions WHERE statement_id=? ORDER BY id');
        $stmt->execute([$statementId]);
        $summary = ['pending' => 0, 'review' => 0, 'confirmed' => 0, 'total' => 0];
        $counts = [];
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $item = $this->inspectNotice($supplierId, (int) $id);
            $item += $this->displayTransaction($this->notice($supplierId, (int) $id));
            $item['id'] = (int) $id;
            $item['transaction_id'] = (int) $id;
            $items[] = $item;
            $summary[$item['confirmation_state']]++;
            $summary['total']++;
            foreach ($this->deletionBlockers((int) $id) as $code) $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        $blockers = [];
        foreach ($counts as $code => $count) $blockers[] = ['code' => $code, 'count' => $count];
        return ['confirmation_summary' => $summary, 'can_delete_notices' => $blockers === [], 'deletion_blockers' => $blockers, 'items' => $items];
    }

    /** Uložený souhrn a kandidáti jen pro zobrazenou stránku; živá kontrola patří do změnových akcí. */
    public function statementDetail(int $supplierId, int $statementId, array $transactionIds): array
    {
        $this->assertNoticeStatement($supplierId, $statementId);
        $reader = new BankNoticeConfirmationReader($this->db);
        $result = $reader->statements($supplierId, [$statementId])[$statementId];
        $ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn (int $id): bool => $id > 0)));
        $result['items'] = [];
        if ($ids === []) return $result;
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_transactions WHERE statement_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $stmt->execute([$statementId, ...$ids]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $states = $reader->notices($supplierId, array_column($rows, 'id'));
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result['items'][] = ($states[$id] ?? []) + $this->displayTransaction($row) + ['transaction_id' => $id, 'blockers' => []];
        }
        return $result;
    }

    private function assertNoticeStatement(int $supplierId, int $statementId): void
    {
        $stmt = $this->db->pdo()->prepare("SELECT bs.id FROM bank_statements bs WHERE bs.id=? AND bs.source='email_notice' AND " . BankStatementOwnershipResolver::sql() . $this->lockingRead());
        $stmt->execute([$statementId, ...BankStatementOwnershipResolver::params($supplierId)]);
        if ($stmt->fetchColumn() === false) throw new BankNoticeConfirmationException('notice_statement_not_found', 'Přehled avíz nebyl nalezen.', 404);
    }

    /** Dávkovaná rekonstrukce je opakovatelná a náhled nic nepřevádí. */
    public function reconcileStatement(int $supplierId, int $statementId, ?int $userId = null, bool $dryRun = false, int $limit = 100, int $afterId = 0): array
    {
        return $this->atomic($supplierId, function () use ($supplierId, $statementId, $userId, $dryRun, $limit, $afterId): array {
            $this->assertNoticeStatement($supplierId, $statementId);
            $limit = max(1, min(500, $limit));
            $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_transactions WHERE statement_id=? AND id>? ORDER BY id LIMIT ' . ($limit + 1));
            $stmt->execute([$statementId, $afterId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $hasMore = count($ids) > $limit;
            $ids = array_slice($ids, 0, $limit);
            $assessments = [];
            $proposedSummary = ['pending' => 0, 'review' => 0, 'confirmed' => 0, 'total' => 0];
            foreach ($ids as $id) {
                $assessment = $dryRun ? $this->evaluateNotice($supplierId, $id) : $this->reconcileNoticeLocked($supplierId, $id, $userId);
                $assessments[$id] = $assessment;
                if ($dryRun) {
                    $state = $assessment['confirmation_reason'] === 'ready' ? 'confirmed' : $assessment['confirmation_state'];
                    $assessments[$id] += ['proposed_state' => $state, 'proposed_reason' => $assessment['confirmation_reason']];
                    $proposedSummary[$state]++;
                    $proposedSummary['total']++;
                }
            }
            $result = $this->statementDetail($supplierId, $statementId, $ids) + ['processed' => count($ids), 'next_after_id' => $ids === [] ? $afterId : end($ids), 'has_more' => $hasMore, 'dry_run' => $dryRun];
            // Stav filtru zůstává uložený; živé posouzení a návrh jsou omezeny na tuto dávku.
            foreach ($result['items'] as &$item) {
                $assessment = $assessments[$item['id']];
                foreach (['candidates', 'blockers', 'proposed_state', 'proposed_reason'] as $key) {
                    if (array_key_exists($key, $assessment)) $item[$key] = $assessment[$key];
                }
            }
            unset($item);
            if ($dryRun) $result['proposed_summary'] = $proposedSummary;
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function reconcileNotice(int $supplierId, int $noticeId, ?int $userId = null): array
    {
        return $this->atomic($supplierId, fn (): array => $this->reconcileNoticeLocked($supplierId, $noticeId, $userId));
    }

    /** Vyhodnotí všechny avíza v okolí nového pohybu ještě před párováním faktur. */
    public function reconcileAuthoritative(int $supplierId, int $transactionId): array
    {
        return $this->atomic($supplierId, function () use ($supplierId, $transactionId): array {
            $target = $this->transaction($supplierId, $transactionId);
            if ($target === null || !$this->isAuthoritative($target)) return [];
            $stmt = $this->db->pdo()->prepare("SELECT bt.*,bs.supplier_id,bs.account_number AS stmt_account,bs.bank_code AS stmt_bank,bs.currency AS stmt_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id LEFT JOIN bank_notice_confirmations c ON c.notice_transaction_id=bt.id WHERE " . BankStatementOwnershipResolver::sql() . " AND bs.source='email_notice' AND bt.source='email_notice' AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) AND (c.state IS NULL OR c.state<>'confirmed' OR c.authoritative_transaction_id IS NULL OR c.authoritative_transaction_id=?) ORDER BY bt.id" . $this->lockingRead());
            $stmt->execute([...BankStatementOwnershipResolver::params($supplierId), $target['posted_at'], $target['posted_at'], $transactionId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $notice) {
                if (!$this->sameAccount($notice, $target) || !$this->sameCurrency($notice, $target) || !$this->sameIdentity($notice, $target)
                    || ((float) $notice['amount'] <=> 0) !== ((float) $target['amount'] <=> 0)) continue;
                $id = (int) $notice['id'];
                $out[$id] = $this->reconcileNoticeLocked($supplierId, $id, null);
            }
            return $out;
        });
    }

    public function confirmManually(int $supplierId, int $noticeId, int $authoritativeId, int $userId, string $reason, ?callable $onConfirmed = null): array
    {
        if (trim($reason) === '') throw new BankNoticeConfirmationException('manual_reason_required', 'Vyplňte důvod ručního potvrzení.', 422);
        if (mb_strlen(trim($reason)) > 500) throw new BankNoticeConfirmationException('manual_reason_too_long', 'Důvod ručního potvrzení může mít nejvýše 500 znaků.', 422);
        return $this->atomic($supplierId, function () use ($supplierId, $noticeId, $authoritativeId, $userId, $reason, $onConfirmed): array {
            $notice = $this->notice($supplierId, $noticeId);
            $target = $this->transaction($supplierId, $authoritativeId);
            $record = $this->record($supplierId, $noticeId);
            if (($record['state'] ?? null) === 'confirmed') {
                if ((int) $record['authoritative_transaction_id'] !== $authoritativeId) {
                    throw new BankNoticeConfirmationException('notice_read_only', 'Nahrazené avízo již má jiný potvrzující pohyb.');
                }
                return $this->inspectNotice($supplierId, $noticeId);
            }
            if ($notice === null || $target === null || !$this->isAuthoritative($target) || !$this->sameAccount($notice, $target) || !$this->sameCurrency($notice, $target)
                || ((float) $notice['amount'] <=> 0) !== ((float) $target['amount'] <=> 0)) {
                throw new BankNoticeConfirmationException('unsafe_confirmation', 'Pohyby nemají shodný účet, měnu nebo směr platby.');
            }
            (new BankNoticeManualConfirmationGuard($this->db))->assertAllowed($supplierId, $notice, $target);
            if ($this->sourceBlockers($notice, $target) !== []) throw new BankNoticeConfirmationException('notice_dependencies', 'Avízo má nevyřešené vazby.');
            $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_notice_confirmations WHERE authoritative_transaction_id=? AND (notice_transaction_id IS NULL OR notice_transaction_id<>?)' . $this->lockingRead());
            $stmt->execute([$authoritativeId, $noticeId]);
            if ($stmt->fetchColumn() !== false) throw new BankNoticeConfirmationException('target_reserved', 'Tento pohyb již potvrzuje jiné avízo.');
            $this->confirm($supplierId, $notice, $target, $userId, 'manual', trim($reason));
            if ($onConfirmed !== null) $onConfirmed();
            return $this->inspectNotice($supplierId, $noticeId);
        });
    }

    /** Volající drží supplier lock a transakci i během následného DELETE. */
    public function prepareNoticeDeletion(int $supplierId, int $statementId, ?int $userId = null): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Mazání avíz vyžaduje transakci.');
        $this->assertNoticeStatement($supplierId, $statementId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_transactions WHERE statement_id=? ORDER BY id FOR UPDATE');
        $stmt->execute([$statementId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->deletionBlockers((int) $id) !== []) throw new BankNoticeConfirmationException('notice_dependencies', 'Avíza mají úhradu nebo nevyřešené vazby.');
        }
        $this->db->pdo()->prepare('UPDATE bank_notice_confirmations c JOIN bank_transactions bt ON bt.id=c.notice_transaction_id SET c.deleted_at=NOW() WHERE c.supplier_id=? AND bt.statement_id=? AND c.state=\'confirmed\'')->execute([$supplierId, $statementId]);
    }

    /** Neobnovuje úhrady, jen vrací zachované avízo do viditelné kontroly. */
    public function invalidateAuthoritativeTransactions(int $supplierId, array $txIds, ?int $userId = null): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Zneplatnění potvrzení vyžaduje transakci.');
        $txIds = array_values(array_filter(array_map('intval', $txIds), static fn (int $v): bool => $v > 0));
        if ($txIds === []) return;
        $stmt = $this->db->pdo()->prepare("UPDATE bank_notice_confirmations SET state='review',reason='authoritative_removed',authoritative_transaction_id=NULL,checked_at=NOW() WHERE supplier_id=? AND authoritative_transaction_id IN (" . implode(',', array_fill(0, count($txIds), '?')) . ')');
        $stmt->execute([$supplierId, ...$txIds]);
    }

    /** Smazání processed-message nesmí obejít trvalou stopu již odstraněného avíza. */
    public function tombstone(int $supplierId, string $sourceRef): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_notice_confirmations WHERE supplier_id=? AND notice_identity=? AND deleted_at IS NOT NULL');
        $stmt->execute([$supplierId, hash('sha256', $sourceRef)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Blokuje druhou úhradu při konfliktu s existujícím avízem. */
    public function blocksMatching(int $supplierId, int $transactionId, ?array $knownTransaction = null): bool
    {
        $row = $knownTransaction ?? $this->transaction($supplierId, $transactionId);
        if ($row === null) return false;
        if ($row['source'] === 'email_notice') {
            $result = $this->reconcileNotice($supplierId, $transactionId);
            return $result['confirmation_state'] !== 'pending' || $result['candidates'] !== [];
        }
        if (!$this->isAuthoritative($row)) return false;
        // Bez relevantních avíz není co přebírat. Žádná cache: i nově přijaté avízo
        // se musí projevit v témže objektu; locking read vidí i starší RR transakci.
        $stmt = $this->db->pdo()->prepare("SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE " . BankStatementOwnershipResolver::sql() . " AND bs.source='email_notice' AND bt.source='email_notice' AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) LIMIT 1" . $this->lockingRead());
        $stmt->execute([...BankStatementOwnershipResolver::params($supplierId), $row['posted_at'], $row['posted_at']]);
        if ($stmt->fetchColumn() === false) return false;
        foreach ($this->reconcileAuthoritative($supplierId, $transactionId) as $noticeId => $result) {
            if ($result['confirmation_state'] !== 'review') continue;
            foreach ($result['candidates'] as $candidate) if ((int) $candidate['id'] === $transactionId && $this->hasPayments((int) $noticeId)) return true;
        }
        return false;
    }

    public function assertMutable(int $supplierId, int $transactionId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM bank_notice_confirmations WHERE supplier_id=? AND notice_transaction_id=? AND (state=\'confirmed\' OR reason=\'authoritative_removed\')' . $this->lockingRead());
        $stmt->execute([$supplierId, $transactionId]);
        if ($stmt->fetchColumn() !== false) throw new BankNoticeConfirmationException('notice_read_only', 'Nahrazené avízo nelze samostatně měnit.');
    }

    private function reconcileNoticeLocked(int $supplierId, int $noticeId, ?int $userId): array
    {
        $inspection = $this->evaluateNotice($supplierId, $noticeId);
        if ($inspection['confirmation_state'] === 'confirmed') return $inspection;
        if ($inspection['confirmation_reason'] === 'ready') {
            $notice = $this->notice($supplierId, $noticeId);
            $target = $this->transaction($supplierId, (int) $inspection['authoritative_transaction_id']);
            $method = abs((float) $notice['amount'] - (float) $target['amount']) > 0.005 ? 'card_fx' : 'exact';
            $this->confirm($supplierId, $notice, $target, $userId, $method, null);
            return $this->inspectNotice($supplierId, $noticeId);
        }
        $notice = $this->notice($supplierId, $noticeId);
        $this->saveRecord($supplierId, $notice, $inspection['confirmation_state'], $inspection['confirmation_reason']);
        return $inspection;
    }

    private function confirm(int $supplierId, array $notice, array $target, ?int $userId, string $method, ?string $reason): void
    {
        $pdo = $this->db->pdo();
        $ids = [(int) $notice['id'], (int) $target['id']];
        sort($ids);
        $pdo->prepare('SELECT id FROM bank_transactions WHERE id IN (?,?) ORDER BY id FOR UPDATE')->execute($ids);
        $this->saveRecord($supplierId, $notice, 'pending', 'ready');
        $hadPayments = $this->hasPayments((int) $notice['id']);
        if ($hadPayments) {
            foreach (['invoice_payments', 'payment_matches'] as $table) {
                $pdo->prepare("UPDATE {$table} SET bank_transaction_id=? WHERE supplier_id=? AND bank_transaction_id=?")->execute([$target['id'], $supplierId, $notice['id']]);
            }
            if ($method === 'card_fx') {
                $pdo->prepare('UPDATE payment_matches SET amount=? WHERE supplier_id=? AND bank_transaction_id=?')->execute([number_format(abs((float) $target['amount']), 2, '.', ''), $supplierId, $target['id']]);
            }
        }
        if ($hadPayments || in_array($notice['match_status'], ['auto_exact','auto_partial','manual'], true)) {
            $pdo->prepare('UPDATE bank_transactions SET match_status=?,matched_invoice_id=?,matched_at=?,matched_by=? WHERE id=?')->execute([$notice['match_status'], $notice['matched_invoice_id'], $notice['matched_at'], $notice['matched_by'], $target['id']]);
        }
        $payout = $pdo->prepare('SELECT 1 FROM gopay_clearings WHERE payout_match_transaction_id=? LIMIT 1' . $this->lockingRead());
        $payout->execute([$notice['id']]);
        $hasPayout = $payout->fetchColumn() !== false;
        $pdo->prepare('UPDATE gopay_clearings SET payout_match_transaction_id=? WHERE supplier_id=? AND payout_match_transaction_id=?')->execute([$target['id'], $supplierId, $notice['id']]);
        $pdo->prepare('UPDATE client_bank_accounts SET last_bank_transaction_id=? WHERE supplier_id=? AND last_bank_transaction_id=?')->execute([$target['id'], $supplierId, $notice['id']]);
        // Jde stále o stejné pozorování platby, nikoli nový vzorek pro učení.
        // Počty a datum povýšení mapy se při pouhém převzetí nepřepočítávají.
        $pdo->prepare('UPDATE bank_counterparty_observations o JOIN bank_counterparty_map m ON m.id=o.map_id SET o.bank_transaction_id=? WHERE m.supplier_id=? AND o.bank_transaction_id=?')->execute([$target['id'], $supplierId, $notice['id']]);
        $pdo->prepare("UPDATE bank_transactions SET match_status=?,ignore_origin='superseded',ignore_note='Nahrazeno bankovním výpisem',matched_invoice_id=NULL,matched_at=NULL,matched_by=NULL WHERE id=?")->execute([$hadPayments || in_array($notice['match_status'], ['auto_exact','auto_partial','manual'], true) ? 'unmatched' : 'ignored', $notice['id']]);
        $pdo->prepare("UPDATE bank_notice_confirmations SET state='confirmed',reason=NULL,authoritative_transaction_id=?,original_authoritative_transaction_id=?,method=?,confirmed_by=?,manual_reason=?,confirmed_at=NOW(),checked_at=NOW() WHERE supplier_id=? AND notice_transaction_id=?")->execute([$target['id'], $target['id'], $method, $userId, $reason === null ? null : mb_substr($reason, 0, 500), $supplierId, $notice['id']]);
        $pdo->prepare("UPDATE bank_statements SET matched_count=(SELECT COUNT(*) FROM bank_transactions WHERE statement_id=? AND match_status IN ('auto_exact','auto_partial','manual')) WHERE id=?")->execute([$notice['statement_id'], $notice['statement_id']]);
        if ($hasPayout && !($this->gopay?->completeTransferredPayout($supplierId, (int) $target['id'], $userId) ?? false)) {
            throw new BankNoticeConfirmationException('dependency_gopay_payout', 'GoPay nedokončil převzetí výplaty; avízo nebylo potvrzeno.');
        }
    }

    private function sourceBlockers(array $notice, array $target): array
    {
        $pdo = $this->db->pdo();
        $id = (int) $notice['id'];
        $blockers = [];
        foreach (self::LINKS as [$table, $column]) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column}=? LIMIT 1" . $this->lockingRead());
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) $blockers[] = 'dependency_' . $table;
        }
        $observations = $pdo->prepare('SELECT m.supplier_id FROM bank_counterparty_observations o JOIN bank_counterparty_map m ON m.id=o.map_id WHERE o.bank_transaction_id=?' . $this->lockingRead());
        $observations->execute([$id]);
        $observedSuppliers = array_map('intval', $observations->fetchAll(PDO::FETCH_COLUMN));
        if ($observedSuppliers !== []) {
            $collision = $pdo->prepare('SELECT 1 FROM bank_counterparty_observations WHERE bank_transaction_id=? LIMIT 1' . $this->lockingRead());
            $collision->execute([$target['id']]);
            if ($collision->fetchColumn() !== false || array_diff($observedSuppliers, [(int) $notice['supplier_id']]) !== []) $blockers[] = 'dependency_bank_counterparty_observations';
        }
        foreach (['journal_entries' => "source_type IN ('bank','card_settlement','card_writeoff') AND source_id=?", 'cash_journal_notes' => "source_type='bank' AND source_id=? AND deleted_at IS NULL", 'de_movement_classification_history' => "source_type='bank' AND source_id=?"] as $table => $where) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1" . $this->lockingRead());
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) $blockers[] = 'dependency_' . $table;
        }
        foreach (['document_links' => "entity_type='bank_transaction' AND entity_id=?", 'document_dimensions' => "doc_type='bank_transaction' AND doc_id=?", 'document_dimension_splits' => "doc_type='bank_transaction' AND doc_id=?"] as $table => $where) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$where} LIMIT 1" . $this->lockingRead());
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) $blockers[] = 'dependency_' . $table;
        }
        if ($notice['balance'] !== null) {
            $stmt = $pdo->prepare("SELECT bt.*, bs.account_number AS stmt_account, bs.bank_code AS stmt_bank, bs.currency AS stmt_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE " . BankStatementOwnershipResolver::sql() . " AND bt.source='statement' AND bs.source IN " . BankStatementSource::sqlList() . " AND ((bt.balance IS NOT NULL AND bt.posted_at BETWEEN ? AND ?) OR (bs.curr_balance IS NOT NULL AND bs.statement_date BETWEEN ? AND ?))");
            $end = max((new \DateTimeImmutable((string) $target['posted_at']))->modify('+' . EmailNoticeReconciler::DATE_WINDOW_DAYS . ' days')->format('Y-m-d'), (string) ($target['statement_date'] ?? ''));
            $stmt->execute([...BankStatementOwnershipResolver::params((int) $notice['supplier_id']), $notice['posted_at'], $end, $notice['posted_at'], $end]);
            $hasAnchor = false;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $anchor) {
                if ($this->sameAccount($notice, $anchor) && $this->sameCurrency($notice, $anchor)) { $hasAnchor = true; break; }
            }
            if (!$hasAnchor) $blockers[] = 'provisional_balance_not_covered';
        }
        if ($this->hasPayments($id)) {
            if (!in_array($notice['match_status'], ['auto_exact','auto_partial','manual'], true)) $blockers[] = 'source_payment_status';
            if ($this->hasPayments((int) $target['id']) || in_array($target['match_status'], ['auto_exact','auto_partial','manual'], true)) $blockers[] = 'target_has_payment';
            $stmt = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE bank_transaction_id=? AND supplier_id<>? LIMIT 1');
            $stmt->execute([$id, $notice['supplier_id']]);
            if ($stmt->fetchColumn() !== false) $blockers[] = 'foreign_payment_dependency';
            $stmt = $pdo->prepare('SELECT 1 FROM payment_matches WHERE bank_transaction_id=? AND supplier_id<>? LIMIT 1');
            $stmt->execute([$id, $notice['supplier_id']]);
            if ($stmt->fetchColumn() !== false) $blockers[] = 'foreign_payment_dependency';
        } elseif (in_array($notice['match_status'], ['auto_exact','auto_partial','manual'], true)) {
            $stmt = $pdo->prepare('SELECT 1 FROM gopay_clearings WHERE supplier_id=? AND payout_match_transaction_id=? LIMIT 1' . $this->lockingRead());
            $stmt->execute([$notice['supplier_id'], $id]);
            if ($stmt->fetchColumn() === false) $blockers[] = 'missing_payment_evidence';
        }
        $stmt = $pdo->prepare('SELECT supplier_id FROM gopay_clearings WHERE payout_match_transaction_id=?' . $this->lockingRead());
        $stmt->execute([$id]);
        $payoutSuppliers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($payoutSuppliers !== []) {
            if ($this->gopay === null || array_diff($payoutSuppliers, [(int) $notice['supplier_id']]) !== []) $blockers[] = 'dependency_gopay_payout';
            if ($this->hasPayments((int) $target['id']) || in_array($target['match_status'], ['auto_exact','auto_partial','manual'], true)) $blockers[] = 'target_has_payment';
        }
        return array_values(array_unique($blockers));
    }

    private function hasPayments(int $id): bool
    {
        foreach (['invoice_payments', 'payment_matches'] as $table) {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE bank_transaction_id=? LIMIT 1" . $this->lockingRead());
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) return true;
        }
        return false;
    }

    private function saveRecord(int $supplierId, array $notice, string $state, ?string $reason): void
    {
        $ref = (string) ($notice['source_ref'] ?: 'legacy-transaction:' . $notice['id']);
        $this->db->pdo()->prepare('INSERT INTO bank_notice_confirmations (supplier_id,notice_transaction_id,notice_source_ref,notice_identity,state,reason) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),reason=VALUES(reason),checked_at=NOW()')->execute([$supplierId, $notice['id'], $ref, hash('sha256', $ref), $state, $reason]);
    }

    private function record(int $supplierId, int $noticeId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_notice_confirmations WHERE supplier_id=? AND notice_transaction_id=?' . $this->lockingRead());
        $stmt->execute([$supplierId, $noticeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function notice(int $supplierId, int $id): ?array
    {
        $row = $this->transaction($supplierId, $id);
        return $row !== null && $row['source'] === 'email_notice' && $row['statement_source'] === 'email_notice' ? $row : null;
    }

    private function transaction(int $supplierId, int $id): ?array
    {
        // Mutace čeká i na zapisovatele, který drží řádkový zámek ve vnější transakci.
        // Locking read současně obchází starší REPEATABLE READ snapshot volajícího.
        $stmt = $this->db->pdo()->prepare('SELECT bt.*,bs.supplier_id,bs.source AS statement_source,bs.statement_date,bs.account_number AS stmt_account,bs.bank_code AS stmt_bank,bs.currency AS stmt_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE ' . BankStatementOwnershipResolver::sql() . ' AND bt.id=?' . $this->lockingRead());
        $stmt->execute([...BankStatementOwnershipResolver::params($supplierId), $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Jednoznačný legacy vlastník byl ověřen stejným resolverem jako v akcích.
        if ($row !== false) $row['supplier_id'] = $supplierId;
        return $row ?: null;
    }

    private function candidates(array $notice): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT bt.*,bs.supplier_id,bs.source AS statement_source,bs.statement_date,bs.account_number AS stmt_account,bs.bank_code AS stmt_bank,bs.currency AS stmt_currency FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE " . BankStatementOwnershipResolver::sql() . " AND bt.source='statement' AND bs.source IN " . BankStatementSource::sqlList() . ' AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL 5 DAY) AND DATE_ADD(?, INTERVAL 5 DAY) ORDER BY bt.id' . $this->lockingRead());
        $stmt->execute([...BankStatementOwnershipResolver::params((int) $notice['supplier_id']), $notice['posted_at'], $notice['posted_at']]);
        // bank_ref nemá ověřený společný namespace: banky sem dávají i konstantu
        // nebo denní pořadí (viz StatementImporter::transactionIdentities). Proto
        // nesmí přebít VS/protiúčet ani rozhodnout mezi jinak shodnými kandidáty.
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn (array $r): bool => $this->sameAccount($notice, $r) && $this->sameCurrency($notice, $r) && $this->sameIdentity($notice, $r) && ((float) $notice['amount'] <=> 0) === ((float) $r['amount'] <=> 0)));
    }

    private function isAuthoritative(array $row): bool
    {
        return $row['source'] === 'statement' && BankStatementSource::isStatement((string) $row['statement_source']);
    }

    private function sameAccount(array $a, array $b): bool
    {
        return EmailNoticeReconciler::sameOwnAccount($a, $b);
    }

    private function sameCurrency(array $a, array $b): bool
    {
        return EmailNoticeReconciler::sameCurrency($a, $b);
    }

    private function sameIdentity(array $notice, array $authoritative): bool
    {
        return EmailNoticeReconciler::twinIdentity($authoritative, $notice, VariableSymbolNormalizer::digits((string) ($authoritative['variable_symbol'] ?? ''))) !== null;
    }

    private function lockingRead(): string
    {
        return $this->db->pdo()->inTransaction() ? ' FOR UPDATE' : '';
    }

    private function inspection(string $state, ?string $reason, ?array $target, array $candidates, array $blockers): array
    {
        $statementIds = [];
        if ($target !== null) {
            $statementIds[] = (int) $target['statement_id'];
            $stmt = $this->db->pdo()->prepare('SELECT statement_id FROM bank_transaction_imports WHERE bank_transaction_id=?');
            $stmt->execute([$target['id']]);
            $statementIds = array_values(array_unique([...$statementIds, ...array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]));
        }
        return ['confirmation_state' => $state, 'confirmation_reason' => $reason, 'authoritative_transaction_id' => $target !== null ? (int) $target['id'] : null, 'authoritative_statement_id' => $target !== null ? (int) $target['statement_id'] : null, 'authoritative_statement_ids' => $statementIds, 'candidates' => array_map($this->displayTransaction(...), $candidates), 'blockers' => $blockers];
    }

    private function displayTransaction(array $row): array
    {
        $public = array_intersect_key($row, array_flip(['id', 'statement_id', 'source', 'posted_at', 'amount', 'currency', 'variable_symbol', 'counterparty_name', 'counterparty_account', 'counterparty_bank', 'description', 'bank_ref', 'match_status']));
        $public['id'] = (int) $public['id'];
        $public['statement_id'] = (int) $public['statement_id'];
        $public['amount'] = (float) $public['amount'];
        return $public;
    }

    private function atomic(int $supplierId, callable $action): mixed
    {
        $lock = $this->lockSupplier($supplierId);
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        $savepoint = 'bank_notice_' . bin2hex(random_bytes(5));
        try {
            if ($owns) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT ' . $savepoint);
            // Nový cíl potvrzení ještě nemá notice vazbu, takže jeho běžný zápis
            // drží jen účetní a řádkové zámky. Obě cesty musí začít obdobím.
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            $result = $action();
            if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                if ($owns) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            if ($e instanceof \PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1020, 1205, 1213], true)) {
                throw new BankNoticeConfirmationException('bank_busy', 'Bankovní pohyby se souběžně změnily. Opakujte kontrolu v nové transakci.');
            }
            throw $e;
        } finally {
            $this->unlockSupplier($lock);
        }
    }
}
