<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollAnnualReportRepository;

final class PayrollAnnualReportService
{
    public function __construct(private readonly PayrollAnnualReportRepository $reports) {}

    /**
     * @return array{
     *   year:int,
     *   totals:array{approved_revision_count:int,headcount_person_months:int,gross_minor:?int,employer_cost_minor:?int},
     *   months:list<array{period:string,approved_revision_count:int,headcount:int,gross_minor:?int,employer_cost_minor:?int}>
     * }
     */
    public function report(int $supplierId, int $year): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }

        /** @var array<string,array{approved_revision_count:int,headcount:int,gross_minor:?int,employer_cost_minor:?int}> $months */
        $months = [];
        foreach ($this->reports->approvedCurrentRevisions($supplierId, $year) as $row) {
            $period = substr($row['period_start'], 0, 7);
            if (preg_match('/^' . $year . '-(0[1-9]|1[0-2])$/D', $period) !== 1) {
                throw new \UnexpectedValueException('Schválená revize má neplatné mzdové období.');
            }
            $months[$period] ??= [
                'approved_revision_count' => 0,
                'headcount' => 0,
                'gross_minor' => 0,
                'employer_cost_minor' => 0,
            ];
            $months[$period]['approved_revision_count']++;
            $headcount = $this->nonNegativeInt($row['headcount'] ?? null);
            $gross = $this->integer($row['gross_minor'] ?? null);
            $social = $this->nonNegativeInt($row['employer_social_minor'] ?? null);
            $health = $this->nonNegativeInt($row['employer_health_minor'] ?? null);
            if ($headcount !== null) {
                $months[$period]['headcount'] += $headcount;
            }
            if ($gross === null) {
                $months[$period]['gross_minor'] = null;
            } else {
                if ($months[$period]['gross_minor'] !== null) {
                    $months[$period]['gross_minor'] += $gross;
                }
            }
            if ($gross === null || $social === null || $health === null) {
                $months[$period]['employer_cost_minor'] = null;
            } elseif ($months[$period]['employer_cost_minor'] !== null) {
                $months[$period]['employer_cost_minor'] += $gross + $social + $health;
            }
        }

        $totalGross = 0;
        $totalEmployerCost = 0;
        $headcountPersonMonths = 0;
        $approvedRevisionCount = 0;
        $serializedMonths = [];
        foreach ($months as $period => $month) {
            $approvedRevisionCount += $month['approved_revision_count'];
            $headcountPersonMonths += $month['headcount'];
            if ($month['gross_minor'] === null) {
                $totalGross = null;
            } elseif ($totalGross !== null) {
                $totalGross += $month['gross_minor'];
            }
            if ($month['employer_cost_minor'] === null) {
                $totalEmployerCost = null;
            } elseif ($totalEmployerCost !== null) {
                $totalEmployerCost += $month['employer_cost_minor'];
            }
            $serializedMonths[] = ['period' => $period, ...$month];
        }

        return [
            'year' => $year,
            'totals' => [
                'approved_revision_count' => $approvedRevisionCount,
                'headcount_person_months' => $headcountPersonMonths,
                'gross_minor' => $totalGross,
                'employer_cost_minor' => $totalEmployerCost,
            ],
            'months' => $serializedMonths,
        ];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer >= 0 ? $integer : null;
    }
}
