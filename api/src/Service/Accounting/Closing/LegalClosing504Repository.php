<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{DimensionAssignmentRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingPeriodStatus, AccountingRoleProfile, JournalLineAmount, JournalLineComparison};
use PDO;

final class LegalClosing504Repository
{
    private array $verifiedEntryIds = [];
    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal, private readonly bool $currentRead = true) {}

    public function step(int $supplierId, int $periodId, string $stepKey): array
    {
        $this->assertTransaction($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT status,payload,note FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key=?' . $this->lock());
        $stmt->execute([$supplierId, $periodId, $stepKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return ['status' => 'pending', 'payload' => [], 'note' => null];
        $row['payload'] = json_decode((string) ($row['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    public function resolveAccounts(int $supplierId, array $lines): array
    {
        $this->assertTransaction($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? ORDER BY id' . $this->lock());
        $stmt->execute([$supplierId]);
        $ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id', 'account_code');
        foreach ($lines as &$line) {
            if (!isset($ids[$line['account_code']])) throw self::changed();
            $line['account_id'] = (int) $ids[$line['account_code']];
        }
        unset($line);
        return $lines;
    }

    public function reserveRemaining(int $supplierId, int $sourceEntryId, string $asOf): array
    {
        return $this->reserveSnapshot($supplierId, $sourceEntryId, $asOf)['remaining'];
    }

    public function reserveFullyReversedInPeriod(int $supplierId, int $sourceEntryId, int $correctionPeriodId): bool
    {
        return $this->reserveReversalEvidence($supplierId, $sourceEntryId, $correctionPeriodId)['fully_reversed'];
    }

    public function reserveReversalEvidence(int $supplierId, int $sourceEntryId, int $correctionPeriodId): array
    {
        $this->assertTransaction($supplierId);
        $this->verifiedEntryIds = [];
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='provisions' ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId]);
        $origin = null;
        $neutral = true;
        $relatedIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['asset_source_revisions'] ?? [] as $revision) {
                if (($revision['version'] ?? null) !== 'asset-reserve-revision-v2') continue;
                AssetReserveSourceDecision::verifyRevision($revision);
                foreach ($revision['transfers'] as $transfer) if ($transfer['source_entry_id'] === $sourceEntryId) $neutral = false;
            }
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                if (($record['legal_decision']['version'] ?? null) !== '504-legal-v1'
                    || !in_array($record['rule_key'] ?? '', ['reserve.repairs.create', 'reserve.repairs.release'], true)) continue;
                $record['ends_on'] = $step['ends_on'];
                $record['period_id'] = (int) $step['period_id'];
                $related = (int) ($record['entry_id'] ?? 0) === $sourceEntryId
                    || (int) ($record['legal_decision']['source_entry_id'] ?? 0) === $sourceEntryId;
                $before = $this->verifiedEntryIds;
                $active = $this->recordActive($supplierId, $record, '9999-12-31');
                if (!$related) continue;
                $relatedIds += array_diff_key($this->verifiedEntryIds, $before);
                if ((int) $record['entry_id'] === $sourceEntryId) {
                    if ($origin !== null || $record['rule_key'] !== 'reserve.repairs.create') throw self::changed();
                    $origin = $record;
                }
                if ($active || $record['period_id'] !== $correctionPeriodId) $neutral = false;
                foreach (array_diff_key($this->verifiedEntryIds, $before) as $id => $_) {
                    if ((int) $this->entry($supplierId, (int) $id)['period_id'] !== $correctionPeriodId) $neutral = false;
                }
            }
        }
        if ($origin === null) throw self::changed();
        $this->assertReserveUsageLinked($supplierId, $origin, '9999-12-31');
        return ['fully_reversed' => $neutral, 'entry_ids' => array_keys($relatedIds)];
    }

    public function reserveSnapshot(int $supplierId, int $sourceEntryId, string $asOf): array
    {
        $this->assertTransaction($supplierId);
        $this->verifiedEntryIds = [];
        if ($sourceEntryId <= 0) throw new ClosingException('legal_closing_source_required', 'Pro rozpuštění vyberte původní doloženou rezervu.', 422);
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='provisions' AND p.ends_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        $records = [];
        $corrections = [];
        $allCorrections = [];
        $otherRecords = [];
        $origin = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['asset_source_revisions'] ?? [] as $revision) {
                if (($revision['version'] ?? '') !== 'asset-reserve-revision-v2') continue;
                AssetReserveSourceDecision::verifyRevision($revision);
                $allCorrections[] = $revision;
                foreach ($revision['transfers'] as $transfer) if ($transfer['source_entry_id'] === $sourceEntryId) {
                    $corrections[] = ['revision' => $revision, 'transfer' => $transfer];
                }
            }
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                if (($record['legal_decision']['version'] ?? null) !== '504-legal-v1') continue;
                $record['ends_on'] = $step['ends_on'];
                $record['period_id'] = (int) $step['period_id'];
                if ((int) ($record['entry_id'] ?? 0) === $sourceEntryId && ($record['rule_key'] ?? '') === 'reserve.repairs.create') $origin = $record;
                elseif ((int) ($record['legal_decision']['source_entry_id'] ?? 0) === $sourceEntryId && ($record['rule_key'] ?? '') === 'reserve.repairs.release') $records[] = $record;
                elseif (in_array($record['rule_key'] ?? '', ['reserve.repairs.create', 'reserve.repairs.release'], true)) $otherRecords[] = $record;
            }
        }
        if ($origin === null || !$this->recordActive($supplierId, $origin, $asOf)) throw self::changed();
        $remaining = [];
        $cells = [];
        foreach ($origin['legal_decision']['allocations'] as $cell) {
            $key = LegalClosing504Builder::cellKey($cell);
            $remaining[$key] = ProfitDistribution504Builder::allocationCents($cell['amount']);
            $cells[$key] = $cell;
        }
        foreach ($allCorrections as $revision) $this->verifyAssociationTransfer($supplierId, $revision);
        $events = [];
        foreach ($records as $record) {
            if ($this->recordActive($supplierId, $record, $asOf)) $events[] = ['date' => $record['ends_on'], 'order' => (int) $record['entry_id'], 'record' => $record];
        }
        foreach ($corrections as $correction) $events[] = ['date' => $correction['revision']['effective_on'],
            'order' => $correction['revision']['sequence_entry_id'], 'correction' => $correction];
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['order'], isset($a['correction']) ? 1 : 0] <=> [$b['date'], $b['order'], isset($b['correction']) ? 1 : 0]);
        foreach ($events as $event) {
            if (isset($event['record'])) {
                foreach ($event['record']['legal_decision']['allocations'] as $cell) {
                    $key = LegalClosing504Builder::cellKey($cell);
                    $remaining[$key] = ($remaining[$key] ?? 0) - ProfitDistribution504Builder::allocationCents($cell['amount']);
                    if ($remaining[$key] < 0) throw self::changed();
                }
                continue;
            }
            $revision = $event['correction']['revision'];
            $transfer = $event['correction']['transfer'];
            $before = [];
            foreach ($transfer['before'] as $cell) $before[LegalClosing504Builder::cellKey($cell)] = ProfitDistribution504Builder::allocationCents($cell['amount']);
            $actual = array_filter($remaining, static fn (int $cents): bool => $cents !== 0);
            ksort($actual); ksort($before);
            if ($actual !== $before) throw self::changed();
            $remaining = [];
            foreach ($transfer['after'] as $cell) {
                $key = LegalClosing504Builder::cellKey($cell);
                $remaining[$key] = ProfitDistribution504Builder::allocationCents($cell['amount']);
                $cells[$key] = $cell;
            }
        }
        foreach ($otherRecords as $record) $this->recordActive($supplierId, $record, $asOf);
        $keys = array_fill_keys(array_keys($cells), true);
        $this->assertAccountUsageLinked($supplierId, '941', $origin['ends_on'], (int) $origin['entry_id'], $asOf, $keys);
        $ledger = $this->accountCells($supplierId, '941', $asOf);
        foreach ($remaining as $key => &$cents) $cents = min($cents, max(0, $ledger[$key] ?? 0));
        unset($cents);
        return ['remaining' => $remaining, 'cells' => $cells, 'origin' => $origin, 'verified_entry_ids' => array_keys($this->verifiedEntryIds)];
    }

    public function entryEvidence(int $supplierId, int $entryId): array
    {
        $this->assertTransaction($supplierId);
        $entry = $this->entry($supplierId, $entryId);
        if ($entry === null) throw self::changed();
        $stmt = $this->db->pdo()->prepare('SELECT * FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id' . $this->lock());
        $stmt->execute([$supplierId, $entryId]);
        $lines = $this->assignments($supplierId, $stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach ($lines as &$line) foreach ($line['dimension_splits'] as &$values) foreach ($values as &$percentage) $percentage = (string) $percentage;
        unset($line, $values, $percentage);
        return array_intersect_key($entry, array_flip(['id', 'supplier_id', 'period_id', 'entry_date', 'source_type', 'source_id', 'posted_at', 'reversed_by']))
            + ['lines' => array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['id', 'account_id', 'side', 'amount', 'is_red_storno', 'currency_code', 'amount_foreign', 'fx_rate', 'cost_center', 'project_id', 'dimensions', 'dimension_splits'])), $lines)];
    }

    public function verifyAssociationTransfer(int $supplierId, array $revision): void
    {
        AssetReserveSourceDecision::verifyRevision($revision);
        foreach ($revision['pinned_entries'] as $evidence) {
            if ($this->entryEvidence($supplierId, $evidence['id']) !== $evidence) throw self::changed();
            $this->verifiedEntryIds[$evidence['id']] = true;
        }
        $source = new AssetReserveSourceRepository($this->db, $this->currentRead);
        $source->assertReceiptCurrent($supplierId, $revision['previous_source_date'], $revision['previous_source_evidence']);
        $source->assertReceiptCurrent($supplierId, $revision['source_date'], $revision['source_evidence']);
        $lines = AssetReserveCorrection504Builder::lines($revision['transfers'], $revision['source_evidence']);
        if ($lines === []) {
            if ($revision['entry_id'] !== null) throw self::changed();
        } elseif ($revision['entry_id'] === null || !$this->verifyRecordedJournal($supplierId, $revision['entry_id'], $revision['period_id'], $revision['effective_on'], $lines, $revision['effective_on'])) throw self::changed();
    }

    public function reserveSources(int $supplierId, string $asOf): array
    {
        $this->assertTransaction($supplierId);
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='provisions' AND p.ends_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        $sources = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                if (($record['rule_key'] ?? '') !== 'reserve.repairs.create' || ($record['legal_decision']['version'] ?? '') !== '504-legal-v1') continue;
                $record['ends_on'] = $step['ends_on'];
                $record['period_id'] = (int) $step['period_id'];
                if (!$this->recordActive($supplierId, $record, $asOf)) continue;
                $snapshot = $this->reserveSnapshot($supplierId, (int) $record['entry_id'], $asOf);
                $remaining = $snapshot['remaining'];
                $cells = [];
                foreach ($snapshot['cells'] as $cell) {
                    $available = $remaining[LegalClosing504Builder::cellKey($cell)] ?? 0;
                    unset($cell['amount']);
                    if ($available > 0) $cells[] = $cell + ['available_cents' => $available];
                }
                $sources[] = ['entry_id' => (int) $record['entry_id'], 'document_no' => $record['document_no'],
                    'reference' => $record['legal_decision']['reference'], 'cells' => $cells];
            }
        }
        return $sources;
    }

    public function assertReserveReversible(int $supplierId, array $record, string $asOf): void
    {
        $remaining = $this->reserveRemaining($supplierId, (int) $record['entry_id'], $asOf);
        foreach ($record['legal_decision']['allocations'] as $cell) {
            if (($remaining[LegalClosing504Builder::cellKey($cell)] ?? 0) !== ProfitDistribution504Builder::allocationCents($cell['amount'])) {
                throw new ClosingException('legal_closing_source_used', 'Rezerva již byla čerpána. Nejprve stornujte její doložená čerpání.', 409);
            }
        }
    }

    public function provisionCells(int $supplierId, int $invoiceId, string $asOf, ?int $excludedEntryId = null): array
    {
        return $this->provisionStates($supplierId, $asOf, $invoiceId, $excludedEntryId)[$invoiceId] ?? [];
    }

    public function provisionStates(int $supplierId, string $asOf, ?int $invoiceId = null, ?int $excludedEntryId = null): array
    {
        $this->assertTransaction($supplierId);
        $this->verifiedEntryIds = [];
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='provisions' AND p.ends_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        $states = [];
        $origins = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                $id = (int) ($record['invoice_id'] ?? 0);
                if (($record['rule_key'] ?? '') !== 'allowance.receivable.legal.change') continue;
                $record['ends_on'] = $step['ends_on'];
                $record['period_id'] = (int) $step['period_id'];
                if (!$this->recordActive($supplierId, $record, $asOf) || (int) $record['entry_id'] === $excludedEntryId) continue;
                if ($invoiceId !== null && $id !== $invoiceId) continue;
                $origins[] = $record;
                foreach ($record['movement_allocations'] as $cell) {
                    $key = LegalClosing504Builder::cellKey($cell);
                    $before = $states[$id][$key]['available_cents'] ?? 0;
                    $cents = ProfitDistribution504Builder::allocationCents($cell['amount']);
                    $sign = $cell['direction'] === 'create' ? 1 : -1;
                    unset($cell['direction'], $cell['amount']);
                    $states[$id][$key] = $cell + ['available_cents' => $before + $sign * $cents];
                }
            }
        }
        foreach ($origins as $origin) {
            $keys = [];
            foreach ($origin['movement_allocations'] as $cell) if ($cell['direction'] === 'create') $keys[LegalClosing504Builder::cellKey($cell)] = true;
            if ($keys !== []) $this->assertAccountUsageLinked($supplierId, '391', $origin['ends_on'], (int) $origin['entry_id'], $asOf, $keys);
        }
        foreach ($states as &$cells) {
            foreach ($cells as $cell) if ($cell['available_cents'] < 0) throw self::changed();
            $cells = array_filter($cells, static fn (array $cell): bool => $cell['available_cents'] > 0);
        }
        unset($cells);
        return array_filter($states);
    }

    public function receivableSource(int $supplierId, int $invoiceId, string $asOf): array
    {
        $this->assertTransaction($supplierId);
        $stmt = $this->db->pdo()->prepare("SELECT id,entry_date FROM journal_entries WHERE supplier_id=? AND source_type='invoice' AND source_id=?
            AND posted_at IS NOT NULL AND reversed_by IS NULL AND entry_date<=? ORDER BY id" . $this->lock());
        $stmt->execute([$supplierId, $invoiceId, $asOf]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($entries) !== 1) throw self::changed();
        $source = $entries[0];
        $framework = new AccountingFrameworkService($this->db, new \MyInvoice\Repository\AccountingFrameworkRepository($this->db));
        if (($this->currentRead ? $framework->forDateForUpdate($supplierId, $source['entry_date']) : $framework->forDate($supplierId, $source['entry_date'])) !== AccountingFrameworkService::CZ_504) throw self::changed();
        $stmt = $this->db->pdo()->prepare("SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code LIKE '311%' ORDER BY l.id" . $this->lock());
        $stmt->execute([$supplierId, $source['id']]);
        $rows = $this->assignments($supplierId, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $cells = [];
        foreach (ProfitDistribution504Builder::cells($rows) as $cell) {
            $key = LegalClosing504Builder::cellKey($cell);
            $amount = -$cell['available_cents'];
            if ($amount <= 0) throw self::changed();
            $cells[$key] ??= array_intersect_key($cell, array_flip(['dimensions', 'cost_center', 'project_id'])) + ['available_cents' => 0];
            $cells[$key]['available_cents'] += $amount;
        }
        ksort($cells);
        $principal = array_sum(array_column($cells, 'available_cents'));
        if ($principal <= 0) throw self::changed();
        $saldo = new \MyInvoice\Repository\SaldoRepository($this->db);
        $account = $saldo->resolveAccount($supplierId, '311', $asOf);
        if ($account === null) throw self::changed();
        $normalSide = $account['normal_side'] ?? (in_array($account['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        $remaining = null;
        foreach ($saldo->iterateOpenInvoiceItems($supplierId, (int) $account['id'], $asOf, [$invoiceId], $this->currentRead) as $item) {
            $booked = $normalSide === 'debit' ? $item['booked_signed'] : -$item['booked_signed'];
            if ((int) round($booked * 100) !== $principal) throw self::changed();
            $remaining = (int) round(\MyInvoice\Repository\SaldoRepository::settlementAmounts($booked, (float) $item['paid_ratio'])['remaining'] * 100);
        }
        if ($remaining === null || $remaining <= 0 || $remaining > $principal) throw self::changed();
        $allocated = \MyInvoice\Service\Accounting\Dimension\DimensionStamper::distributeCents($remaining, array_values(array_column($cells, 'available_cents')));
        foreach (array_keys($cells) as $index => $key) $cells[$key]['available_cents'] = $allocated[$index];
        return ['entry_id' => (int) $source['id'], 'source_line_ids' => array_map('intval', array_column($rows, 'id')), 'cells' => $cells, 'remaining_cents' => $remaining];
    }

    public function receivableRemaining(int $supplierId, int $invoiceId, string $asOf): int
    {
        $this->assertTransaction($supplierId);
        $saldo = new \MyInvoice\Repository\SaldoRepository($this->db);
        $account = $saldo->resolveAccount($supplierId, '311', $asOf);
        if ($account === null) return 0;
        $normalSide = $account['normal_side'] ?? (in_array($account['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        foreach ($saldo->iterateOpenInvoiceItems($supplierId, (int) $account['id'], $asOf, [$invoiceId], $this->currentRead) as $item) {
            $booked = $normalSide === 'debit' ? $item['booked_signed'] : -$item['booked_signed'];
            return max(0, (int) round(\MyInvoice\Repository\SaldoRepository::settlementAmounts($booked, (float) $item['paid_ratio'])['remaining'] * 100));
        }
        return 0;
    }

    public function verifiedSourceLineIds(int $supplierId): array
    {
        $this->assertTransaction($supplierId);
        $ids = array_keys($this->verifiedEntryIds);
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id IN ({$marks}) ORDER BY id" . $this->lock());
        $stmt->execute([$supplierId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function provisionAfterReversal(int $supplierId, array $record, string $asOf): int
    {
        $invoiceId = (int) $record['invoice_id'];
        $cells = $this->provisionCells($supplierId, $invoiceId, $asOf);
        $stmt = $this->db->pdo()->prepare("SELECT s.payload,p.id AS period_id,p.ends_on FROM accounting_closing_steps s
            JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id
            WHERE s.supplier_id=? AND s.step_key='provisions' AND p.ends_on<=? ORDER BY p.starts_on,p.id" . $this->lock());
        $stmt->execute([$supplierId, $asOf]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $later) {
                if (($later['rule_key'] ?? '') !== 'allowance.receivable.legal.change' || (int) ($later['invoice_id'] ?? 0) !== $invoiceId
                    || (int) $later['entry_id'] <= (int) $record['entry_id']) continue;
                $later['ends_on'] = $step['ends_on'];
                $later['period_id'] = (int) $step['period_id'];
                if ($this->recordActive($supplierId, $later, $asOf)) {
                    throw new ClosingException('legal_closing_source_used', 'Na rozhodnutí navazují další změny opravné položky. Nejprve stornujte navazující změny.', 409);
                }
            }
        }
        $after = array_column($cells, 'available_cents');
        $after = array_combine(array_keys($cells), $after);
        foreach ($record['movement_allocations'] as $cell) {
            $key = LegalClosing504Builder::cellKey($cell);
            $after[$key] = ($after[$key] ?? 0) + ($cell['direction'] === 'create' ? -1 : 1) * ProfitDistribution504Builder::allocationCents($cell['amount']);
            if ($after[$key] < 0) throw self::changed();
        }
        return array_sum($after);
    }

    public function accountCells(int $supplierId, string $accountCode, string $asOf, ?int $excludedEntryId = null): array
    {
        $this->assertTransaction($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT l.id,l.side,l.signed_amount,l.cost_center,l.project_id,a.account_code
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE e.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND a.account_code=?
            AND e.id<>? ORDER BY e.id,l.id' . $this->lock());
        $stmt->execute([$supplierId, $asOf, $accountCode, $excludedEntryId ?? 0]);
        $rows = $this->assignments($supplierId, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $out = [];
        foreach (ProfitDistribution504Builder::cells($rows) as $cell) $out[LegalClosing504Builder::cellKey($cell)] = $cell['available_cents'];
        return $out;
    }

    private function recordActive(int $supplierId, array $record, string $asOf): bool
    {
        if (isset($record['asset_source_evidence'])) {
            $receipt = AssetReserveSourceDecision::verify($record['asset_source_evidence']);
            if (($record['legal_decision']['reserve_source']['source_hash'] ?? null) !== $receipt['source_hash']) throw AssetReserveSourceDecision::changed();
            (new AssetReserveSourceRepository($this->db, $this->currentRead))->assertReceiptCurrent($supplierId, $record['ends_on'], $receipt);
            $expected = AssetReserveSourceDecision::allocations($receipt, ProfitDistribution504Builder::allocationCents($record['amount']));
            if (LegalClosing504Builder::allocations($expected, ProfitDistribution504Builder::allocationCents($record['amount'])) !== $record['legal_decision']['allocations']) throw AssetReserveSourceDecision::changed();
        }
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        if ($record['rule_key'] === 'allowance.receivable.legal.change') {
            LegalClosing504Builder::decision($profile, 'provision.legal', $record['legal_decision'] ?? null, $record['legal_amount'] ?? null, true);
            $lines = LegalClosing504Builder::provisionMovementLines($profile, $record['movement_allocations'] ?? []);
        } else {
            $release = $record['rule_key'] === 'reserve.repairs.release';
            $lines = LegalClosing504Builder::reserve($profile, $record, $release ? array_fill_keys(array_map(
                LegalClosing504Builder::cellKey(...), $record['legal_decision']['allocations']), PHP_INT_MAX) : null)['lines'];
        }
        return $this->verifyRecordedJournal($supplierId, (int) $record['entry_id'], $record['period_id'], $record['ends_on'], $lines, $asOf);
    }

    public function verifyRecordedJournal(int $supplierId, int $entryId, int $periodId, string $date, array $lines, string $asOf): bool
    {
        $this->assertTransaction($supplierId);
        $entry = $this->entry($supplierId, $entryId);
        if ($entry === null || $entry['posted_at'] === null || $entry['source_type'] !== 'manual' || $entry['source_id'] !== null
            || (int) $entry['period_id'] !== $periodId || $entry['entry_date'] !== $date) throw self::changed();
        $expected = $this->resolveAccounts($supplierId, $lines);
        $actual = $this->assignments($supplierId, $entry['lines']);
        if (!JournalLineComparison::sameLines($expected, $actual)) throw self::changed();
        return $this->chainActive($supplierId, $entry, $asOf);
    }

    private function chainActive(int $supplierId, array $entry, string $asOf): bool
    {
        if ($entry['posted_at'] === null) throw self::changed();
        $actual = $this->assignments($supplierId, $entry['lines']);
        $active = true;
        $seen = [(int) $entry['id'] => true];
        $periods = new \MyInvoice\Repository\AccountingPeriodRepository($this->db);
        $frameworks = new AccountingFrameworkService($this->db, new \MyInvoice\Repository\AccountingFrameworkRepository($this->db));
        while ($entry['reversed_by'] !== null) {
            $id = (int) $entry['reversed_by'];
            if (isset($seen[$id]) || count($seen) >= 100) throw self::changed();
            $seen[$id] = true;
            $parents = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND reversed_by=? ORDER BY id' . $this->lock());
            $parents->execute([$supplierId, $id]);
            if (array_map('intval', $parents->fetchAll(PDO::FETCH_COLUMN)) !== [(int) $entry['id']]) throw self::changed();
            $mirror = $this->entry($supplierId, $id);
            if ($mirror === null || $mirror['posted_at'] === null || $mirror['source_id'] !== null
                || $mirror['source_type'] !== $entry['source_type'] || $mirror['entry_date'] < $entry['entry_date']) throw self::changed();
            $period = ($this->currentRead ? $periods->findForUpdate((int) $mirror['period_id'], $supplierId) : $periods->findById($supplierId, (int) $mirror['period_id']));
            if ($period === null || $mirror['entry_date'] < $period['starts_on'] || $mirror['entry_date'] > $period['ends_on']
                || ($this->currentRead ? $frameworks->forDateForUpdate($supplierId, $mirror['entry_date']) : $frameworks->forDate($supplierId, $mirror['entry_date'])) !== AccountingFrameworkService::CZ_504) throw self::changed();
            $mirrorLines = $this->assignments($supplierId, $mirror['lines']);
            if (!JournalLineComparison::sameLines(array_map(JournalLineAmount::reversal(...), $actual), $mirrorLines)) throw self::changed();
            if ($mirror['entry_date'] <= $asOf) $active = !$active;
            $entry = $mirror;
            $actual = $mirrorLines;
        }
        $this->verifiedEntryIds += $seen;
        return $active;
    }

    private function chainRoot(int $supplierId, int $entryId): ?array
    {
        $seen = [];
        while (true) {
            if (isset($seen[$entryId]) || count($seen) >= 100) throw self::changed();
            $seen[$entryId] = true;
            $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND reversed_by=? ORDER BY id' . $this->lock());
            $stmt->execute([$supplierId, $entryId]);
            $parents = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($parents) > 1) throw self::changed();
            if ($parents === []) return $this->entry($supplierId, $entryId);
            $entryId = (int) $parents[0];
        }
    }

    private function assertReserveUsageLinked(int $supplierId, array $origin, string $asOf): void
    {
        $keys = array_fill_keys(array_map(LegalClosing504Builder::cellKey(...), $origin['legal_decision']['allocations']), true);
        $this->assertAccountUsageLinked($supplierId, '941', $origin['ends_on'], (int) $origin['entry_id'], $asOf, $keys);
    }

    private function assertAccountUsageLinked(int $supplierId, string $accountCode, string $fromDate, int $afterEntryId, string $asOf, array $keys): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT l.*,e.entry_date,e.period_id,a.account_code
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE e.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date>=? AND e.entry_date<=? AND e.id>?
            AND a.account_code=? ORDER BY e.id,l.id' . $this->lock());
        $stmt->execute([$supplierId, $fromDate, $asOf, $afterEntryId, $accountCode]);
        foreach ($this->assignments($supplierId, $stmt->fetchAll(PDO::FETCH_ASSOC)) as $line) {
            $id = (int) $line['entry_id'];
            if (isset($this->verifiedEntryIds[$id])) continue;
            $touchesSource = false;
            foreach (ProfitDistribution504Builder::cells([$line]) as $cell) {
                if (isset($keys[LegalClosing504Builder::cellKey($cell)]) && $cell['available_cents'] < 0) $touchesSource = true;
            }
            if (!$touchesSource) continue;
            if ($this->verifiedCarryEntry($supplierId, $id)) continue;
            $entry = $this->chainRoot($supplierId, $id);
            if ($entry === null || $this->chainActive($supplierId, $entry, $asOf)) throw self::changed();
        }
    }

    public function verifiedCarryEntry(int $supplierId, int $entryId): bool
    {
        $this->assertTransaction($supplierId);
        $entry = $this->entry($supplierId, $entryId);
        if ($entry === null || $entry['reversed_by'] !== null || !in_array($entry['source_type'], ['closing', 'opening'], true)) return false;
        $read = new \MyInvoice\Repository\ClosingCarryReadRepository($this->db, $this->currentRead);
        $periods = $read->periods($supplierId);
        $stepKey = $entry['source_type'] === 'closing' ? 'close_books' : 'open_next';
        foreach ($periods as $source) {
            if (!AccountingPeriodStatus::isClosed((string) $source['status'])) continue;
            $step = $read->step($supplierId, (int) $source['id'], $stepKey);
            $receipt = $step['decoded_payload']['carry'][$stepKey] ?? null;
            if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'written' || (int) ($receipt['entry_id'] ?? 0) !== $entryId) continue;
            $target = $periods[(int) ($receipt['target_period_id'] ?? 0)] ?? null;
            if ($target === null || (int) $entry['period_id'] !== (int) $target['id'] || $entry['entry_date'] !== ($receipt['entry_date'] ?? null)
                || (int) $entry['source_id'] !== (int) ($stepKey === 'close_books' ? $source['id'] : $target['id'])
                || ($stepKey === 'close_books' && ((int) $source['id'] !== (int) $target['id'] || $entry['entry_date'] !== $source['ends_on']))
                || ($stepKey === 'open_next' && ($entry['entry_date'] !== $target['starts_on']
                    || $target['starts_on'] !== (new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d')))) return false;
            $framework = new AccountingFrameworkService($this->db, new \MyInvoice\Repository\AccountingFrameworkRepository($this->db));
            if (($this->currentRead ? $framework->forDateForUpdate($supplierId, $source['ends_on']) : $framework->forDate($supplierId, $source['ends_on'])) !== AccountingFrameworkService::CZ_504
                || ($this->currentRead ? $framework->forDateForUpdate($supplierId, $entry['entry_date']) : $framework->forDate($supplierId, $entry['entry_date'])) !== AccountingFrameworkService::CZ_504) return false;
            $snapshot = $read->snapshot($supplierId, $source, $target, $stepKey, $entry['entry_date'], new AccountingRoleProfile(AccountingFrameworkService::CZ_504));
            $expected = $this->resolveAccounts($supplierId, $snapshot['lines']);
            if (!JournalLineComparison::sameLines($expected, $this->assignments($supplierId, $entry['lines']))) return false;
            $this->verifiedEntryIds[$entryId] = true;
            return true;
        }
        return false;
    }

    private function assignments(int $supplierId, array $rows): array
    {
        $repository = new DimensionAssignmentRepository($this->db);
        $ids = array_column($rows, 'id');
        $dimensions = $repository->lineDimensions($supplierId, $ids, $this->currentRead);
        $splits = $repository->lineSplits($supplierId, $ids, $this->currentRead);
        foreach ($rows as &$row) {
            $row['dimensions'] = $dimensions[(int) $row['id']] ?? [];
            $row['dimension_splits'] = $splits[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    private function lock(): string
    {
        return $this->currentRead ? ' FOR UPDATE' : '';
    }

    private function entry(int $supplierId, int $entryId): ?array
    {
        return $this->currentRead ? $this->journal->findForUpdate($entryId, $supplierId) : $this->journal->find($entryId, $supplierId);
    }

    private function assertTransaction(int $supplierId): void
    {
        if ($this->currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
    }

    private static function changed(): ClosingException
    {
        return new ClosingException('legal_closing_source_changed', 'Původní doložený zápis nebo jeho čerpání se změnily. Obnovte podklady a zkontrolujte vazby.', 409);
    }
}
