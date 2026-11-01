<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\Calculation\HealthMinimumTopUpPayer;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceInput;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzPayrollTakeover;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Návrh ručního zadání převzatých mezd z přijatých hlášení JMHZ. Nic neukládá:
 * účetní návrh ve formuláři zkontroluje a uloží sama.
 *
 * Doplňuje jen prázdná pole, vyplněná čísla nepřepisuje. Hlášení nenese zdravotní
 * pojištění zaměstnavatele, zdravotní základ ani (u některých programů) čistou
 * mzdu; ty se dopočítají a řádek je vyjmenuje v `derived`. Dopočet proběhne, jen
 * když má osoba v měsíci jediný formulář: podíl osoby na souběžné vztahy rozdělit
 * nejde a částka by se tvářila jako doložená.
 */
final readonly class PayrollTakeoverFiledFormPrefill
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private JmhzExternalSubmissionStore $forms,
        private PayrollTakeoverManualEntryService $manual,
        private MonthlyHealthInsuranceCalculator $health,
        private PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @return array{rows:list<array<string,mixed>>,missing_months:list<int>}
     */
    public function suggest(int $supplierId, int $employeeId, int $year): array
    {
        $byKey = [];
        $perPersonMonth = [];
        foreach ($this->forms->effectiveMonthlyForms($supplierId, self::ENVIRONMENT, $year) as $entry) {
            $month = (int) substr($entry['period'], 5, 2);
            $byKey[$entry['employment_id'] . ':' . $month] = $entry['form'];
            if ($entry['employee_id'] === $employeeId) {
                $perPersonMonth[$month] = ($perPersonMonth[$month] ?? 0) + 1;
            }
        }

        $rows = [];
        $missing = [];
        foreach ($this->manual->form($supplierId, $employeeId, $year)['rows'] as $row) {
            $month = (int) $row['month'];
            $form = $byKey[$row['employment_id'] . ':' . $month] ?? null;
            if ($form === null) {
                $missing[$month] = $month;
                continue;
            }
            $rows[] = $this->row(
                $row,
                $form,
                sprintf('%04d-%02d-01', $year, $month),
                ($perPersonMonth[$month] ?? 0) === 1,
            );
        }

        return ['rows' => $rows, 'missing_months' => array_values($missing)];
    }

    /**
     * @param array<string,mixed> $row řádek formuláře ručního zadání
     * @return array<string,mixed> týž řádek s doplněnými poli a seznamem `derived`
     */
    public function row(array $row, JmhzReportForm $form, string $periodStart, bool $onlyFormOfPerson): array
    {
        $next = $row;
        $fill = static function (string $field, ?int $crowns) use (&$next): void {
            if ($crowns !== null && (int) ($next[$field] ?? 0) === 0) {
                $next[$field] = $crowns * 100;
            }
        };
        $fill('gross_minor', $form->hasSummary ? ($form->incomeTotal ?? $form->taxableIncome) : $form->taxableIncome);
        $fill('social_base_minor', $form->socialBase);
        $fill('employee_social_minor', $form->employeeSocial);
        $fill('employer_social_minor', $form->employerSocial);
        if ($form->hasSummary) {
            $fill('advance_base_minor', $form->advance['base'] ?? null);
            $fill('advance_tax_minor', $form->advance['after_credits'] ?? null);
            $fill('tax_bonus_minor', $form->advance['bonus'] ?? null);
            $fill('withholding_base_minor', $form->withholding['base'] ?? null);
            $fill('withholding_tax_minor', $form->withholding['tax'] ?? null);
            $fill('employee_health_minor', $form->employeeHealth);
            $fill('employer_health_minor', $form->employerHealth);
            $fill('net_minor', $form->netWage);
        }
        if ((int) ($next['insurance_days'] ?? 0) === 0) {
            $next['insurance_days'] = min(31, (int) ($form->eldp['insurance_days'] ?? 0));
        }
        if ((int) ($next['excluded_days'] ?? 0) === 0) {
            $next['excluded_days'] = min(31, (int) ($form->eldp['excluded_days'] ?? 0));
        }
        if ((int) ($next['worked_days_hundredths'] ?? 0) === 0) {
            $next['worked_days_hundredths'] = ($form->workedDays ?? 0) * 100;
        }
        if ((int) ($next['worked_minutes'] ?? 0) === 0) {
            $next['worked_minutes'] = JmhzPayrollTakeover::minutes($form->workedMillihours);
        }

        $derived = [];
        if ($onlyFormOfPerson && $form->hasSummary) {
            $derived = $this->deriveHealth($next, $form, $periodStart);
            if ($form->netWage === null && (int) ($next['net_minor'] ?? 0) === 0 && (int) $next['gross_minor'] > 0) {
                $next['net_minor'] = (int) $next['gross_minor']
                    - (int) $next['advance_tax_minor'] - (int) $next['withholding_tax_minor']
                    - (int) $next['employee_social_minor'] - (int) $next['employee_health_minor']
                    + (int) $next['tax_bonus_minor'];
                $derived[] = 'net_minor';
            }
        }
        // Výši srážek hlášení nenese, k výplatě jde doplnit jen bez nich.
        if ($form->deductionsRecorded !== true && (int) ($next['net_payable_minor'] ?? 0) === 0) {
            $next['net_payable_minor'] = (int) ($next['net_minor'] ?? 0);
        }
        $next['derived'] = $derived;

        return $next;
    }

    /**
     * Rozpad zdravotního pojištění týmž výpočtem jako vlastní mzdový běh.
     *
     * @param array<string,mixed> $next
     * @return list<string> dopočítaná pole
     */
    private function deriveHealth(array &$next, JmhzReportForm $form, string $periodStart): array
    {
        $employeeReported = (int) ($next['employee_health_minor'] ?? 0);
        $base = (int) ($next['social_base_minor'] ?? 0);
        if ($form->employeeHealth === null || $employeeReported <= 0 || $base <= 0) {
            return [];
        }
        $standard = $this->health->calculate($periodStart, new MonthlyHealthInsuranceInput($base, true));
        $derived = [];
        if ($form->employerHealth === null && (int) ($next['employer_health_minor'] ?? 0) === 0) {
            $next['employer_health_minor'] = $standard->employerStandardContributionMinorUnits;
            $derived[] = 'employer_health_minor';
        }
        $topUp = max(0, $employeeReported - $standard->employeeStandardContributionMinorUnits);
        if ($topUp > 0 && (int) ($next['health_minimum_top_up_minor'] ?? 0) === 0) {
            $next['health_minimum_top_up_minor'] = $topUp;
            $derived[] = 'health_minimum_top_up_minor';
        }
        if ((int) ($next['health_base_minor'] ?? 0) === 0) {
            $next['health_base_minor'] = $topUp > 0 ? $this->baseWithMinimum($base, $employeeReported, $periodStart) : $base;
            $derived[] = 'health_base_minor';
        }

        return $derived;
    }

    /** Minimální základ, jen když z něj hlášené pojistné přesně vychází. */
    private function baseWithMinimum(int $base, int $employeeReported, string $periodStart): int
    {
        $minimum = $this->rulesets
            ->forCalculation(PayrollRulesetDomain::HealthInsurance, $periodStart)
            ->parameter('minimum_assessment_base.monthly')
            ->value;
        if (!is_int($minimum) || $minimum <= $base) {
            return $base;
        }
        $withMinimum = $this->health->calculate($periodStart, new MonthlyHealthInsuranceInput(
            $base,
            true,
            $minimum,
            HealthMinimumTopUpPayer::Employee,
        ));

        return $withMinimum->employeeContributionMinorUnits === $employeeReported ? $minimum : $base;
    }
}
