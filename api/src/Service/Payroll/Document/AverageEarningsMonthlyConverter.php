<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Repository\Payroll\PayrollEmploymentExitRevisionRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyEmployeeSocialInsuranceInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyHealthInsuranceInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Formy průměrného výdělku podle § 356 zákoníku práce.
 *
 * Odst. 2: hrubý měsíční průměr = hodinový průměr × týdenní pracovní doba
 * uplatněná v rozhodném období × 4,348; při změně týdenní doby se váží
 * kalendářními dny a výsledek se zaokrouhlí na tisíciny nahoru.
 *
 * Odst. 3: čistý měsíční průměr = hrubý měsíční průměr − pojistné na sociální
 * zabezpečení − pojistné na zdravotní pojištění − záloha na daň, vše podle
 * podmínek a sazeb platných pro zaměstnance v měsíci, v němž se čistý výdělek
 * zjišťuje. Sazby a slevy se proto berou z účinného rulesetu k tomuto měsíci,
 * nikdy z rozhodného období a nikdy z literálu v kódu.
 *
 * Vše, co by musel odhadnout (neúplná evidence úvazku, srážková daň, slevy nad
 * rámec slevy na poplatníka, minimální vyměřovací základ zdravotního pojištění),
 * končí pojmenovanou fail-closed překážkou.
 */
final class AverageEarningsMonthlyConverter
{
    public function __construct(
        private readonly PayrollEmploymentExitRevisionRepository $revisions,
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly MonthlyEmployeeSocialInsuranceCalculator $social,
        private readonly MonthlyHealthInsuranceCalculator $health,
        private readonly MonthlyAdvanceTaxCalculator $tax,
    ) {}

