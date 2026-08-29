<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;

final class PayrollAbsenceValidator
{
    /**
     * `unexcused` = neomluvené zameškání směny nebo její části (§ 223 odst. 1
     * ZP, o kterém rozhoduje zaměstnavatel podle § 348 odst. 3). Je to JEDINÝ
     * druh absence, o který se smí krátit dovolená — kniha dovolené proti němu
     * krácení poměřuje. Vědomě je oddělený od `employee_obstacle`: překážka
     * v práci je nepřítomnost OMLUVENÁ a krátit se za ni nesmí.
     */
    private const TYPES = [
        'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
        'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
        'employer_obstacle', 'compensatory_time_off', 'unexcused', 'other',
    ];

    private const DOMAIN = PayrollRulesetDomain::CompensationAverages;

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function absence(array $body): array
    {
        $employmentId = $this->positiveInt($body, 'employment_id');
        $type = trim((string) ($body['absence_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Druh absence není platný.');
        }
        $from = $this->date($body['date_from'] ?? null, 'date_from');
        $to = $this->date($body['date_to'] ?? null, 'date_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Konec absence nesmí předcházet začátku.');
        }
        PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $from);
        PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $to);
        $timezone = trim((string) ($body['timezone_name'] ?? 'Europe/Prague'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Časové pásmo není platné.');
        }
        $policy = match ($type) {
            'dpn', 'quarantine' => 'dpn',
            'vacation' => 'average_100',
            'employee_obstacle', 'employer_obstacle' => 'statutory_manual_review',
            // Náhradní volno: za dobu jeho čerpání mzda nepřísluší
            // (§ 114 odst. 3 zákoníku práce) — přesčas se už zaplatil mzdou,
            // volnem se nahrazuje jen příplatek. Proto `none`, ne přehlédnutí.
            'compensatory_time_off' => 'none',
            // Za neomluveně zameškanou dobu mzda ani náhrada nepřísluší —
            // zaměstnanec v ní nepracoval a žádná překážka v práci to nekryje.
            'unexcused' => 'none',
            default => 'none',
        };
        if (in_array($policy, ['average_100', 'statutory_manual_review'], true)
            && $this->calendarQuarter($from) !== $this->calendarQuarter($to)
        ) {
            throw new \InvalidArgumentException(
                'Absenci s náhradou mzdy rozděl podle kalendářních čtvrtletí.'
            );
        }
        $averageId = $this->nullablePositiveInt($body['average_snapshot_id'] ?? null, 'average_snapshot_id');
        if (in_array($type, ['dpn', 'quarantine', 'vacation', 'employee_obstacle', 'employer_obstacle'], true)
            && $averageId === null
        ) {
            throw new \InvalidArgumentException('Tento druh absence vyžaduje schválený snapshot průměru.');
        }
        return [
            'employment_id' => $employmentId,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'timezone_name' => $timezone,
            'partial_first_minutes' => $this->nullablePositiveInt(
                $body['partial_first_minutes'] ?? null,
                'partial_first_minutes',
            ),
            'partial_last_minutes' => $this->nullablePositiveInt(
                $body['partial_last_minutes'] ?? null,
                'partial_last_minutes',
            ),
            'note' => $this->nullableText($body['note'] ?? null, 1000),
            'compensation_policy' => $policy,
            // Sazba náhrady při DPN je zákonná a mění se — bere se z rulesetu,
            // ať absence a výpočet náhrady nikdy nepracují s jiným číslem.
            // 10 000 bp u ostatních politik je definice „average_100", ne sazba.
            'compensation_rate_basis_points' => match ($policy) {
                'none' => null,
                'dpn' => AbsenceRuleset::forDate($this->rulesets, $from)
                    ->compensationRateBasisPoints(),
                default => 10_000,
            },
            'average_snapshot_id' => $averageId,
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function average(array $body): array
    {
        $year = $this->positiveInt($body, 'applicable_year');
        $quarter = $this->positiveInt($body, 'applicable_quarter');
        if ($quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $year);
        $from = $this->date($body['decisive_from'] ?? null, 'decisive_from');
        $to = $this->date($body['decisive_to'] ?? null, 'decisive_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Rozhodné období průměru není platné.');
        }
        $applicationStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-01',
            $year,
            (($quarter - 1) * 3) + 1,
        ));
        $expectedFrom = $applicationStart->modify('-3 months')->format('Y-m-d');
        $expectedTo = $applicationStart->modify('-1 day')->format('Y-m-d');
        if ($from !== $expectedFrom || $to !== $expectedTo) {
            throw new \InvalidArgumentException(
                "Rozhodné období pro {$year}/Q{$quarter} musí být {$expectedFrom} až {$expectedTo}."
            );
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $from,
            'decisive_to' => $to,
            'gross_earnings_minor' => $this->nonNegativeInt($body, 'gross_earnings_minor'),
            'longer_period_allocated_minor' => $this->nonNegativeInt($body, 'longer_period_allocated_minor'),
            'worked_minutes' => $this->nonNegativeInt($body, 'worked_minutes'),
            'worked_days' => $this->nonNegativeInt($body, 'worked_days'),
            'probable_hourly_minor' => $this->nullablePositiveInt(
                $body['probable_hourly_minor'] ?? null,
                'probable_hourly_minor',
            ),
            'rationale' => $this->nullableText($body['rationale'] ?? null, 1000),
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function entitlement(array $body): array
    {
        $rationale = trim((string) ($body['rationale'] ?? ''));
        if ($rationale === '' || mb_strlen($rationale) > 1000) {
            throw new \InvalidArgumentException('Odůvodnění nároku je povinné a smí mít nejvýše 1000 znaků.');
        }
        $year = $this->positiveInt($body, 'leave_year');
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $year);
        $minimumWeeks = AbsenceRuleset::forYear($this->rulesets, $year)
            ->leaveStatutoryMinimumWeeks();
        $entitlementWeeks = $this->positiveInt($body, 'entitlement_weeks');
        if ($entitlementWeeks < $minimumWeeks) {
            throw new \InvalidArgumentException(
                "Výměra dovolené nesmí být nižší než zákonné minimum {$minimumWeeks} týdny."
            );
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'leave_year' => $year,
            'weekly_minutes' => $this->positiveInt($body, 'weekly_minutes'),
            'entitlement_weeks' => $entitlementWeeks,
            'continuous_calendar_days' => $this->positiveInt($body, 'continuous_calendar_days'),
            'worked_equivalent_minutes' => $this->positiveInt($body, 'worked_equivalent_minutes'),
            'rationale' => $rationale,
        ];
    }

    /**
     * Ruční položka knihy dovolené smí vzniknout jen v roce, který má účinný
     * ruleset, a její datum účinnosti musí do téhož roku patřit.
     */
    public function assertLeaveEntryPeriod(int $leaveYear, string $effectiveDate): void
    {
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $leaveYear);
        if ((int) substr($effectiveDate, 0, 4) !== $leaveYear) {
            throw new \InvalidArgumentException(
                'Datum účinnosti položky dovolené musí ležet v roce nároku.'
            );
        }
        PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $effectiveDate);
    }

    private function date(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException("{$field} musí být platné datum YYYY-MM-DD.");
        }
        return $text;
    }

    /** @param array<string,mixed> $body */
    private function positiveInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function nonNegativeInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
        }
        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return (int) $result;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("Text smí mít nejvýše {$max} znaků.");
        }
        return $text;
    }

    private function calendarQuarter(string $date): string
    {
        $value = new \DateTimeImmutable($date);
        return $value->format('Y') . '-Q' . (intdiv((int) $value->format('n') - 1, 3) + 1);
    }
}
