<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollComponentMinimumWage;
use PDO;

/** Dávkové zmrazení ostatních schválených peněžních složek pro § 111. */
final class PayrollHourlyMinimumInputs
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $employmentIds @return array<int,list<array<string,mixed>>> */
    public function forMany(int $supplierId, array $employmentIds, string $periodStart, bool $lock = false): array
    {
        if ($employmentIds === []) return [];
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT component.*, input.id AS input_id, input.employment_id,
                input.amount_minor, input.source_period_start, input.component_snapshot_json
            FROM payroll_inputs input FORCE INDEX (uq_payroll_input_external)
            JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
            WHERE input.supplier_id = ? AND input.period_start = ? AND input.employment_id IN ($in)
              AND input.status IN ('approved', 'locked')
              AND NOT EXISTS (SELECT 1 FROM payroll_hourly_wage_materializations materialization
                  WHERE materialization.supplier_id = input.supplier_id AND materialization.input_id = input.id)
            ORDER BY input.employment_id, input.id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $periodStart, ...$employmentIds]);
        $result = array_fill_keys($employmentIds, []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Základní zařazení pochází ze schváleného snapshotu; doložené
            // doplňující rozhodnutí účetní čteme z účinné verze číselníku.
            $snapshot = json_decode((string) ($row['component_snapshot_json'] ?? ''), true);
            $classification = $row;
            if (is_array($snapshot)) {
                foreach (['code', 'component_kind', 'value_kind'] as $field) {
                    if (array_key_exists($field, $snapshot)) $classification[$field] = $snapshot[$field];
                }
            }
            $treatment = PayrollComponentMinimumWage::resolve($classification)['treatment'];
            // Minimum se posuzuje za měsíc výkonu práce; plnění za jiný měsíc
            // (například odměna za únor zúčtovaná v březnu) do něj nepatří.
            $otherPeriod = $row['source_period_start'] !== null && $row['source_period_start'] !== $periodStart;
            if ($otherPeriod) $treatment = 'excluded';
            $id = (int) $row['employment_id'];
            $key = (int) $row['id'] . ':' . ($row['source_period_start'] ?? '') . ':' . $treatment;
            $result[$id][$key] ??= ['component_id' => (int) $row['id'], 'version' => (int) $row['row_version'],
                'input_ids' => [], 'amount_minor' => 0, 'minimum_wage_treatment' => $treatment,
                'component_code' => (string) $row['code'], 'source_period_start' => $row['source_period_start'],
                'classification_reason' => $row['minimum_wage_reason'] ?? null,
                ...($otherPeriod ? ['exclusion_reason' => 'source_period_other'] : [])];
            $result[$id][$key]['input_ids'][] = (int) $row['input_id'];
            $old = $result[$id][$key]['amount_minor'];
            $amount = (int) $row['amount_minor'];
            if (($amount > 0 && $old > PHP_INT_MAX - $amount) || ($amount < 0 && $old < PHP_INT_MIN - $amount)) {
                throw new \OverflowException('Součet složky pro minimální mzdu překročil celočíselný rozsah.');
            }
            $result[$id][$key]['amount_minor'] = $old + $amount;
        }
        foreach ($result as &$rows) $rows = array_values($rows);
        unset($rows);
        return $result;
    }
}
