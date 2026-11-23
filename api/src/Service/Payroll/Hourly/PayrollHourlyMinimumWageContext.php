<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;

/** Podklady sestavuje server; fond nikdy nenahrazuje skutečně odpracovanou dobu. */
final readonly class PayrollHourlyMinimumWageContext
{
    /**
     * @param list<array{term_id:int,date_from:string,date_to:string,regular_worked_duration_units:int,stated_weekly_minutes:?int,minimum_base40_minor:?int,minimum_monthly_minor?:int,full_month_fund_duration_units?:int,contractual_weekly_duration_units?:int}> $parts
     * @param list<array<string,mixed>> $otherEarnings Schválené čisté částky po opravách, bez generovaných nároků tohoto výpočtu.
     * @param list<array<string,mixed>> $includedAllocations Přesné výstupy hodinového kalkulátoru.
     * @param array{reference:string,decided_on:string,valid_from:string,valid_to:?string}|null $advanceProof
     */
    public function __construct(
        public int $employmentId,
        public string $periodStart,
        public string $employmentType,
        public string $assessment,
        public array $parts,
        public ?int $ordinaryPayableMinor,
        public array $otherEarnings,
        public array $includedAllocations,
        public PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
        public ?array $advanceProof = null,
    ) {}
}
