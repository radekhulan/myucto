<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;

/** Pouze doložené vstupy; fond není náhradním zdrojem odpracovaných hodin. */
final readonly class PayrollHourlyWageContext
{
    /**
     * @param list<array<string,mixed>> $terms účinné verze podmínek vztahu
     * @param list<array<string,mixed>> $segments rozpad schválené pracovní doby
     * @param array<string,mixed>|null $workedTimeEvidence kanonický zdroj etapy 2
     * @param list<array<string,mixed>> $monthlyParts doložený fond a zachovaná část měsíční mzdy
     * @param array<string,mixed>|null $entitlementContext doložené politiky a další nároky
     */
    public function __construct(
        public int $employmentId,
        public string $periodStart,
        public string $employmentType,
        public array $terms,
        public array $segments,
        public ?array $workedTimeEvidence,
        public PayrollWageRounding $rounding = PayrollWageRounding::Ceil,
        public array $monthlyParts = [],
        public ?array $entitlementContext = null,
        public bool $settlementOnly = false,
    ) {}
}
