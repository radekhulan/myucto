<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JointAllocationReadRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\NonprofitTaxSourceFactRegistry;
use PDO;

final class NonprofitTaxSnapshotBatchReader
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingFrameworkService $frameworks,
        private readonly JointAllocationReadRepository $joint,
        private readonly NonprofitTaxSourceFactRegistry $facts,
    ) {}

    public function header(int $supplierId, int $periodId, bool $currentRead): SnapshotHeaderV2
    {
        $period = $this->period($supplierId, $periodId, $currentRead);
        $frameworks = $this->frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead);
        if (array_values(array_unique($frameworks)) !== [AccountingFrameworkService::CZ_504]) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_framework_unsupported', 'Podklad pro neziskové organizace vyžaduje rámec 504 v celém období.');
        }
        unset($period['status']);
        return new SnapshotHeaderV2([
            'schema' => SnapshotHeaderV2::SCHEMA,
            'scope' => 'current-period-source',
            'supplier_id' => $supplierId,
            'period_id' => $periodId,
            'period' => $period,
            'frameworks' => $frameworks,
            'ruleset' => NonprofitTaxRules::VERSION,
            'funding_history_mode' => 'paged_assessment_graph_v1',
            'source_fact_schemas' => $this->facts->schemas(),
        ]);
    }

    private const int HEAD_BATCH = 200;
    private const string LINE_COLUMNS = 'l.id,l.entry_id,l.line_no,l.account_id,l.side,l.signed_amount,l.amount_foreign,l.currency_code,l.fx_rate,
        e.period_id,e.entry_date,e.source_type,e.source_id,e.reversed_by';

    /**
     * Řádky deníku období v pořadí (entry_date, entry_id, line_id) keyset stránkováním
     * přímo nad deníkem: hlavičky přes idx_je_supplier_entry_date, řádky jen vybraných
     * dokladů přes idx_jel_supplier_entry. Žádná pomocná kopie deníku.
     *
     * @return list<array<string,mixed>>
     */
    private function scanLines(int $supplierId, int $periodId, array $period, ?SnapshotCursor $after, int $take, bool $currentRead): array
    {
        $pdo = $this->db->pdo();
        $lock = $this->lock($currentRead);
        $endsBefore = (new \DateTimeImmutable($period['ends_on']))->modify('+1 day')->format('Y-m-d');
        $scanned = [];
        if ($after !== null) {
            // Dokončení rozpracovaného dokladu z předchozí stránky.
            $stmt = $pdo->prepare('SELECT ' . self::LINE_COLUMNS . '
                FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
                WHERE e.supplier_id=? AND e.id=? AND e.period_id=? AND e.entry_date=? AND e.posted_at IS NOT NULL AND l.id>?
                ORDER BY l.id LIMIT ' . $take . $lock);
            $stmt->execute([$supplierId, $after->entryId, $periodId, $after->entryDate, $after->lineId]);
            $scanned = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $afterDate = $after?->entryDate;
        $afterEntry = $after?->entryId;
        while (count($scanned) < $take) {
            $params = [$supplierId, $period['starts_on'], $endsBefore, $periodId];
            $keyset = '';
            if ($afterDate !== null) {
                $keyset = ' AND (e.entry_date>? OR (e.entry_date=? AND e.id>?))';
                array_push($params, $afterDate, $afterDate, $afterEntry);
            }
            $heads = $pdo->prepare('SELECT e.id,e.entry_date FROM journal_entries e FORCE INDEX (idx_je_supplier_entry_date)
                WHERE e.supplier_id=? AND e.entry_date>=? AND e.entry_date<? AND e.period_id=? AND e.posted_at IS NOT NULL' . $keyset . '
                ORDER BY e.entry_date,e.id LIMIT ' . self::HEAD_BATCH . $lock);
            $heads->execute($params);
            $entries = $heads->fetchAll(PDO::FETCH_ASSOC);
            if ($entries === []) break;
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $entries);
            $lines = $pdo->prepare('SELECT STRAIGHT_JOIN ' . self::LINE_COLUMNS . '
                FROM journal_entry_lines l FORCE INDEX (idx_jel_supplier_entry)
                JOIN journal_entries e FORCE INDEX (uq_je_supplier_id) ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
                WHERE l.supplier_id=? AND l.entry_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                  AND e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                ORDER BY e.entry_date,l.entry_id,l.id LIMIT ' . ($take - count($scanned)) . $lock);
            $lines->execute([$supplierId, ...$ids, ...$ids]);
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $row) $scanned[] = $row;
            $last = $entries[array_key_last($entries)];
            $afterDate = $last['entry_date'];
            $afterEntry = (int) $last['id'];
            if (count($entries) < self::HEAD_BATCH) break;
        }
        return $scanned;
    }

    public function page(int $supplierId, int $periodId, ?SnapshotCursor $after = null, int $limit = 500, bool $currentRead = false): SnapshotPageV2
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Snapshot page limit must be between 1 and 500.');
        $period = $this->period($supplierId, $periodId, $currentRead);
        if ($after !== null && ($after->entryDate < $period['starts_on'] || $after->entryDate > $period['ends_on'])) {
            throw new \InvalidArgumentException('Snapshot cursor does not belong to the requested period.');
        }
        $scanned = $this->scanLines($supplierId, $periodId, $period, $after, $limit + 1, $currentRead);
        $hasMore = count($scanned) > $limit;
        if ($hasMore) array_pop($scanned);
        if ($scanned === []) return new SnapshotPageV2([], [], null, null, null, 0);
        $firstCursor = $this->cursor($scanned[0]);
        $lastCursor = $this->cursor($scanned[array_key_last($scanned)]);
        $nextCursor = $hasMore ? $lastCursor : null;
        $accounts = $this->accounts($supplierId, $scanned, $currentRead);
        foreach ($scanned as &$row) {
            $account = $accounts[(int) $row['account_id']] ?? null;
            if ($account === null) throw new NonprofitTaxAssessmentException('nonprofit_tax_account_changed', 'Účet zdrojového řádku není dohledatelný.', 409);
            $row += $account;
        }
        unset($row);
        $taxRows = array_values(array_filter($scanned, static fn (array $row): bool => (str_starts_with($row['account_code'], '5')
            || str_starts_with($row['account_code'], '6')) && !str_starts_with($row['account_code'], '59')));
        if ($taxRows === []) return new SnapshotPageV2([], [], $firstCursor, $lastCursor, $nextCursor, count($scanned));
        $origins = $this->journalOrigins($supplierId, $taxRows, $currentRead);
        $rows = [];
        foreach ($taxRows as $raw) {
            $origin = $origins[(int) $raw['entry_id']];
            if ($origin['source_type'] === 'closing' && ($origin['source_id'] === null || $origin['source_id'] < ClosingSourceId::STOCK_SLOT_BASE)) continue;
            $raw['origin_type'] = $origin['source_type'];
            $raw['origin_id'] = $origin['source_id'];
            $rows[(int) $raw['id']] = $raw;
        }
        if ($rows === []) return new SnapshotPageV2([], [], $firstCursor, $lastCursor, $nextCursor, count($scanned));
        $this->applyAccountDefinitions($supplierId, $rows, $currentRead);
        if ($rows === []) return new SnapshotPageV2([], [], $firstCursor, $lastCursor, $nextCursor, count($scanned));
        $dimensions = $this->dimensions($supplierId, array_keys($rows), $currentRead);
        $batchIds = $this->pageBatchIds($supplierId, $rows, $period, $currentRead);
        $projections = $this->projections($supplierId, $batchIds, $currentRead);
        $carrier = $this->carrierMap($projections);
        $ultimateIds = $this->ultimateSourceIds($supplierId, $rows, $carrier, $projections, $currentRead);
        $originLines = $this->ledgerLinesByIds($supplierId, array_values(array_unique($ultimateIds)), $currentRead);
        $allTaxRows = $rows + $originLines;
        $purchaseDeductibility = $this->purchaseDeductibility($supplierId, $allTaxRows, $currentRead);
        $neutral = $this->neutralLines($supplierId, $period, $projections, $currentRead);
        $out = [];
        $findings = [];
        foreach ($rows as $id => $row) {
            $kind = str_starts_with($row['account_code'], '6') ? 'revenue' : 'expense';
            $amount = self::cents($row['signed_amount']) * (($row['side'] === 'credit') === ($kind === 'revenue') ? 1 : -1);
            $projection = $carrier[$id] ?? null;
            $axes = $projection['axes'] ?? ($dimensions[$id]['axes'] ?? []);
            $provenance = $projection ?? ($dimensions[$id] ?? []);
            $ultimateId = $ultimateIds[$id];
            $ultimate = $originLines[$ultimateId] ?? $row;
            $mandatory = $this->mandatoryNonDeductible($row, $purchaseDeductibility)
                || $this->mandatoryNonDeductible($ultimate, $purchaseDeductibility);
            if (!isset($neutral[$id])) {
                foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) {
                    if (!isset($axes[$axis])) $findings[] = ['line_id' => $id, 'code' => 'missing_' . $axis];
                }
                if (isset($axes['activity']) && !in_array($axes['activity']['semantic_key'], ['main', 'economic'], true)) $findings[] = ['line_id' => $id, 'code' => 'invalid_activity'];
                if (isset($axes['tax_regime']) && !in_array($axes['tax_regime']['semantic_key'], ['subject', 'out_of_scope', 'exempt'], true)) $findings[] = ['line_id' => $id, 'code' => 'invalid_tax_regime'];
                if ($projection === null) {
                    foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) {
                        if (($axes[$axis]['assignment_origin'] ?? 'unknown') === 'unknown') $findings[] = ['line_id' => $id, 'code' => 'unknown_' . $axis . '_origin'];
                    }
                    if (!empty($dimensions[$id]['splits'])) $findings[] = ['line_id' => $id, 'code' => 'joint_allocation_required'];
                } else {
                    $projectionChanged = !empty($dimensions[$id]['splits']);
                    foreach ($axes as $axis => $value) {
                        $actual = $dimensions[$id]['axes'][$axis] ?? [];
                        if (($actual['value_id'] ?? null) !== $value['value_id'] || ($actual['type_id'] ?? null) !== $value['type_id']
                            || ($actual['assignment_origin'] ?? null) !== 'module'
                            || ($actual['assignment_origin_ref'] ?? null) !== 'allocation:' . $projection['batch_id']) {
                            $projectionChanged = true;
                        }
                    }
                    if ($projectionChanged) $findings[] = ['line_id' => $id, 'code' => 'joint_dimensions_changed'];
                }
            }
            $out[] = ['id' => 'line:' . $id, 'line_id' => $id, 'entry_id' => (int) $row['entry_id'], 'line_no' => (int) $row['line_no'],
                'account_id' => (int) $row['account_id'], 'account_code' => $row['account_code'], 'date' => $row['entry_date'],
                'kind' => $kind, 'amount_cents' => $amount, 'activity' => $axes['activity']['semantic_key'] ?? null,
                'activity_kind_id' => isset($axes['activity_kind']) ? (int) $axes['activity_kind']['value_id'] : null,
                'tax_regime' => $axes['tax_regime']['semantic_key'] ?? null, 'mandatory_non_deductible' => $mandatory,
                'neutral' => isset($neutral[$id]), 'axes' => $axes, 'provenance' => $provenance,
                'origin' => ['type' => $ultimate['origin_type'], 'id' => $ultimate['origin_id'], 'line_id' => $ultimateId],
                'source_type' => $row['source_type'], 'source_id' => $row['source_id'] === null ? null : (int) $row['source_id'],
                'allocation' => $projection, 'module_facts' => []];
        }
        $facts = $this->facts->capture($supplierId, $out, $currentRead);
        foreach ($out as &$row) $row['module_facts'] = $facts[$row['line_id']] ?? [];
        unset($row);
        return new SnapshotPageV2($out, $findings, $firstCursor, $lastCursor, $nextCursor, count($scanned));
    }

    private function period(int $supplierId, int $periodId, bool $currentRead): array
    {
        if ($currentRead) {
            if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Current snapshot reads require a transaction.');
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $stmt = $this->db->pdo()->prepare('SELECT id,fiscal_year,starts_on,ends_on,status FROM accounting_periods WHERE supplier_id=? AND id=?' . $this->lock($currentRead));
        $stmt->execute([$supplierId, $periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($period === false) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        foreach (['id', 'fiscal_year'] as $field) $period[$field] = (int) $period[$field];
        return $period;
    }

    private function cursor(array $row): SnapshotCursor
    {
        return new SnapshotCursor($row['entry_date'], (int) $row['entry_id'], (int) $row['id']);
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array{account_code:string,account_type:string,base_tax_deductibility:string}> */
    private function accounts(int $supplierId, array $rows, bool $currentRead): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['account_id'], $rows)));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id,account_code,account_type,tax_deductibility AS base_tax_deductibility
            FROM chart_of_accounts WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            unset($row['id']);
            $out[$id] = $row;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array{source_type:string,source_id:?int}> */
    private function journalOrigins(int $supplierId, array $rows, bool $currentRead): array
    {
        $headers = [];
        foreach ($rows as $row) $headers[(int) $row['entry_id']] = ['id' => (int) $row['entry_id'], 'source_type' => $row['source_type'],
            'source_id' => $row['source_id'] === null ? null : (int) $row['source_id'], 'reversed_by' => $row['reversed_by'] === null ? null : (int) $row['reversed_by']];
        $parents = [];
        $frontier = array_keys($headers);
        for ($depth = 0; $frontier !== []; ++$depth) {
            if ($depth >= 64) throw new NonprofitTaxAssessmentException('nonprofit_tax_origin_cycle', 'Původ účetního zápisu obsahuje příliš hluboký nebo cyklický řetězec.', 409);
            $marks = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id,source_type,source_id,reversed_by FROM journal_entries
                WHERE supplier_id=? AND reversed_by IN ({$marks}) ORDER BY id" . $this->lock($currentRead));
            $stmt->execute([$supplierId, ...$frontier]);
            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $parent) {
                $child = (int) $parent['reversed_by'];
                if (isset($parents[$child])) throw new NonprofitTaxAssessmentException('nonprofit_tax_origin_ambiguous', 'Původ účetního zápisu není jednoznačný.', 409);
                $parent['id'] = (int) $parent['id'];
                $parent['source_id'] = $parent['source_id'] === null ? null : (int) $parent['source_id'];
                $parent['reversed_by'] = $parent['reversed_by'] === null ? null : (int) $parent['reversed_by'];
                $parents[$child] = $parent['id'];
                $headers[$parent['id']] = $parent;
                $next[] = $parent['id'];
            }
            $frontier = $next;
        }
        $out = [];
        foreach ($rows as $row) {
            $target = (int) $row['entry_id'];
            $seen = [];
            $root = $target;
            while (isset($parents[$root])) {
                if (isset($seen[$root])) throw new NonprofitTaxAssessmentException('nonprofit_tax_origin_cycle', 'Původ účetního zápisu obsahuje cyklus.', 409);
                $seen[$root] = true;
                $root = $parents[$root];
            }
            $out[$target] = ['source_type' => $headers[$root]['source_type'], 'source_id' => $headers[$root]['source_id']];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function applyAccountDefinitions(int $supplierId, array &$rows, bool $currentRead): void
    {
        $accountIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['account_id'], $rows)));
        $marks = implode(',', array_fill(0, count($accountIds), '?'));
        $maxDate = max(array_column($rows, 'entry_date'));
        $stmt = $this->db->pdo()->prepare("SELECT * FROM accounting_account_definition_history
            WHERE supplier_id=? AND account_id IN ({$marks}) AND effective_from<=? ORDER BY account_id,effective_from,id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$accountIds, $maxDate]);
        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $definition) $history[(int) $definition['account_id']][] = $definition;
        foreach ($rows as $id => &$row) {
            $chosen = null;
            foreach ($history[(int) $row['account_id']] ?? [] as $definition) if ($definition['effective_from'] <= $row['entry_date']) $chosen = $definition;
            if ($chosen !== null) $row['tax_deductibility'] = $chosen['tax_deductibility'];
            elseif (isset($history[(int) $row['account_id']])) unset($rows[$id]);
            else $row['tax_deductibility'] = $row['base_tax_deductibility'];
            unset($row['base_tax_deductibility']);
        }
        unset($row);
    }

    /** @param list<int> $lineIds @return array<int,array<string,mixed>> */
    private function dimensions(int $supplierId, array $lineIds, bool $currentRead): array
    {
        if ($lineIds === []) return [];
        $marks = implode(',', array_fill(0, count($lineIds), '?'));
        $out = [];
        foreach (['journal_entry_line_dimensions' => false, 'journal_entry_line_dimension_splits' => true] as $table => $split) {
            $stmt = $this->db->pdo()->prepare("SELECT d.*,t.system_key,v.semantic_key,v.code,v.name FROM {$table} d
                JOIN dimension_types t ON t.supplier_id=d.supplier_id AND t.id=d.dimension_type_id
                JOIN dimension_values v ON v.supplier_id=d.supplier_id AND v.type_id=t.id AND v.id=d.dimension_value_id
                WHERE d.supplier_id=? AND d.line_id IN ({$marks}) AND t.system_key IN ('activity','activity_kind','tax_regime')
                ORDER BY d.line_id,d.dimension_type_id,d.dimension_value_id" . $this->lock($currentRead));
            $stmt->execute([$supplierId, ...$lineIds]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $axis = ['type_id' => (int) $row['dimension_type_id'], 'value_id' => (int) $row['dimension_value_id'],
                    'semantic_key' => $row['semantic_key'], 'code' => $row['code'], 'name' => $row['name'],
                    'assignment_origin' => $row['assignment_origin'], 'assignment_origin_ref' => $row['assignment_origin_ref']];
                if ($split) $out[(int) $row['line_id']]['splits'][] = $row;
                else $out[(int) $row['line_id']]['axes'][$row['system_key']] = $axis;
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows @return list<int> */
    private function pageBatchIds(int $supplierId, array $rows, array $period, bool $currentRead): array
    {
        $entryIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['entry_id'], $rows)));
        $lineIds = array_keys($rows);
        $batchIds = [];
        $entryMarks = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT b.id FROM joint_allocation_batches b
            WHERE b.supplier_id=? AND b.journal_entry_id IN ({$entryMarks}) ORDER BY b.id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$entryIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $batchIds[(int) $id] = (int) $id;
        $stmt = $this->db->pdo()->prepare("SELECT batch_id FROM joint_allocation_cancellations
            WHERE supplier_id=? AND reversal_entry_id IN ({$entryMarks}) ORDER BY batch_id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$entryIds]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $batchIds[(int) $id] = (int) $id;
        $lineMarks = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT sc.batch_id FROM joint_allocation_source_claims sc
            JOIN joint_allocation_batches b ON b.supplier_id=sc.supplier_id AND b.id=sc.batch_id
            LEFT JOIN joint_allocation_cancellations c ON c.supplier_id=b.supplier_id AND c.batch_id=b.id
            WHERE sc.supplier_id=? AND sc.line_id IN ({$lineMarks})
              AND ((b.target_date>=? AND b.target_date<=?) OR (c.target_date>=? AND c.target_date<=?)) ORDER BY sc.batch_id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$lineIds, $period['starts_on'], $period['ends_on'], $period['starts_on'], $period['ends_on']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $batchIds[(int) $id] = (int) $id;
        ksort($batchIds, SORT_NUMERIC);
        return array_values($batchIds);
    }

    /** @param list<int> $batchIds @return array<int,array<int,array<string,mixed>>> */
    private function projections(int $supplierId, array $batchIds, bool $currentRead): array
    {
        $out = [];
        foreach (array_chunk($batchIds, 500) as $chunk) foreach ($this->joint->ledgerProjectionsForBatches($supplierId, $chunk, $currentRead) as $id => $projection) $out[$id] = $projection;
        return $out;
    }

    /** @param array<int,array<int,array<string,mixed>>> $projections @return array<int,array<string,mixed>> */
    private function carrierMap(array $projections): array
    {
        $out = [];
        foreach ($projections as $projection) foreach ($projection as $lineId => $line) {
            if (!isset($out[$lineId]) || ($out[$lineId]['role'] === 'source' && $line['role'] !== 'source')) $out[$lineId] = $line;
            elseif ($out[$lineId]['role'] !== 'source' && $line['role'] !== 'source' && $out[$lineId]['batch_id'] !== $line['batch_id']) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Řádek má více společných alokací.', 409);
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,array<string,mixed>> $carrier @param array<int,array<int,array<string,mixed>>> $projections @return array<int,int> */
    private function ultimateSourceIds(int $supplierId, array $rows, array &$carrier, array &$projections, bool $currentRead): array
    {
        $frontier = [];
        foreach ($rows as $id => $_) if (isset($carrier[$id]) && $carrier[$id]['role'] !== 'source') $frontier[(int) $carrier[$id]['source_line_id']] = true;
        for ($depth = 0; $frontier !== []; ++$depth) {
            if ($depth >= 64) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původ společné alokace obsahuje cyklus.', 409);
            $unknown = array_values(array_filter(array_keys($frontier), static fn (int $id): bool => !isset($carrier[$id])));
            if ($unknown === []) break;
            $batchIds = $this->carrierBatchIdsForLines($supplierId, $unknown, $currentRead);
            if ($batchIds === []) break;
            foreach ($this->projections($supplierId, $batchIds, $currentRead) as $batchId => $projection) $projections[$batchId] = $projection;
            $carrier = $this->carrierMap($projections);
            $next = [];
            foreach ($unknown as $lineId) if (isset($carrier[$lineId]) && $carrier[$lineId]['role'] !== 'source') $next[(int) $carrier[$lineId]['source_line_id']] = true;
            $frontier = $next;
        }
        $out = [];
        foreach ($rows as $id => $_) {
            $lineId = $id;
            $seen = [];
            while (isset($carrier[$lineId]) && $carrier[$lineId]['role'] !== 'source') {
                if (isset($seen[$lineId])) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původ společné alokace obsahuje cyklus.', 409);
                $seen[$lineId] = true;
                $lineId = (int) $carrier[$lineId]['source_line_id'];
            }
            $out[$id] = $lineId;
        }
        return $out;
    }

    /** @param list<int> $lineIds @return list<int> */
    private function carrierBatchIdsForLines(int $supplierId, array $lineIds, bool $currentRead): array
    {
        if ($lineIds === []) return [];
        $marks = implode(',', array_fill(0, count($lineIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT COALESCE(b.id,c.batch_id) AS batch_id FROM journal_entry_lines l
            LEFT JOIN joint_allocation_batches b ON b.supplier_id=l.supplier_id AND b.journal_entry_id=l.entry_id
            LEFT JOIN joint_allocation_cancellations c ON c.supplier_id=l.supplier_id AND c.reversal_entry_id=l.entry_id
            WHERE l.supplier_id=? AND l.id IN ({$marks}) AND (b.id IS NOT NULL OR c.batch_id IS NOT NULL) ORDER BY batch_id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$lineIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $lineIds @return array<int,array<string,mixed>> */
    private function ledgerLinesByIds(int $supplierId, array $lineIds, bool $currentRead): array
    {
        $out = [];
        foreach (array_chunk($lineIds, 500) as $chunk) {
            if ($chunk === []) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT l.id,l.entry_id,l.line_no,l.account_id,l.side,l.signed_amount,l.amount_foreign,l.currency_code,l.fx_rate,
                    e.period_id,e.entry_date,e.source_type,e.source_id,e.reversed_by,a.account_code,a.account_type,a.tax_deductibility AS base_tax_deductibility
                FROM journal_entry_lines l JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id
                JOIN chart_of_accounts a ON a.supplier_id=l.supplier_id AND a.id=l.account_id
                WHERE l.supplier_id=? AND l.id IN ({$marks}) ORDER BY l.id" . $this->lock($currentRead));
            $stmt->execute([$supplierId, ...$chunk]);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $origins = $this->journalOrigins($supplierId, $raw, $currentRead);
            foreach ($raw as $row) {
                $row['origin_type'] = $origins[(int) $row['entry_id']]['source_type'];
                $row['origin_id'] = $origins[(int) $row['entry_id']]['source_id'];
                $out[(int) $row['id']] = $row;
            }
        }
        if (count($out) !== count(array_unique($lineIds))) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původní řádek společné alokace není dohledatelný.', 409);
        $this->applyAccountDefinitions($supplierId, $out, $currentRead);
        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,bool> */
    private function purchaseDeductibility(int $supplierId, array $rows, bool $currentRead): array
    {
        $ids = [];
        foreach ($rows as $row) if (($row['origin_type'] ?? null) === 'purchase_invoice' && isset($row['origin_id'])) $ids[(int) $row['origin_id']] = (int) $row['origin_id'];
        if ($ids === []) return [];
        $out = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id,tax_deductible FROM purchase_invoices WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id" . $this->lock($currentRead));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['id']] = (bool) $row['tax_deductible'];
        }
        return $out;
    }

    /** @param array<int,bool> $purchaseDeductibility */
    private function mandatoryNonDeductible(array $row, array $purchaseDeductibility): bool
    {
        return ($row['tax_deductibility'] ?? $row['base_tax_deductibility'] ?? null) === 'non_deductible'
            || (($row['origin_type'] ?? null) === 'purchase_invoice' && isset($row['origin_id']) && ($purchaseDeductibility[(int) $row['origin_id']] ?? true) === false);
    }

    /** @param array<int,array<int,array<string,mixed>>> $projections @return array<int,true> */
    private function neutralLines(int $supplierId, array $period, array $projections, bool $currentRead): array
    {
        $candidateIds = [];
        foreach ($projections as $projection) foreach ($projection as $lineId => $line) {
            if (in_array($line['role'], ['source', 'compensation', 'cancellation_compensation'], true)) $candidateIds[(int) $lineId] = (int) $lineId;
        }
        if ($candidateIds === []) return [];
        $actual = $this->ledgerLinesByIds($supplierId, array_values($candidateIds), $currentRead);
        $neutral = [];
        foreach ($projections as $projection) {
            $pools = [];
            foreach ($projection as $lineId => $line) {
                if (!in_array($line['role'], ['source', 'compensation', 'cancellation_compensation'], true)) continue;
                $row = $actual[(int) $lineId];
                JointAllocationReadRepository::assertJointLedgerLine($row, $line);
                if ($row['entry_date'] < $period['starts_on'] || $row['entry_date'] > $period['ends_on']
                    || !preg_match('/^[56]/D', $row['account_code']) || str_starts_with($row['account_code'], '59')) continue;
                $source = (int) $line['source_line_id'];
                $pools[$source]['sum'] = ($pools[$source]['sum'] ?? 0) + self::cents($row['signed_amount']) * ($row['side'] === 'debit' ? 1 : -1);
                $pools[$source]['ids'][] = (int) $lineId;
            }
            foreach ($pools as $pool) if ($pool['sum'] === 0) foreach ($pool['ids'] as $id) $neutral[$id] = true;
        }
        return $neutral;
    }

    private function lock(bool $currentRead): string
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current snapshot reads require a transaction.');
        return $currentRead ? ' FOR UPDATE' : '';
    }

    private static function cents(string $amount): int
    {
        $value = bcmul($amount, '100', 0);
        if (bccomp($value, (string) PHP_INT_MAX) > 0 || bccomp($value, (string) PHP_INT_MIN) < 0) NonprofitTaxRules::invalid('Částka překračuje podporovaný rozsah.');
        return (int) $value;
    }
}

