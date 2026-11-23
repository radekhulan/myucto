<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

final readonly class PayrollHourlyWageResult
{
    /** @param list<array<string,mixed>> $claims @param list<array<string,mixed>> $blockers @param list<array<string,mixed>> $entitlements */
    public function __construct(
        public bool $complete,
        public array $claims,
        public array $blockers,
        public string $evidenceHash,
        public array $entitlements = [],
        public array $includedAllocations = [],
    ) {}
}
