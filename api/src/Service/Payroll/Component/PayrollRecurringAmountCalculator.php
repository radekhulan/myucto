<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Calculation\MoneyRateCalculator;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Employment\PayrollHourlyRemuneration;

final class PayrollRecurringAmountCalculator
{
    public function __construct(private readonly MoneyRateCalculator $rates)
    {
    }

    /**
     * @param array<string,mixed> $recurring
     * @return array{status:string,amount_minor:?int,trace:array<string,mixed>,blocker:?string}
     */
    public function calculate(array $recurring, string $periodStart): array
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($month === false || $month->format('Y-m-d') !== $periodStart
            || $month->format('d') !== '01') {
            throw new \InvalidArgumentException('Období musí začínat prvním dnem měsíce.');
        }

        if (PayrollHourlyRemuneration::blocksMonthlyComponent($recurring)) {
            return $this->manualReview(PayrollHourlyRemuneration::MESSAGE);
        }
        $calculation = $this->string($recurring, 'calculation_kind');
        if ($calculation === 'manual_review') {
            return $this->manualReview('Předpis vyžaduje ruční určení částky.');
        }
        if ($calculation === 'fixed_amount') {
            $amount = $this->int($recurring, 'amount_minor');
        } elseif ($calculation === 'employment_gross_basis_points') {
            $gross = $this->nullableInt($recurring, 'monthly_gross_minor');
            if ($gross === null) {
                return $this->manualReview(
                    'Pracovní vztah nemá sjednanou pravidelnou hrubou částku.'
                );
            }
            $basisPoints = $this->int($recurring, 'rate_basis_points');
            $rate = $basisPoints === 10000
                ? '1'
                : '0.' . str_pad((string) $basisPoints, 4, '0', STR_PAD_LEFT);
            try {
                $result = $this->rates->multiply(
                    new Money($gross),
                    DecimalRate::fromString($rate),
                    RoundingMode::HalfUp,
                    'recurring_employment_gross_rate',
                );
            } catch (\OverflowException) {
                return $this->manualReview(
                    'Výpočet procentní složky překračuje podporovaný celočíselný rozsah.'
                );
            }
            $amount = $result->money->minorUnits;
        } else {
            throw new \UnexpectedValueException('Neznámý výpočet opakované složky.');
        }

        $allocation = $this->string($recurring, 'allocation_rule');
        if (in_array($allocation, ['working_days', 'hours', 'manual_review'], true)) {
            return $this->manualReview(
                'Rozpočítání podle pracovních dnů nebo hodin vyžaduje potvrzený časový podklad.'
            );
        }
        $employmentStart = new \DateTimeImmutable(
            $this->string($recurring, 'employment_start'),
        );
        $employmentEndValue = $recurring['employment_end'] ?? null;
        $employmentEnd = $employmentEndValue === null
            ? null
            : new \DateTimeImmutable(
                $this->string($recurring, 'employment_end'),
            );
        $employmentStatus = $this->string(
            $recurring,
            'employment_effective_status',
        );
        $suspendedInMonth = $this->bool(
            $recurring,
            'employment_suspended_in_month',
        );
        if ($employmentStatus === 'suspended' || $suspendedInMonth) {
            return $this->manualReview(
                'Pracovní vztah byl v měsíci pozastaven a předpis vyžaduje ruční časové posouzení.'
            );
        }
        $activeDays = (int) $month->format('t');
        $monthDays = $activeDays;
        if ($allocation === 'calendar_days') {
            $monthEnd = $month->modify('last day of this month');
            $validFrom = new \DateTimeImmutable($this->string($recurring, 'valid_from'));
            $validToValue = $recurring['valid_to'] ?? null;
            $validTo = $validToValue === null
                ? $monthEnd
                : new \DateTimeImmutable($this->string($recurring, 'valid_to'));
            $activeFrom = $validFrom > $month ? $validFrom : $month;
            $activeTo = $validTo < $monthEnd ? $validTo : $monthEnd;
            $activeFrom = $employmentStart > $activeFrom
                ? $employmentStart
                : $activeFrom;
            $activeTo = $employmentEnd !== null && $employmentEnd < $activeTo
                ? $employmentEnd
                : $activeTo;
            $activeDays = $activeTo < $activeFrom
                ? 0
                : ((int) $activeFrom->diff($activeTo)->format('%a')) + 1;
            $amount = $this->multiplyFraction($amount, $activeDays, $monthDays);
        } elseif ($allocation === 'full_month') {
            $monthEnd = $month->modify('last day of this month');
            if ($employmentStart > $month
                || ($employmentEnd !== null && $employmentEnd < $monthEnd)
            ) {
                return $this->manualReview(
                    'Pracovní vztah trvá jen část měsíce a plný měsíční předpis vyžaduje ruční posouzení.'
                );
            }
        } else {
            throw new \UnexpectedValueException('Neznámé rozpočítání opakované složky.');
        }

        if ($amount === PHP_INT_MIN) {
            return $this->manualReview(
                'Částka předpisu překračuje podporovaný celočíselný rozsah.'
            );
        }
        $maximum = $this->nullableInt($recurring, 'maximum_amount_minor');
        $capped = false;
        if ($maximum !== null && abs($amount) > $maximum) {
            $amount = $amount < 0 ? -$maximum : $maximum;
            $capped = true;
        }

        return [
            'status' => 'supported',
            'amount_minor' => $amount,
            'blocker' => null,
            'trace' => [
                'calculation_kind' => $calculation,
                'allocation_rule' => $allocation,
                'active_days' => $activeDays,
                'month_days' => $monthDays,
                'rate_basis_points' => $recurring['rate_basis_points'] ?? null,
                'maximum_amount_minor' => $maximum,
                'maximum_applied' => $capped,
                'rounding' => 'half-up-minor-unit',
            ],
        ];
    }

    private function multiplyFraction(int $amount, int $numerator, int $denominator): int
    {
        $whole = intdiv($amount, $denominator) * $numerator;
        $remainder = ($amount % $denominator) * $numerator;
        return $whole + RoundingMode::HalfUp->roundFraction($remainder, $denominator);
    }

    /**
     * @return array{status:string,amount_minor:null,trace:array<string,mixed>,blocker:string}
     */
    private function manualReview(string $message): array
    {
        return [
            'status' => 'manual_review',
            'amount_minor' => null,
            'blocker' => $message,
            'trace' => [],
        ];
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Předpis nemá textové pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $key): int
    {
        return $this->nullableInt($row, $key)
            ?? throw new \UnexpectedValueException("Předpis nemá číselné pole {$key}.");
    }

    /** @param array<string,mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("Předpis nemá číselné pole {$key}.");
    }

    /** @param array<string,mixed> $row */
    private function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }
        throw new \UnexpectedValueException("Předpis nemá boolean pole {$key}.");
    }
}