    /**
     * @param array<string,mixed> $averageSnapshot schválený snapshot MZ-07
     */
    public function convert(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        array $averageSnapshot,
        string $determinationDate,
        bool $withNet,
    ): AverageEarningsMonthlyConversion {
        $hourly = $averageSnapshot['average_hourly_minor'] ?? null;
        if (!is_int($hourly) || $hourly <= 0) {
            throw new EmploymentExitReadinessException(
                'average_hourly_not_positive',
                'Schválený hodinový průměr není kladná částka.',
            );
        }
        $decisiveFrom = self::text($averageSnapshot, 'decisive_from');
        $decisiveTo = self::text($averageSnapshot, 'decisive_to');
        if ($decisiveTo < $decisiveFrom) {
            throw new EmploymentExitReadinessException(
                'average_decisive_period_invalid',
                'Rozhodné období schváleného průměru není platné.',
            );
        }

        $intervals = $this->weeklyHoursIntervals(
            $supplierId,
            $employmentId,
            $decisiveFrom,
            $decisiveTo,
        );
        $weeklyHoursMilli = AverageEarningsMonthlyMath::weightedWeeklyHoursMilli(
            $intervals,
        );
        if ($weeklyHoursMilli === null) {
            throw new EmploymentExitReadinessException(
                'weekly_hours_evidence_missing',
                'Rozhodné období nemá kalendářní dny pro vážení úvazku.',
            );
        }

        $thresholds = $this->rulesets->forCalculation(
            PayrollRulesetDomain::EmploymentThresholds,
            $determinationDate,
        );
        $minimumHourly = self::moneyParameter(
            $thresholds,
            'minimum_wage.hourly_40h_week',
        );
        $appliedHourly = max($hourly, $minimumHourly);
        $grossMonthly = AverageEarningsMonthlyMath::grossMonthlyMinorUnits(
            $appliedHourly,
            $weeklyHoursMilli,
        );

        $trace = [
            'rule' => 'labour-code-356',
            'decisive_from' => $decisiveFrom,
            'decisive_to' => $decisiveTo,
            'determination_date' => $determinationDate,
            'month_coefficient' => '4.348',
            'minimum_wage_hourly_minor_units' => $minimumHourly,
            'minimum_wage_ruleset_id' => $thresholds->id,
            'gross_rounding' => 'half-up-to-minor-unit',
        ];

        if (!$withNet) {
            return new AverageEarningsMonthlyConversion(
                approvedHourlyMinorUnits: $hourly,
                appliedHourlyMinorUnits: $appliedHourly,
                minimumWageFloorApplied: $appliedHourly !== $hourly,
                weeklyHoursMilli: $weeklyHoursMilli,
                weeklyHoursIntervals: $intervals,
                grossMonthlyMinorUnits: $grossMonthly,
                socialInsuranceMinorUnits: null,
                healthInsuranceMinorUnits: null,
                advanceTaxMinorUnits: null,
                netMonthlyExactMinorUnits: null,
                netMonthlyMinorUnits: null,
                trace: $trace,
            );
        }

        foreach ($intervals as $interval) {
            if ($interval['tax_regime'] !== 'advance') {
                throw new EmploymentExitReadinessException(
                    'average_earnings_tax_regime_not_advance',
                    'Vztah není v rozhodném období celý v režimu zálohové '
                        . 'daně, přepočet na čistý měsíční výdělek podle '
                        . '§ 356 odst. 3 pro něj není ověřený.',
                );
            }
        }
        $conditions = $this->netConditions(
            $supplierId,
            $employeeId,
            $determinationDate,
        );

        $socialResult = $this->social->calculate(
            $determinationDate,
            new MonthlyEmployeeSocialInsuranceInput(
                assessmentBaseMinorUnits: $grossMonthly,
                yearToDateAssessmentBaseBeforeMonthMinorUnits: 0,
                participates: true,
                workingPensionerDiscount: $conditions['working_pensioner_discount'],
            ),
        );
        $healthRuleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::HealthInsurance,
            $determinationDate,
        );
        $healthMinimum = self::moneyParameter(
            $healthRuleset,
            'minimum_assessment_base.monthly',
        );
        if ($grossMonthly < $healthMinimum) {
            throw new EmploymentExitReadinessException(
                'average_earnings_below_health_minimum_base',
                'Hrubý měsíční průměr nedosahuje minimálního vyměřovacího '
                    . 'základu zdravotního pojištění. Doplatek do minima '
                    . 'závisí na důvodech, které aplikace pro tento přepočet '
                    . 'neumí doložit — potvrzení vystavte mimo aplikaci.',
            );
        }
        $healthResult = $this->health->calculate(
            $determinationDate,
            new MonthlyHealthInsuranceInput(
                assessmentBaseMinorUnits: $grossMonthly,
                participates: true,
            ),
        );
        $taxResult = $this->tax->calculate(
            $determinationDate,
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: $grossMonthly,
                signedDeclaration: $conditions['signed_declaration'],
                claimTaxpayerCredit: $conditions['taxpayer_credit'],
            ),
        );

        $exactNet = $grossMonthly
            - $socialResult->employeeContributionMinorUnits
            - $healthResult->employeeContributionMinorUnits
            - $taxResult->taxAfterCreditsMinorUnits;
        if ($exactNet <= 0) {
            throw new EmploymentExitReadinessException(
                'average_earnings_net_not_positive',
                'Přepočet podle § 356 odst. 3 nevychází kladně, potvrzení '
                    . 'nelze vydat bez ručního posouzení.',
            );
        }

        $trace['social_ruleset_id'] = $socialResult->rulesetId;
        $trace['health_ruleset_id'] = $healthResult->rulesetId;
        $trace['tax_ruleset_id'] = $taxResult->rulesetId;
        $trace['signed_declaration'] = $conditions['signed_declaration'];
        $trace['taxpayer_credit'] = $conditions['taxpayer_credit'];
        $trace['working_pensioner_discount'] =
            $conditions['working_pensioner_discount'];
        $trace['net_rounding'] = 'half-up-to-whole-czk';

        return new AverageEarningsMonthlyConversion(
            approvedHourlyMinorUnits: $hourly,
            appliedHourlyMinorUnits: $appliedHourly,
            minimumWageFloorApplied: $appliedHourly !== $hourly,
            weeklyHoursMilli: $weeklyHoursMilli,
            weeklyHoursIntervals: $intervals,
            grossMonthlyMinorUnits: $grossMonthly,
            socialInsuranceMinorUnits:
                $socialResult->employeeContributionMinorUnits,
            healthInsuranceMinorUnits:
                $healthResult->employeeContributionMinorUnits,
            advanceTaxMinorUnits: $taxResult->taxAfterCreditsMinorUnits,
            netMonthlyExactMinorUnits: $exactNet,
            netMonthlyMinorUnits:
                AverageEarningsMonthlyMath::roundHalfUpToWholeCzk($exactNet),
            trace: $trace,
        );
    }

    /**
     * @return list<array{
     *   term_id:int,
     *   effective_from:string,
     *   effective_to:string,
     *   weekly_hours_milli:int,
     *   calendar_days:int,
     *   tax_regime:string
     * }>
     */
    private function weeklyHoursIntervals(
        int $supplierId,
        int $employmentId,
        string $decisiveFrom,
        string $decisiveTo,
    ): array {
        $rows = $this->revisions->lockDecisivePeriodTerms(
            $supplierId,
            $employmentId,
            $decisiveFrom,
            $decisiveTo,
        );
        if ($rows === []) {
            throw new EmploymentExitReadinessException(
                'weekly_hours_evidence_missing',
                'Pro rozhodné období nejsou doložené smluvní podmínky '
                    . 's týdenní pracovní dobou.',
            );
        }
        $result = [];
        $cursor = $decisiveFrom;
        foreach ($rows as $row) {
            $from = max($row['effective_from'], $decisiveFrom);
            $to = min($row['effective_to'] ?? $decisiveTo, $decisiveTo);
            if ($from !== $cursor || $to < $from) {
                throw new EmploymentExitReadinessException(
                    'weekly_hours_evidence_missing',
                    'Evidence týdenní pracovní doby v rozhodném období '
                        . 'má mezeru nebo překryv.',
                );
            }
            $hours = $row['weekly_hours'];
            if ($hours === null) {
                throw new EmploymentExitReadinessException(
                    'weekly_hours_evidence_missing',
                    'Smluvní podmínky v rozhodném období nemají vyplněnou '
                        . 'týdenní pracovní dobu.',
                );
            }
            $milli = AverageEarningsMonthlyMath::weeklyHoursMilli($hours);
            if ($milli === null) {
                throw new EmploymentExitReadinessException(
                    'weekly_hours_evidence_missing',
                    'Týdenní pracovní doba v rozhodném období není kladná.',
                );
            }
            $result[] = [
                'term_id' => $row['id'],
                'effective_from' => $from,
                'effective_to' => $to,
                'weekly_hours_milli' => $milli,
                'calendar_days' => AverageEarningsMonthlyMath::calendarDays(
                    $from,
                    $to,
                ),
                'tax_regime' => $row['tax_regime'],
            ];
            $cursor = (new \DateTimeImmutable($to))
                ->modify('+1 day')
                ->format('Y-m-d');
        }
        $expectedNext = (new \DateTimeImmutable($decisiveTo))
            ->modify('+1 day')
            ->format('Y-m-d');
        if ($cursor !== $expectedNext) {
            throw new EmploymentExitReadinessException(
                'weekly_hours_evidence_missing',
                'Evidence týdenní pracovní doby nepokrývá celé rozhodné období.',
            );
        }

        return $result;
    }

    /**
     * @return array{
     *   signed_declaration:bool,
     *   taxpayer_credit:bool,
     *   working_pensioner_discount:bool
     * }
     */
    private function netConditions(
        int $supplierId,
        int $employeeId,
        string $determinationDate,
    ): array {
        $snapshot = $this->evidence->snapshot(
            $supplierId,
            $employeeId,
            $determinationDate,
        );
        if ($snapshot === null) {
            throw new EmploymentExitReadinessException(
                'statutory_evidence_snapshot_missing',
                'Ke dni zjištění chybí zákonná evidence zaměstnance.',
            );
        }
        $incomeTax = self::section($snapshot, 'income_tax');
        $declaration = $incomeTax['declaration'] ?? null;
        if (!is_array($declaration)) {
            throw new EmploymentExitReadinessException(
                'tax_declaration_evidence_missing',
                'K měsíci zjištění chybí evidence daňového prohlášení.',
            );
        }
        $status = $declaration['status'] ?? null;
        if ($status === 'unverified' || !is_string($status)) {
            throw new EmploymentExitReadinessException(
                'tax_declaration_evidence_unverified',
                'Evidence daňového prohlášení k měsíci zjištění není ověřená.',
            );
        }
        // Dítě „N" (`credit_status = claimed_by_other`) zvýhodnění u tohoto
        // zaměstnance nezakládá — uplatňuje ho jiná osoba — takže čistý
        // výdělek nijak neovlivní a potvrzení kvůli němu stát nemusí.
        $children = is_array($incomeTax['child_claims'] ?? null)
            ? array_filter(
                $incomeTax['child_claims'],
                static fn (mixed $claim): bool => !is_array($claim)
                    || ($claim['credit_status'] ?? 'claimed') !== 'claimed_by_other',
            )
            : [];
        if ($children !== []) {
            throw new EmploymentExitReadinessException(
                'average_earnings_child_credit_not_supported',
                'Zaměstnanec uplatňuje daňové zvýhodnění na dítě. Jeho '
                    . 'promítnutí do průměrného čistého výdělku aplikace '
                    . 'nemá ověřené — potvrzení vystavte mimo aplikaci.',
            );
        }
        $taxpayerCredit = false;
        $credits = $incomeTax['credit_claims'] ?? [];
        if (is_array($credits)) {
            foreach ($credits as $credit) {
                if (!is_array($credit)) {
                    continue;
                }
                if (($credit['evidence_status'] ?? null) === 'unverified') {
                    throw new EmploymentExitReadinessException(
                        'tax_credit_evidence_unverified',
                        'Evidence daňové slevy k měsíci zjištění není ověřená.',
                    );
                }
                if (($credit['credit_kind'] ?? null) === 'taxpayer') {
                    $taxpayerCredit = true;
                    continue;
                }
                throw new EmploymentExitReadinessException(
                    'average_earnings_tax_credit_not_supported',
                    'Zaměstnanec uplatňuje slevu nad rámec základní slevy na '
                        . 'poplatníka. Její promítnutí do průměrného čistého '
                        . 'výdělku aplikace nemá ověřené.',
                );
            }
        }
        $signed = $status === 'signed';

        $social = self::section($snapshot, 'social');
        $discount = $social['working_pensioner_discount'] ?? null;
        $discountActive = false;
        if (is_array($discount)) {
            $discountStatus = $discount['status'] ?? null;
            if ($discountStatus === 'unverified') {
                throw new EmploymentExitReadinessException(
                    'working_pensioner_discount_evidence_unverified',
                    'Evidence slevy pracujícího důchodce k měsíci zjištění '
                        . 'není ověřená.',
                );
            }
            $discountActive = $discountStatus === 'verified';
        }

        return [
            'signed_declaration' => $signed,
            'taxpayer_credit' => $signed && $taxpayerCredit,
            'working_pensioner_discount' => $discountActive,
        ];
    }

    private static function moneyParameter(
        PayrollRulesetVersion $ruleset,
        string $key,
    ): int {
        $parameter = $ruleset->parameter($key);
        if ($parameter->type !== 'money_minor' || !is_int($parameter->value)) {
            throw new \UnexpectedValueException(
                "Parametr rulesetu {$key} není peněžní částka.",
            );
        }

        return $parameter->value;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function section(array $snapshot, string $key): array
    {
        $value = $snapshot[$key] ?? null;
        if (!is_array($value)) {
            throw new EmploymentExitReadinessException(
                'statutory_evidence_snapshot_missing',
                'Zákonná evidence zaměstnance nemá očekávanou strukturu.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \DomainException(
                "Schválený průměr nemá pole {$field}.",
            );
        }

        return $value;
    }
}
