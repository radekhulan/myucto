<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Sdílený zámek pro ruční/importovaný vstup a materializaci téhož nároku. */
final class PayrollHourlyWageCollisionGuard
{
    /** Složky základní mzdy, které s hodinovými podmínkami vztahu kolidují vždy. */
    private const BASE_COMPONENT_KINDS = ['base_wage', 'hourly_wage'];

    /** Kolik měsíců před účinností hodinové sazby se importovaná mzda ještě počítá. */
    public const IMPORTED_WAGE_LOOKBACK_MONTHS = 3;

    public function __construct(private readonly Connection $db) {}

    /**
     * Vztahy, které by přechod na hodinové podmínky účinné od `$effectiveFrom`
     * odřízl od importu mzdy: mají externí základní mzdu v měsíci účinnosti,
     * ve třech měsících před ním nebo kdykoli později.
     *
     * Jediné místo, kde se okno určuje — sdílí ho karta vztahu i hromadná úprava.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    public function employmentsBlockingHourlySwitch(int $supplierId, array $employmentIds, string $effectiveFrom): array
    {
        $month = new \DateTimeImmutable(substr($effectiveFrom, 0, 8) . '01');

        return $this->employmentsWithExternalBaseWage(
            $supplierId,
            $employmentIds,
            $month->modify('-' . self::IMPORTED_WAGE_LOOKBACK_MONTHS . ' months')->format('Y-m-d'),
            '9999-12-01',
        );
    }

    /**
     * Vztahy, kterým v daných měsících chodí základní mzda jako hotová částka
     * zvenčí (import docházky, ruční vstup), ne z výpočtu hodinové mzdy.
     *
     * Přepnutím na hodinové podmínky by {@see externalInputs()} každý další
     * takový vstup odmítla, proto se na to musí ptát každý, kdo hodinové
     * podmínky zapisuje. Pravidelné předpisy řeší přechod na
     * hodinovou mzdu samostatně, vstupy vzniklé z výpočtu se nepočítají.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    public function employmentsWithExternalBaseWage(int $supplierId, array $employmentIds, string $fromPeriod, string $toPeriod): array
    {
        if ($employmentIds === []) return [];
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $kinds = implode(',', array_fill(0, count(self::BASE_COMPONENT_KINDS), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT DISTINCT input.employment_id
            FROM payroll_inputs input FORCE INDEX (uq_payroll_input_external)
            JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id
            WHERE input.supplier_id = ? AND input.employment_id IN ($in)
              AND input.period_start >= ? AND input.period_start <= ?
              AND input.status <> 'cancelled' AND input.source_kind <> 'recurring'
              AND component.component_kind IN ($kinds)
              AND NOT EXISTS (SELECT 1 FROM payroll_hourly_wage_materializations materialization
                               WHERE materialization.supplier_id = input.supplier_id AND materialization.input_id = input.id)");
        $stmt->execute([$supplierId, ...$employmentIds, $fromPeriod, $toPeriod, ...self::BASE_COMPONENT_KINDS]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Rezervované složky hodinového výpočtu patří výpočtu jen tam, kde vztah má
     * v měsíci hodinové podmínky; jinde zůstávají běžnou (i vlastní) složkou.
     */
    public function reservedForCalculation(int $supplierId, int $employmentId, string $periodStart, string $componentCode): bool
    {
        if (!\MyInvoice\Service\Payroll\PayrollHourlyWageComponents::isReserved($componentCode)) return false;
        $end = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM payroll_employment_terms FORCE INDEX (idx_payroll_employment_term_effective)
            WHERE supplier_id = ? AND employment_id = ? AND remuneration_type = 'hourly'
              AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) LIMIT 1");
        $stmt->execute([$supplierId, $employmentId, $end, $periodStart]);
        return $stmt->fetchColumn() !== false;
    }

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
        $kinds = implode(',', array_fill(0, count(self::BASE_COMPONENT_KINDS), '?'));
        $stmt = $pdo->prepare("SELECT id, code, component_kind FROM payroll_component_definitions WHERE supplier_id = ? AND id IN ($in) AND (component_kind IN ($kinds) OR code IN ($generatedIn))");
        $stmt->execute([$supplierId, ...$componentIds, ...self::BASE_COMPONENT_KINDS, ...$generated]);
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
        $stmt = $pdo->prepare("SELECT employment.id, employment.code, employee.full_name FROM payroll_employments employment
            JOIN payroll_employees employee ON employee.supplier_id = employment.supplier_id AND employee.id = employment.employee_id
            WHERE employment.supplier_id = ? AND employment.id IN ($in) ORDER BY employment.id FOR UPDATE");
        $stmt->execute([$supplierId, ...$ids]);
        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $label) $labels[(int) $label['id']] = $label;
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
        $codes = ['ordinary_base' => 'MZDA_HODINOVA_ZAKLAD', 'achieved_overtime' => 'MZDA_DOSAZENA_PRESCAS', 'minimum_wage_top_up' => 'DOPLATEK_MINIMALNI_MZDA',
            'holiday_nonworked_compensation' => 'NAHRADA_MZDY_SVATEK', 'holiday_time_off_compensation' => 'NAHRADA_MZDY_VOLNO_ZA_SVATEK'];
        foreach (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind::all() as $kind) $codes['premium_' . $kind->value] = $kind->componentCode();
        foreach ($rows as $row) {
            $id = (int) $row['employment_id'];
            $component = $baseComponents[(int) $row['component_id']] ?? null;
            if ($component === null) continue;
            $base = in_array($component['component_kind'], self::BASE_COMPONENT_KINDS, true);
            $conflict = $base && (isset($hourly[$id]) || isset($purposes[$id]['ordinary_base']) || isset($purposes[$id]['achieved_overtime']));
            foreach ($purposes[$id] ?? [] as $purpose => $_) if (($codes[$purpose] ?? null) === $component['code']) $conflict = true;
            if ($conflict) {
                $link = '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7) . '&employment=' . $id . '&hours=1';
                throw new \MyInvoice\Service\Payroll\PayrollHourlyWageConflictException(sprintf(
                    '%s (vztah %s) má pro složku %s za %s vypočtený hodinový nárok z odpracovaných hodin. '
                        . 'Ponechte jediný zdroj: vstup nezadávejte (neimportujte), nebo nejdřív opravte hodiny a podmínky vztahu '
                        . 'v Rychlém měsíčním vstupu.',
                    (string) ($labels[$id]['full_name'] ?? ''),
                    (string) ($labels[$id]['code'] ?? $id),
                    (string) $component['code'],
                    substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4),
                ), [
                    'employment_id' => $id,
                    'component_code' => (string) $component['code'],
                    'link' => $link,
                ]);
            }
        }
    }
}
