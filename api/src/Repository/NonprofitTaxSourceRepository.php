<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;

final class NonprofitTaxSourceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingFrameworkService $frameworks,
        private readonly JointAllocationReadRepository $joint,
        private readonly NonprofitTaxAssessmentRepository $assessments,
    ) {}

    public function period(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        if ($currentRead) (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id, fiscal_year, starts_on, ends_on, status FROM accounting_periods WHERE supplier_id = ? AND id = ?' . $this->lock($currentRead));
        $stmt->execute([$supplierId, $periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        return $period;
    }

    public function snapshot(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $guard = new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db);
        return $guard->transaction(function () use ($guard, $supplierId, $periodId): array {
            $guard->lockPeriods($supplierId);
            $memo = [];
            return $this->buildSnapshot($supplierId, $periodId, true, $memo);
        });
    }

    private function buildSnapshot(int $supplierId, int $periodId, bool $currentRead, array &$memo): array
    {
        if (isset($memo[$periodId])) return $memo[$periodId];
        $period = $this->period($supplierId, $periodId, $currentRead);
        $frameworks = $this->frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead);
        if (array_values(array_unique($frameworks)) !== [AccountingFrameworkService::CZ_504]) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_framework_unsupported', 'Podklad pro neziskové organizace vyžaduje rámec 504 v celém období.');
        }
        unset($period['status']);
        $lock = $this->lock($currentRead);
        $ledger = $this->ledgerRows($supplierId, 'e.entry_date BETWEEN ? AND ?', [$period['starts_on'], $period['ends_on']], $currentRead);
        $byId = array_column($ledger, null, 'id');
        $dimensions = $this->dimensions($supplierId, array_keys($byId), $currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT b.id FROM joint_allocation_batches b
            LEFT JOIN joint_allocation_cancellations c ON c.batch_id = b.id AND c.supplier_id = b.supplier_id
            WHERE b.supplier_id = ? AND b.journal_entry_id IS NOT NULL
                AND (b.target_date BETWEEN ? AND ? OR c.target_date BETWEEN ? AND ?) ORDER BY b.id' . $lock);
        $stmt->execute([$supplierId, $period['starts_on'], $period['ends_on'], $period['starts_on'], $period['ends_on']]);
        $joint = $pool = $projections = $attributions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $batchId) {
            try {
                $projection = $this->joint->ledgerProjection($supplierId, (int) $batchId, $currentRead);
            } catch (\MyInvoice\Service\Accounting\Allocation\JointAllocationException $failure) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', $failure->getMessage(), 409);
            }
            $projections[(string) $batchId] = $projection;
            foreach ($projection as $id => $line) {
                if (!isset($byId[$id])) continue;
                $actual = $byId[$id];
                self::assertJointLedgerLine($actual, $line);
                if (in_array($line['role'], ['source', 'compensation', 'cancellation_compensation'], true)) {
                    $pool[(string) $line['source_line_id']][$id] = $actual;
                } else {
                    if (isset($joint[$id])) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Řádek má více společných alokací.', 409);
                    $joint[$id] = $line;
                }
                if ($line['role'] !== 'source') $attributions[$id] = $projection[$line['source_line_id']];
            }
        }
        $neutral = [];
        foreach ($pool as $lines) {
            $sum = 0;
            foreach ($lines as $line) $sum += self::cents($line['signed_amount']) * ($line['side'] === 'debit' ? 1 : -1);
            if ($sum === 0) foreach ($lines as $line) $neutral[(int) $line['id']] = true;
        }
        $sources = $findings = $references = [];
        $accountingResult = 0;
        foreach ($ledger as $line) {
            $id = (int) $line['id'];
            $kind = str_starts_with($line['account_code'], '6') ? 'revenue' : 'expense';
            $amount = self::cents($line['signed_amount']) * (($line['side'] === 'credit') === ($kind === 'revenue') ? 1 : -1);
            $accountingResult += $kind === 'revenue' ? $amount : -$amount;
            if (isset($neutral[$id])) continue;
            $axes = $joint[$id]['axes'] ?? ($dimensions[$id]['axes'] ?? []);
            $provenance = $joint[$id] ?? ($dimensions[$id] ?? []);
            $origin = $line;
            if (isset($attributions[$id])) {
                $sourceId = (int) $attributions[$id]['source_line_id'];
                $origin = $this->sourceOrigin($supplierId, $sourceId, $currentRead, $references);
                self::assertJointLedgerLine($references[$sourceId]['line'], $attributions[$id]);
            }
            foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) {
                if (!isset($axes[$axis])) $findings[] = ['line_id' => $id, 'code' => 'missing_' . $axis];
            }
            if (isset($axes['activity']) && !in_array($axes['activity']['semantic_key'], ['main', 'economic'], true)) $findings[] = ['line_id' => $id, 'code' => 'invalid_activity'];
            if (isset($axes['tax_regime']) && !in_array($axes['tax_regime']['semantic_key'], ['subject', 'out_of_scope', 'exempt'], true)) $findings[] = ['line_id' => $id, 'code' => 'invalid_tax_regime'];
            if (!isset($joint[$id])) {
                foreach (['activity', 'activity_kind', 'tax_regime'] as $axis) {
                    if (($axes[$axis]['assignment_origin'] ?? 'unknown') === 'unknown') $findings[] = ['line_id' => $id, 'code' => 'unknown_' . $axis . '_origin'];
                }
                if (!empty($dimensions[$id]['splits'])) $findings[] = ['line_id' => $id, 'code' => 'joint_allocation_required'];
            } else {
                foreach ($axes as $axis => $value) {
                    $actual = $dimensions[$id]['axes'][$axis] ?? [];
                    if (($actual['value_id'] ?? null) !== $value['value_id'] || ($actual['type_id'] ?? null) !== $value['type_id']
                        || ($actual['assignment_origin'] ?? null) !== 'module'
                        || ($actual['assignment_origin_ref'] ?? null) !== 'allocation:' . $joint[$id]['batch_id']) {
                        $findings[] = ['line_id' => $id, 'code' => 'joint_dimensions_changed'];
                    }
                }
                if (!empty($dimensions[$id]['splits'])) $findings[] = ['line_id' => $id, 'code' => 'joint_dimensions_changed'];
            }
            $sources[] = ['id' => 'line:' . $id, 'line_id' => $id, 'entry_id' => (int) $line['entry_id'],
                'account_id' => (int) $line['account_id'], 'account_code' => $line['account_code'], 'date' => $line['entry_date'],
                'kind' => $kind, 'amount_cents' => $amount, 'activity' => $axes['activity']['semantic_key'] ?? null,
                'activity_kind_id' => isset($axes['activity_kind']) ? (int) $axes['activity_kind']['value_id'] : null,
                'tax_regime' => $axes['tax_regime']['semantic_key'] ?? null,
                'mandatory_non_deductible' => (bool) $line['mandatory_non_deductible'] || (bool) $origin['mandatory_non_deductible'], 'axes' => $axes, 'provenance' => $provenance,
                'origin' => ['type' => $origin['origin_type'], 'id' => $origin['origin_id'], 'line_id' => (int) $origin['id']]];
        }
        [$historical, $fundingReferences] = $this->fundingHistory($supplierId, $periodId, $period['starts_on'], $period['ends_on'], $currentRead, $memo);
        $snapshot = ['supplier_id' => $supplierId, 'period' => $period, 'frameworks' => $frameworks,
            'ruleset' => NonprofitTaxRules::VERSION,
            'assessment_support' => ['automatic' => NonprofitTaxRules::supportsPeriod($period), 'documented_manual' => !NonprofitTaxRules::supportsPeriod($period),
                'automatic_from' => NonprofitTaxRules::AUTOMATIC_FROM, 'automatic_to' => NonprofitTaxRules::AUTOMATIC_TO],
            'sources' => $sources, 'findings' => $findings,
            'accounting_result_cents' => $accountingResult, 'ledger' => $ledger, 'dimensions' => $dimensions,
            'joint_projections' => $projections, 'source_references' => $references, 'neutral_line_ids' => array_keys($neutral),
            'funding_history' => $historical, 'funding_reference_sources' => $fundingReferences];
        $snapshot += (new \MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService($this->db))->assessmentContext($supplierId, $periodId, $currentRead);
        $snapshot = (new \MyInvoice\Service\Tax\Nonprofit\NonprofitSvjSourceFacts($this->db))->attach($supplierId, $snapshot);
        $snapshot['input_hash'] = NonprofitTaxRules::hash($snapshot);
        return $memo[$periodId] = $snapshot;
    }

    private function fundingHistory(int $supplierId, int $periodId, string $startsOn, string $endsOn, bool $currentRead, array &$memo): array
    {
        $history = $references = [];
        foreach ($this->assessments->effectiveOtherPeriods($supplierId, $periodId, $currentRead) as $revision) {
            if (\MyInvoice\Service\Tax\Nonprofit\NonprofitDppoAssessmentSource::pagedOnly($revision)) {
                // Stránkovaná revize nemá legacy snímek. Pozdější období historii financování
                // neovlivňuje; předchozí stránkované období legacy výpočet neumí doložit.
                $stmt = $this->db->pdo()->prepare('SELECT ends_on FROM accounting_periods WHERE supplier_id=? AND id=?');
                $stmt->execute([$supplierId, $revision['period_id']]);
                if ((string) $stmt->fetchColumn() >= $startsOn) continue;
                throw new NonprofitTaxAssessmentException('nonprofit_tax_paged_history_required',
                    'Předchozí období je posouzené ve stránkovaném formátu. Navazující posouzení potvrďte také stránkovanou cestou.', 409);
            }
            if ($revision['snapshot']['period']['ends_on'] >= $startsOn) continue;
            try {
                $priorSnapshot = $this->buildSnapshot($supplierId, $revision['period_id'], $currentRead, $memo);
                $current = hash_equals($revision['input_hash'], $priorSnapshot['input_hash']);
            } catch (NonprofitTaxAssessmentException) { $current = false; }
            $history[] = ['assessment_id' => $revision['id'], 'period_id' => $revision['period_id'], 'status' => $revision['status'],
                'input_hash' => $revision['input_hash'], 'current' => $current, 'usage' => $revision['result']['funding_usage'] ?? []];
            foreach (array_merge($revision['result']['rows'], array_values($revision['snapshot']['funding_reference_sources'] ?? [])) as $row) {
                if ($row['kind'] !== 'revenue' || ($row['date'] ?? '') > $endsOn
                    || !in_array($row['decision']['title'] ?? '', ['gift', 'public_operating_grant', 'public_investment_grant'], true)) continue;
                $references[$row['id']] = $row;
                $references[$row['id']]['reference_assessment_id'] = $revision['id'];
                $references[$row['id']]['reference_current'] = false;
                $references[$row['id']]['reference_context_current'] = $current && $revision['status'] === 'confirmed';
                unset($references[$row['id']]['reference_ledger']);
            }
        }
        foreach (array_chunk(array_column($references, 'line_id'), 500) as $ids) {
            $current = $this->ledgerRows($supplierId, 'l.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids, $currentRead);
            foreach ($current as $line) {
                $id = 'line:' . $line['id'];
                $amount = self::cents($line['signed_amount']) * ($line['side'] === 'credit' ? 1 : -1);
                $references[$id]['reference_current'] = $references[$id]['reference_context_current'] && $amount === $references[$id]['amount_cents']
                    && (int) $line['account_id'] === $references[$id]['account_id'];
                $references[$id]['reference_ledger'] = $line;
            }
        }
        ksort($references, SORT_STRING);
        return [$history, $references];
    }

    private function sourceOrigin(int $supplierId, int $lineId, bool $currentRead, array &$references, array $seen = []): array
    {
        if (isset($seen[$lineId])) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původ společné alokace obsahuje cyklus.', 409);
        if (isset($references[$lineId])) return $references[$lineId]['resolved'];
        $seen[$lineId] = true;
        $rows = $this->ledgerRows($supplierId, 'l.id = ?', [$lineId], $currentRead);
        if (count($rows) !== 1) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původní náklad společné alokace není dohledatelný.', 409);
        $line = $rows[0];
        $stmt = $this->db->pdo()->prepare('SELECT b.id FROM joint_allocation_batches b
            LEFT JOIN joint_allocation_cancellations c ON c.batch_id = b.id AND c.supplier_id = b.supplier_id
            WHERE b.supplier_id = ? AND (b.journal_entry_id = ? OR c.reversal_entry_id = ?)' . $this->lock($currentRead));
        $stmt->execute([$supplierId, $line['entry_id'], $line['entry_id']]);
        $batchIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($batchIds) > 1) throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původ nákladu není jednoznačný.', 409);
        $resolved = $line;
        $projection = null;
        if ($batchIds !== []) {
            $projection = $this->joint->ledgerProjection($supplierId, (int) $batchIds[0], $currentRead);
            if (!isset($projection[$lineId]) || $projection[$lineId]['role'] === 'source') throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Původ řádku společné alokace není doložen.', 409);
            self::assertJointLedgerLine($line, $projection[$lineId]);
            $resolved = $this->sourceOrigin($supplierId, (int) $projection[$lineId]['source_line_id'], $currentRead, $references, $seen);
            self::assertJointLedgerLine($references[(int) $projection[$lineId]['source_line_id']]['line'], $projection[$projection[$lineId]['source_line_id']]);
            $resolved['mandatory_non_deductible'] = (bool) $resolved['mandatory_non_deductible'] || (bool) $line['mandatory_non_deductible'];
        }
        $references[$lineId] = ['line' => $line, 'projection' => $projection, 'resolved' => $resolved];
        return $resolved;
    }

    public static function assertJointLedgerLine(array $actual, array $projected): void
    {
        if ((int) $actual['account_id'] !== $projected['account_id'] || $actual['side'] !== $projected['side']
            || self::cents($actual['signed_amount']) !== (int) $projected['signed_amount_cents']) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_joint_changed', 'Deník nesouhlasí s neměnnou společnou alokací.', 409);
        }
    }

    private function ledgerRows(int $supplierId, string $filter, array $params, bool $currentRead): array
    {
        $stmt = $this->db->pdo()->prepare('WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId, $currentRead) . '
            SELECT l.id, l.entry_id, l.account_id, l.side, l.signed_amount, l.amount_foreign, l.currency_code, l.fx_rate,
                e.entry_date, e.source_type, e.source_id, e.reversed_by, a.account_code, a.account_type, a.tax_deductibility,
                ' . JournalTaxOrigin::sourceTypeSql() . ' AS origin_type, ' . JournalTaxOrigin::sourceIdSql() . ' AS origin_id,
                ' . NonDeductibleCostsService::predicate() . ' AS mandatory_non_deductible
            FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            ' . AccountDefinitionRepository::journalJoin($supplierId, $currentRead) . '
            ' . JournalTaxOrigin::join() . '
            LEFT JOIN purchase_invoices pi ON ' . JournalTaxOrigin::sourceTypeSql() . " = 'purchase_invoice'
                AND pi.id = " . JournalTaxOrigin::sourceIdSql() . ' AND pi.supplier_id = e.supplier_id
            WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND ' . $filter . '
                AND ' . JournalTaxOrigin::includedSql() . " AND (a.account_code LIKE '5%' OR a.account_code LIKE '6%')
                AND a.account_code NOT LIKE '59%' ORDER BY l.id" . $this->lock($currentRead));
        $stmt->execute([$supplierId, ...$params, ClosingSourceId::STOCK_SLOT_BASE]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dimensions(int $supplierId, array $ids, bool $currentRead): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['journal_entry_line_dimensions' => false, 'journal_entry_line_dimension_splits' => true] as $table => $split) {
                $stmt = $this->db->pdo()->prepare("SELECT d.*, t.system_key, v.semantic_key, v.code, v.name
                    FROM {$table} d JOIN dimension_types t ON t.id = d.dimension_type_id AND t.supplier_id = d.supplier_id
                    JOIN dimension_values v ON v.id = d.dimension_value_id AND v.supplier_id = d.supplier_id AND v.type_id = t.id
                    WHERE d.supplier_id = ? AND d.line_id IN ({$marks}) AND t.system_key IN ('activity','activity_kind','tax_regime')
                    ORDER BY d.line_id, d.dimension_type_id, d.dimension_value_id" . $this->lock($currentRead));
                $stmt->execute([$supplierId, ...$chunk]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $axis = ['type_id' => (int) $row['dimension_type_id'], 'value_id' => (int) $row['dimension_value_id'],
                        'semantic_key' => $row['semantic_key'], 'code' => $row['code'], 'name' => $row['name'],
                        'assignment_origin' => $row['assignment_origin'], 'assignment_origin_ref' => $row['assignment_origin_ref']];
                    if ($split) $out[(int) $row['line_id']]['splits'][] = $row;
                    else $out[(int) $row['line_id']]['axes'][$row['system_key']] = $axis;
                }
            }
        }
        return $out;
    }

    private function lock(bool $currentRead): string
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current tax source reads require a transaction.');
        return $currentRead ? ' FOR UPDATE' : '';
    }

    private static function cents(string $amount): int
    {
        $value = bcmul($amount, '100', 0);
        if (bccomp($value, (string) PHP_INT_MAX) > 0 || bccomp($value, (string) PHP_INT_MIN) < 0) NonprofitTaxRules::invalid('Částka překračuje podporovaný rozsah.');
        return (int) $value;
    }
}
