<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Kontrola běhu: pravidelná základní mzda nesedí na sjednanou měsíční mzdu.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Pravidelná složka základní mzdy zadaná pevnou částkou
 * ({@see \MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator},
 * `fixed_amount`) se na podmínky vztahu neváže. Měsíční mzdu 44 200 Kč
 * v podmínkách vztahu tak předpis tiše přebil částkou 35 500 Kč, kterou měl
 * jiný zaměstnanec, a běh ji bez jediné zmínky vyplatil.
 *
 * ── Pravidlo ────────────────────────────────────────────────────────────────
 * Porovnává se celá měsíční částka PŘEDPISU, ne vstup za měsíc. Krácení za
 * neodpracovanou dobu, nástup nebo odchod v průběhu měsíce mění jen vstup,
 * předpis zůstává celou měsíční mzdou, takže tyto případy varování nevyvolají.
 * Změní-li se podmínky v průběhu měsíce, stačí shoda s kterýmikoli
 * podmínkami účinnými v době účinnosti předpisu. Vztah bez podmínek se
 * porovná s měsíční mzdou na vztahu, tak jako ji bere výpočet předpisu
 * (`PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAt()`). Hodinové
 * podmínky a předpis procentem ze mzdy se nekontrolují: první hlásí jiná
 * kontrola, druhý ze sjednané mzdy vychází sám.
 *
 * Je to varování, ne závora: rozdíl může být záměr (část mzdy v jiné složce).
 */
final class PayrollRunRecurringBaseWage
{
    public const CODE = 'recurring_base_wage_differs_from_terms';

    /**
     * @param list<array<string,mixed>> $employments řádky {@see PayrollRunSnapshotBuilder::employmentRows()}
     * @param array<int,list<array<string,mixed>>> $rows {@see PayrollRunSnapshotBatchLoader::recurringBaseWageTerms()}
     * @return list<PayrollRunValidation>
     */
    public static function validations(array $employments, array $rows, string $periodStart): array
    {
        $validations = [];
        $period = substr($periodStart, 0, 7);
        foreach ($employments as $employment) {
            $employmentId = (int) $employment['employment_id'];
            $byRecurring = [];
            foreach ($rows[$employmentId] ?? [] as $row) {
                $byRecurring[(int) $row['recurring_id']][] = $row;
            }
            foreach ($byRecurring as $recurringRows) {
                $mismatch = self::mismatch($recurringRows);
                if ($mismatch === null) {
                    continue;
                }
                [$recurringAmount, $agreed, $componentCode] = $mismatch;
                $validations[] = new PayrollRunValidation(
                    'warning',
                    self::CODE,
                    'employment',
                    $employmentId,
                    sprintf(
                        '%s: pravidelná složka %s vkládá do mzdy %s Kč měsíčně, ale sjednaná '
                        . 'měsíční mzda v podmínkách pracovního vztahu je %s Kč. Jde-li o omyl, '
                        . 'opravte částku pravidelné složky v Mzdové složky → Pravidelné složky '
                        . 'a obnovte podklady běhu, případně opravte podmínky vztahu. Krácení za '
                        . 'neodpracovanou dobu ani nástup či odchod v průběhu měsíce tuto kontrolu '
                        . 'nevyvolává.',
                        (string) ($employment['full_name'] ?? ''),
                        $componentCode,
                        self::money($recurringAmount),
                        implode(' nebo ', array_map(self::money(...), $agreed)),
                    ),
                    '/payroll/components?tab=recurring&period=' . $period . '&employment=' . $employmentId,
                );
            }
        }

        return $validations;
    }

    /**
     * @param list<array<string,mixed>> $rows řádky jednoho předpisu
     * @return array{0:int,1:list<int>,2:string}|null
     */
    private static function mismatch(array $rows): ?array
    {
        $first = $rows[0];
        $recurringAmount = $first['recurring_amount_minor'];
        if (!is_numeric($recurringAmount)) {
            return null;
        }
        $recurringAmount = (int) $recurringAmount;
        $agreed = [];
        foreach ($rows as $row) {
            if (($row['term_remuneration_type'] ?? null) === 'hourly') {
                return null;
            }
            // Tatáž náhrada jako výpočet předpisu: podmínky bez částky nebo
            // vztah bez podmínek berou měsíční mzdu ze vztahu.
            $value = $row['term_monthly_gross_minor'] ?? $row['employment_monthly_gross_minor'] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                $agreed[(int) $value] = (int) $value;
            }
        }
        if ($agreed === [] || isset($agreed[$recurringAmount])) {
            return null;
        }

        return [$recurringAmount, array_values($agreed), (string) $first['component_code']];
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, $minor % 100 === 0 ? 0 : 2, ',', ' ');
    }
}
