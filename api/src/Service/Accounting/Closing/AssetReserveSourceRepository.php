<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingFrameworkRepository, DimensionAssignmentRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, JournalLineAmount, JournalLineComparison};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class AssetReserveSourceRepository
{
    public function __construct(private readonly Connection $db, private readonly bool $currentRead = true) {}

    public function evidence(int $supplierId, string $date, array $decision): array
    {
        $receipt = $this->buildEvidence($supplierId, $date, $decision);
        $this->assertClaims($supplierId, $receipt);
        $state = $this->bindings($supplierId)[$receipt['asset_id']] ?? null;
        if ($state !== null && $state['revisions'] !== [] && end($state['revisions'])['effective_on'] > $date) throw AssetReserveSourceDecision::changed();
        return $receipt;
    }

    private function buildEvidence(int $supplierId, string $date, array $decision): array
    {
        $this->lock($supplierId);
        $decision = AssetReserveSourceDecision::normalize($decision, false);
        $asset = $this->one('SELECT * FROM assets WHERE supplier_id=? AND id=?', [$supplierId, $decision['asset_id']]);
        if ($asset === null || !($asset['status'] === 'in_use' || ($asset['status'] === 'disposed' && $asset['disposal_date'] > $date))
            || !$asset['put_into_use_date'] || $asset['put_into_use_date'] > $date) throw AssetReserveSourceDecision::changed();
        $improvements = $this->rows('SELECT id,completed_on,amount FROM asset_improvements WHERE supplier_id=? AND asset_id=? AND completed_on<=? ORDER BY id', [$supplierId, $asset['id'], $date]);
        $gross = JournalLineAmount::cents($asset['input_price']);
        foreach ($improvements as &$improvement) {
            $improvement = ['id' => (int) $improvement['id'], 'completed_on' => $improvement['completed_on'], 'amount_cents' => JournalLineAmount::cents($improvement['amount'])];
            $gross += $improvement['amount_cents'];
        }
        unset($improvement);
        if ($gross <= 0) throw AssetReserveSourceDecision::changed();
        $requests = $decision['association']['contributions'] ?? [];
        if ($decision['mode'] === 'capitalization') {
            $entries = $this->rows("SELECT id,entry_date FROM journal_entries WHERE supplier_id=? AND source_type='asset' AND source_id=? AND posted_at IS NOT NULL ORDER BY id", [$supplierId, $asset['id']]);
            if (count($entries) !== 1 || $entries[0]['entry_date'] !== $asset['put_into_use_date']) throw AssetReserveSourceDecision::changed();
            $rows = $this->rows("SELECT l.id,l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
                WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code=? AND l.side='debit' ORDER BY l.id", [$supplierId, $entries[0]['id'], $asset['asset_account_code']]);
            $requests = array_map(static fn (array $row): array => ['line_id' => (int) $row['id'], 'contribution_cents' => JournalLineAmount::cents($row['amount'])], $rows);
        }
        if ($requests === []) throw AssetReserveSourceDecision::changed();
        $assignment = new DimensionAssignmentRepository($this->db);
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $contributions = $cells = [];
        foreach ($requests as $request) {
            $line = $this->one('SELECT l.*,a.account_code FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND l.id=?', [$supplierId, $request['line_id']]);
            if ($line === null || $line['account_code'] !== $asset['asset_account_code'] || $line['side'] !== 'debit' || !empty($line['is_red_storno'])) throw AssetReserveSourceDecision::changed();
            $entry = $this->one('SELECT * FROM journal_entries WHERE supplier_id=? AND id=?', [$supplierId, $line['entry_id']]);
            if ($entry === null || $entry['posted_at'] === null || $entry['entry_date'] > $date
                || ($entry['source_type'] === 'asset' && (int) $entry['source_id'] !== (int) $asset['id'])) throw AssetReserveSourceDecision::changed();
            if (($this->currentRead ? $framework->forDateForUpdate($supplierId, $entry['entry_date']) : $framework->forDate($supplierId, $entry['entry_date'])) !== AccountingFrameworkService::CZ_504) throw AssetReserveSourceDecision::changed();
            $entrySnapshot = $this->activeEntrySnapshot($supplierId, $entry, $date);
            $amount = JournalLineAmount::cents($line['amount']);
            if ($request['contribution_cents'] <= 0 || $request['contribution_cents'] > $amount) throw AssetReserveSourceDecision::changed();
            $dimensions = $assignment->lineDimensions($supplierId, [(int) $line['id']], $this->currentRead)[$line['id']] ?? [];
            $splits = $assignment->lineSplits($supplierId, [(int) $line['id']], $this->currentRead)[$line['id']] ?? [];
            foreach ($splits as $type => $values) {
                if (count($values) !== 1) throw new ClosingException('system_dimension_allocation_ambiguous', 'Zdroj majetku musí mít doložené společné alokační řádky.', 422);
                $dimensions[$type] = (int) array_key_first($values);
            }
            ksort($dimensions);
            if ($dimensions === []) throw AssetReserveSourceDecision::changed();
            $cell = ['dimensions' => $dimensions, 'cost_center' => $line['cost_center'], 'project_id' => $line['project_id'] === null ? null : (int) $line['project_id']];
            $key = LegalClosing504Builder::cellKey($cell);
            $cells[$key] ??= $cell + ['weight_cents' => 0];
            $cells[$key]['weight_cents'] += $request['contribution_cents'];
            $contributions[] = $request + ['entry_id' => (int) $entry['id'], 'entry_date' => $entry['entry_date'],
                'account_id' => (int) $line['account_id'], 'account_code' => $line['account_code'], 'amount_cents' => $amount,
                'currency_code' => $line['currency_code'], 'amount_foreign' => $line['amount_foreign'], 'dimensions' => $dimensions,
                'cost_center' => $cell['cost_center'], 'project_id' => $cell['project_id'], 'entry_snapshot' => $entrySnapshot];
        }
        if (array_sum(array_column($contributions, 'contribution_cents')) !== $gross) throw AssetReserveSourceDecision::changed();
        ksort($cells);
        usort($contributions, static fn (array $a, array $b): int => $a['line_id'] <=> $b['line_id']);
        $receipt = AssetReserveSourceDecision::seal(['version' => 'asset-reserve-source-v1', 'supplier_id' => $supplierId,
            'asset_id' => (int) $asset['id'], 'put_into_use_date' => $asset['put_into_use_date'], 'asset_account_code' => $asset['asset_account_code'],
            'input_cents' => JournalLineAmount::cents($asset['input_price']), 'gross_cents' => $gross, 'improvements' => $improvements,
            'mode' => $decision['mode'], 'association' => $decision['association'] ?? null,
            'contributions' => $contributions, 'cells' => array_values($cells)]);
        return $receipt;
    }

    public function receipts(int $supplierId): array
    {
        $this->lock($supplierId);
        $out = [];
        foreach ($this->rows("SELECT s.period_id,s.payload,p.ends_on FROM accounting_closing_steps s JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id WHERE s.supplier_id=? AND s.step_key='provisions' ORDER BY s.period_id", [$supplierId]) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                if (!isset($record['asset_source_evidence'])) continue;
                $receipt = AssetReserveSourceDecision::verify($record['asset_source_evidence']);
                if (($receipt['supplier_id'] ?? null) !== $supplierId || ($record['legal_decision']['reserve_source']['source_hash'] ?? null) !== $receipt['source_hash']) throw AssetReserveSourceDecision::changed();
                $out[] = ['receipt' => $receipt, 'record' => $record, 'period_id' => (int) $step['period_id'], 'ends_on' => $step['ends_on']];
            }
        }
        return $out;
    }

    public static function assignmentEvidenceSql(): string
    {
        return "EXISTS (SELECT 1 FROM accounting_closing_steps s
            WHERE s.supplier_id=e.supplier_id AND s.step_key='provisions' AND s.payload IS NOT NULL FOR UPDATE)";
    }

    public function protectedAssignmentEntryIds(int $supplierId): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Assignment source proof requires caller transaction.');
        $statement = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND step_key='provisions' ORDER BY period_id FOR UPDATE");
        $statement->execute([$supplierId]);
        $ids = [];
        $records = [];
        $superseded = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $payload = json_decode((string) ($json ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_merge($payload['entries'] ?? [], $payload['reversed'] ?? []) as $record) {
                $records[] = $record;
                if (!isset($record['asset_source_evidence'])) continue;
                $receipt = AssetReserveSourceDecision::verify($record['asset_source_evidence']);
                if ($receipt['supplier_id'] !== $supplierId || ($record['legal_decision']['reserve_source']['source_hash'] ?? null) !== $receipt['source_hash']) throw AssetReserveSourceDecision::changed();
                foreach ($receipt['contributions'] as $line) $ids[$line['entry_id']] = true;
            }
            foreach ($payload['asset_source_revisions'] ?? [] as $revision) {
                AssetReserveSourceDecision::verifyRevision($revision);
                if ($revision['source_evidence']['supplier_id'] !== $supplierId) throw AssetReserveSourceDecision::changed();
                foreach (['source_evidence', 'previous_source_evidence'] as $key) {
                    foreach ($revision[$key]['contributions'] as $line) $ids[$line['entry_id']] = true;
                }
                if ($revision['version'] !== 'asset-reserve-revision-v2') { $superseded[$revision['previous_hash']] = true; continue; }
                foreach ($revision['pinned_entries'] as $entry) $ids[$entry['id']] = true;
                if ($revision['entry_id'] !== null) $ids[$revision['entry_id']] = true;
            }
        }
        $roots = [];
        foreach ($records as $record) {
            if (isset($superseded[$record['asset_source_evidence']['source_hash'] ?? ''])) $roots[(int) $record['entry_id']] = true;
        }
        $pending = $roots;
        foreach ($records as $record) {
            if (isset($roots[(int) ($record['legal_decision']['source_entry_id'] ?? 0)])) $pending[(int) $record['entry_id']] = true;
        }
        $seen = [];
        for ($depth = 0; $pending !== []; $depth++) {
            if ($depth >= 100 || array_intersect_key($pending, $seen) !== []) throw AssetReserveSourceDecision::changed();
            $seen += $pending;
            $marks = implode(',', array_fill(0, count($pending), '?'));
            $current = $this->db->pdo()->prepare("SELECT id,reversed_by FROM journal_entries WHERE supplier_id=? AND id IN ($marks) ORDER BY id FOR UPDATE");
            $current->execute([$supplierId, ...array_keys($pending)]);
            $rows = $current->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($pending)) throw AssetReserveSourceDecision::changed();
            $pending = [];
            foreach ($rows as $row) {
                $ids[(int) $row['id']] = true;
                if ($row['reversed_by'] !== null) $pending[(int) $row['reversed_by']] = true;
            }
        }
        return $ids;
    }

    public function assertReceiptCurrent(int $supplierId, string $date, array $receipt): void
    {
        AssetReserveSourceDecision::verify($receipt);
        $decision = ['asset_id' => $receipt['asset_id'], 'mode' => $receipt['mode'], 'allocation_method' => 'capitalization_joint_proportional',
            'continued_use_evidence' => 'current source verification', 'association' => $receipt['association']];
        $current = $this->buildEvidence($supplierId, $date, $decision);
        $state = $this->bindings($supplierId)[$receipt['asset_id']] ?? null;
        if ($state === null || !isset($state['authorized'][$receipt['source_hash']])) throw AssetReserveSourceDecision::changed();
        if (!hash_equals($receipt['source_hash'], $current['source_hash'])) throw AssetReserveSourceDecision::changed();
    }

    private function activeEntrySnapshot(int $supplierId, array $entry, string $date): array
    {
        $this->assertEntryPeriod($supplierId, $entry);
        $lines = $this->entryLines($supplierId, (int) $entry['id']);
        $total = 0;
        foreach ($lines as $line) $total += JournalLineAmount::signedCents($line) * ($line['side'] === 'debit' ? 1 : -1);
        if ($lines === [] || $total !== 0) throw AssetReserveSourceDecision::changed();
        $snapshot = array_intersect_key($entry, array_flip(['id', 'period_id', 'entry_date', 'source_type', 'source_id'])) + ['lines' => $lines];
        $seen = [(int) $entry['id'] => true];
        $active = true;
        while ($entry['reversed_by'] !== null) {
            $id = (int) $entry['reversed_by'];
            if (isset($seen[$id]) || count($seen) >= 100) throw AssetReserveSourceDecision::changed();
            $seen[$id] = true;
            $parents = $this->rows('SELECT id FROM journal_entries WHERE supplier_id=? AND reversed_by=? ORDER BY id', [$supplierId, $id]);
            if (array_map('intval', array_column($parents, 'id')) !== [(int) $entry['id']]) throw AssetReserveSourceDecision::changed();
            $mirror = $this->one('SELECT * FROM journal_entries WHERE supplier_id=? AND id=?', [$supplierId, $id]);
            if ($mirror === null || $mirror['posted_at'] === null || $mirror['source_id'] !== null
                || $mirror['source_type'] !== $entry['source_type'] || $mirror['entry_date'] < $entry['entry_date']) throw AssetReserveSourceDecision::changed();
            $this->assertEntryPeriod($supplierId, $mirror);
            $mirrorLines = $this->entryLines($supplierId, $id);
            if (!JournalLineComparison::sameLines(array_map(JournalLineAmount::reversal(...), $lines), $mirrorLines)) throw AssetReserveSourceDecision::changed();
            if ($mirror['entry_date'] <= $date) $active = !$active;
            $entry = $mirror;
            $lines = $mirrorLines;
        }
        if (!$active) throw AssetReserveSourceDecision::changed();
        return $snapshot;
    }

    private function assertEntryPeriod(int $supplierId, array $entry): void
    {
        $period = $this->one('SELECT starts_on,ends_on FROM accounting_periods WHERE supplier_id=? AND id=?', [$supplierId, $entry['period_id']]);
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        if ($period === null || $entry['entry_date'] < $period['starts_on'] || $entry['entry_date'] > $period['ends_on']
            || ($this->currentRead ? $framework->forDateForUpdate($supplierId, $entry['entry_date']) : $framework->forDate($supplierId, $entry['entry_date'])) !== AccountingFrameworkService::CZ_504) throw AssetReserveSourceDecision::changed();
    }

    private function entryLines(int $supplierId, int $entryId): array
    {
        $rows = $this->rows('SELECT * FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id', [$supplierId, $entryId]);
        $ids = array_column($rows, 'id');
        $assignment = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignment->lineDimensions($supplierId, $ids, $this->currentRead);
        $splits = $assignment->lineSplits($supplierId, $ids, $this->currentRead);
        foreach ($rows as &$line) {
            $line = array_intersect_key($line, array_flip(['account_id', 'side', 'amount', 'is_red_storno', 'currency_code', 'amount_foreign', 'fx_rate', 'cost_center', 'project_id']))
                + ['dimensions' => $dimensions[$line['id']] ?? [], 'dimension_splits' => $splits[$line['id']] ?? []];
        }
        unset($line);
        return $rows;
    }

    public function bindings(int $supplierId): array
    {
        $states = [];
        $records = $this->receipts($supplierId);
        foreach ($records as $stored) {
            $receipt = $stored['receipt'];
            $states[$receipt['asset_id']]['records'][] = $stored;
        }
        foreach ($this->rows("SELECT s.period_id,s.payload,p.ends_on FROM accounting_closing_steps s JOIN accounting_periods p ON p.id=s.period_id AND p.supplier_id=s.supplier_id WHERE s.supplier_id=? AND s.step_key='provisions' ORDER BY s.period_id", [$supplierId]) as $step) {
            $payload = json_decode((string) ($step['payload'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($payload['asset_source_revisions'] ?? [] as $revision) {
                AssetReserveSourceDecision::verifyRevision($revision);
                $receipt = $revision['source_evidence'];
                if ($receipt['supplier_id'] !== $supplierId || ($revision['period_id'] ?? null) !== (int) $step['period_id'] || ($revision['effective_on'] ?? null) !== $step['ends_on']) throw AssetReserveSourceDecision::changed();
                $states[$receipt['asset_id']]['revisions'][] = $revision;
            }
        }
        foreach ($states as &$state) {
            $state += ['records' => [], 'revisions' => []];
            usort($state['records'], static fn (array $a, array $b): int => $a['record']['entry_id'] <=> $b['record']['entry_id']);
            usort($state['revisions'], static fn (array $a, array $b): int => $a['revision_number'] <=> $b['revision_number']);
            if ($state['records'] === []) throw AssetReserveSourceDecision::changed();
            $state['current'] = $state['records'][0]['receipt'];
            $state['authorized'] = [$state['current']['source_hash'] => true];
            $effective = $state['records'][0]['ends_on'];
            foreach ($state['revisions'] as $index => $revision) {
                if ($revision['effective_on'] < $effective) throw AssetReserveSourceDecision::changed();
                $effective = $revision['effective_on'];
                if ($revision['revision_number'] !== $index + 1 || $revision['previous_source_evidence'] !== $state['current']) throw AssetReserveSourceDecision::changed();
                $state['current'] = $revision['source_evidence'];
                if ($revision['version'] !== 'asset-reserve-revision-v2' && isset($state['authorized'][$state['current']['source_hash']])) throw AssetReserveSourceDecision::changed();
                $state['authorized'][$state['current']['source_hash']] = true;
            }
            foreach ($state['records'] as $record) if (!isset($state['authorized'][$record['receipt']['source_hash']])) throw AssetReserveSourceDecision::changed();
        }
        unset($state);
        return $states;
    }

    public function protectedReceipts(int $supplierId): array
    {
        $out = $this->receipts($supplierId);
        foreach ($this->bindings($supplierId) as $state) foreach ($state['revisions'] as $revision) {
            $out[] = ['receipt' => $revision['source_evidence'], 'record' => null, 'period_id' => $revision['period_id'], 'ends_on' => $revision['source_date'] ?? $revision['effective_on']];
        }
        return $out;
    }

    public function sourceState(int $supplierId, int $assetId, string $asOf): array
    {
        $state = $this->bindings($supplierId)[$assetId] ?? null;
        if ($state === null) return ['current' => null, 'revisions' => [], 'blocking_entries' => []];
        $legal = new LegalClosing504Repository($this->db, new \MyInvoice\Repository\JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db)), $this->currentRead);
        $blocking = [];
        foreach ($state['records'] as $stored) {
            $record = $stored['record'];
            $this->assertReceiptCurrent($supplierId, $stored['ends_on'], $stored['receipt']);
            $lines = LegalClosing504Builder::reserve(new \MyInvoice\Service\Accounting\AccountingRoleProfile(AccountingFrameworkService::CZ_504), $record)['lines'];
            if ($legal->verifyRecordedJournal($supplierId, (int) $record['entry_id'], $stored['period_id'], $stored['ends_on'], $lines, $asOf)) {
                $blocking[] = ['entry_id' => (int) $record['entry_id'], 'period_id' => $stored['period_id'], 'entry_date' => $stored['ends_on']];
            }
        }
        return ['current' => $state['current'], 'revisions' => $state['revisions'], 'blocking_entries' => $blocking];
    }

    public function correctionEvidence(int $supplierId, string $asOf, array $body): array
    {
        $decision = AssetReserveSourceDecision::correction($body);
        $state = $this->sourceState($supplierId, $decision['reserve_source']['asset_id'], $asOf);
        if ($state['current'] === null || $state['current']['source_hash'] !== $decision['previous_hash']) throw AssetReserveSourceDecision::changed();
        if (($body['mode'] ?? null) === 'prospective_closed_history') return $this->prospectiveCorrection($supplierId, $asOf, $body, $decision, $state);
        if ($state['blocking_entries'] !== []) throw new ClosingException('asset_reserve_correction_used', 'Nejprve doloženě stornujte navazující čerpání a tvorbu rezervy. Uzavřenou historii nepřepisujte.', 409);
        $receipt = $this->buildEvidence($supplierId, $asOf, $decision['reserve_source']);
        if ($receipt['source_hash'] === $decision['previous_hash']) throw AssetReserveSourceDecision::invalid();
        $this->assertClaims($supplierId, $receipt, $decision['previous_hash']);
        return ['source' => $receipt, 'previous' => $state['current'], 'revision_number' => count($state['revisions']) + 1, 'reason' => $decision['reason']];
    }

    private function prospectiveCorrection(int $supplierId, string $asOf, array $body, array $decision, array $state): array
    {
        $this->lock($supplierId);
        $purpose = AssetReserveSourceDecision::purpose($body['original_purpose'] ?? null);
        $binding = $this->bindings($supplierId)[$decision['reserve_source']['asset_id']];
        $firstDate = min(array_column($binding['records'], 'ends_on'));
        if ($purpose['document_date'] > $firstDate || $asOf <= $firstDate) throw AssetReserveSourceDecision::invalid();
        $source = $this->buildEvidence($supplierId, $firstDate, $decision['reserve_source']);
        if ($source['source_hash'] === $decision['previous_hash']) throw AssetReserveSourceDecision::invalid();
        $this->assertClaims($supplierId, $source, $decision['previous_hash']);
        foreach ($this->bindings($supplierId) as $assetId => $other) {
            if ($assetId === $source['asset_id']) continue;
            if (array_intersect(array_column($source['contributions'], 'line_id'), array_column($other['current']['contributions'], 'line_id')) !== []) throw new ClosingException('asset_reserve_source_claimed', 'Vybraná část zdrojového zápisu je již doložena jinému majetku.', 409);
        }
        $journal = new \MyInvoice\Repository\JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db));
        $legal = new LegalClosing504Repository($this->db, $journal, $this->currentRead);
        $transfers = [];
        $pinned = [];
        $total = 0;
        foreach ($state['blocking_entries'] as $entry) {
            $snapshot = $legal->reserveSnapshot($supplierId, $entry['entry_id'], $asOf);
            $before = [];
            foreach ($snapshot['remaining'] as $key => $cents) {
                if ($cents <= 0) continue;
                $cell = $snapshot['cells'][$key];
                $cell['amount'] = AssetReserveSourceDecision::decimal($cents);
                $before[] = $cell;
            }
            $amount = array_sum($snapshot['remaining']);
            $transfers[] = ['source_entry_id' => $entry['entry_id'], 'before' => $before,
                'after' => $amount === 0 ? [] : AssetReserveSourceDecision::allocations($source, $amount)];
            $total += $amount;
            foreach ($snapshot['verified_entry_ids'] as $id) $pinned[$id] = $legal->entryEvidence($supplierId, $id);
        }
        foreach ($this->rows("SELECT DISTINCT e.id FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE e.supplier_id=? AND e.posted_at IS NOT NULL AND e.entry_date>=? AND e.entry_date<=? AND e.source_type IN ('closing','opening') AND a.account_code='941' ORDER BY e.id", [$supplierId, $firstDate, $asOf]) as $entry) {
            if (!$legal->verifiedCarryEntry($supplierId, (int) $entry['id'])) throw AssetReserveSourceDecision::changed();
            $pinned[(int) $entry['id']] = $legal->entryEvidence($supplierId, (int) $entry['id']);
        }
        foreach ([$state['current'], $source] as $receipt) foreach ($receipt['contributions'] as $contribution) {
            $pinned[$contribution['entry_id']] = $legal->entryEvidence($supplierId, $contribution['entry_id']);
        }
        ksort($pinned);
        return ['mode' => 'prospective_closed_history', 'effective_on' => $asOf, 'source' => $source, 'previous' => $state['current'],
            'revision_number' => count($state['revisions']) + 1, 'reason' => $decision['reason'], 'original_purpose' => $purpose,
            'source_date' => $firstDate, 'previous_source_date' => ($state['revisions'] === [] ? $firstDate : (end($state['revisions'])['source_date'] ?? end($state['revisions'])['effective_on'])),
            'transfers' => $transfers, 'remaining_cents' => $total, 'pinned_entries' => array_values($pinned),
            'lines' => AssetReserveCorrection504Builder::lines($transfers, $source)];
    }

    private function assertClaims(int $supplierId, array $candidate, ?string $previousHash = null): void
    {
        $states = $this->bindings($supplierId);
        $current = $states[$candidate['asset_id']]['current'] ?? null;
        if ($current !== null && $current['source_hash'] !== ($previousHash ?? $candidate['source_hash'])) throw AssetReserveSourceDecision::changed();
        $claims = [];
        $retain = static function (array $receipt) use (&$claims): void {
            foreach ($receipt['contributions'] as $contribution) {
                $assetId = $receipt['asset_id'];
                $lineId = $contribution['line_id'];
                $claims[$assetId][$lineId] = max($claims[$assetId][$lineId] ?? 0, $contribution['contribution_cents']);
            }
        };
        $legal = new LegalClosing504Repository($this->db, new \MyInvoice\Repository\JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db)), $this->currentRead);
        foreach ($states as $state) {
            $released = [];
            if (!in_array('asset-reserve-revision-v2', array_column($state['revisions'], 'version'), true)) {
                foreach ($state['revisions'] as $revision) {
                    $hash = $revision['previous_hash'];
                    $records = array_filter($state['records'], static fn (array $stored): bool => $stored['receipt']['source_hash'] === $hash);
                    $neutral = $records !== [];
                    foreach ($records as $stored) {
                        if (!$legal->reserveFullyReversedInPeriod($supplierId, (int) $stored['record']['entry_id'], $revision['period_id'])) $neutral = false;
                    }
                    if ($neutral) $released[$hash] = true;
                }
            }
            $retain($state['current']);
            foreach ($state['records'] as $stored) if (!isset($released[$stored['receipt']['source_hash']])) $retain($stored['receipt']);
            foreach ($state['revisions'] as $revision) if (!isset($released[$revision['source_evidence']['source_hash']])) $retain($revision['source_evidence']);
        }
        $retain($candidate);
        foreach ($candidate['contributions'] as $contribution) {
            if (array_sum(array_column($claims, $contribution['line_id'])) > $contribution['amount_cents']) throw new ClosingException('asset_reserve_source_claimed', 'Vybraná část zdrojového zápisu je již doložena jinému majetku.', 409);
        }
    }

    private function lock(int $supplierId): void
    {
        if ($this->currentRead) {
            if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Asset source current read requires caller transaction.');
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        }
    }

    private function rows(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql . ($this->currentRead ? ' FOR UPDATE' : ''));
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $params): ?array
    {
        return $this->rows($sql, $params)[0] ?? null;
    }
}
