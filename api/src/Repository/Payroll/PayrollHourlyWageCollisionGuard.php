<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Sdílený zámek pro ruční/importovaný vstup a materializaci téhož nároku. */
final class PayrollHourlyWageCollisionGuard
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<array{employment_id:int,component_id:int}> $rows */
    public function externalInputs(int $supplierId, string $periodStart, array $rows): void
    {
        if ($rows === []) return;
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Kontrola zdroje mzdy vyžaduje transakci.');
        $componentIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['component_id'], $rows)));
        $in = implode(',', array_fill(0, count($componentIds), '?'));
        $generated = \MyInvoice\Service\Payroll\PayrollHourlyWageComponents::GENERATED;
        $generatedIn = implode(',', array_fill(0, count($generated), '?'));
        $stmt = $pdo->prepare("SELECT id, code, component_kind FROM payroll_component_definitions WHERE supplier_id = ? AND id IN ($in) AND (component_kind IN ('base_wage','hourly_wage') OR code IN ($generatedIn))");
        $stmt->execute([$supplierId, ...$componentIds, ...$generated]);
        $baseComponents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $component) $baseComponents[(int) $component['id']] = $component;
        $ids = [];
        foreach ($rows as $row) {
            if (isset($baseComponents[(int) $row['component_id']])) $ids[(int) $row['employment_id']] = true;
        }
        if ($ids === []) return;
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM payroll_employments WHERE supplier_id = ? AND id IN ($in) ORDER BY id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        $stmt->fetchAll(PDO::FETCH_COLUMN);
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $stmt = $pdo->prepare("SELECT employment_id FROM payroll_employment_terms FORCE INDEX (idx_payroll_employment_term_effective)
            WHERE supplier_id = ? AND employment_id IN ($in) AND remuneration_type = 'hourly'
              AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)");
        $stmt->execute([$supplierId, ...$ids, $end, $periodStart]);
        $hourly = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $stmt = $pdo->prepare("SELECT claim.employment_id, claim.purpose,
            SUM(CASE WHEN materialization.input_id IS NULL OR input.status <> 'cancelled' THEN materialization.delta_amount_minor ELSE 0 END) AS amount,
            SUM(CASE WHEN materialization.input_id IS NULL OR input.status <> 'cancelled' THEN materialization.duration_delta_units ELSE 0 END) AS duration
            FROM payroll_hourly_wage_claims claim
            JOIN payroll_hourly_wage_materializations materialization ON materialization.supplier_id = claim.supplier_id AND materialization.claim_id = claim.id
            LEFT JOIN payroll_inputs input ON input.supplier_id = materialization.supplier_id AND input.id = materialization.input_id
            WHERE claim.supplier_id = ? AND claim.employment_id IN ($in) AND claim.work_period_start = ? AND claim.claim_source = 'hourly_calculation'
            GROUP BY claim.employment_id, claim.purpose HAVING amount <> 0 OR duration <> 0 FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids, $periodStart]);
        $purposes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $claim) $purposes[(int) $claim['employment_id']][(string) $claim['purpose']] = true;
        $codes = ['ordinary_base' => 'MZDA_HODINOVA_ZAKLAD', 'achieved_overtime' => 'MZDA_DOSAZENA_PRESCAS',
            'holiday_nonworked_compensation' => 'NAHRADA_MZDY_SVATEK', 'holiday_time_off_compensation' => 'NAHRADA_MZDY_VOLNO_ZA_SVATEK'];
        foreach (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind::all() as $kind) $codes['premium_' . $kind->value] = $kind->componentCode();
        foreach ($rows as $row) {
            $id = (int) $row['employment_id'];
            $component = $baseComponents[(int) $row['component_id']] ?? null;
            if ($component === null) continue;
            $base = in_array($component['component_kind'], ['base_wage', 'hourly_wage'], true);
            $conflict = $base && (isset($hourly[$id]) || isset($purposes[$id]['ordinary_base']) || isset($purposes[$id]['achieved_overtime']));
            foreach ($purposes[$id] ?? [] as $purpose => $_) if (($codes[$purpose] ?? null) === $component['code']) $conflict = true;
            if ($conflict) throw new \MyInvoice\Service\Payroll\PayrollHourlyWageConflictException(sprintf('Vztah %d má vypočtený hodinový nárok pro tuto složku. Ponechte jediný zdroj.', $id));
        }
    }
}
