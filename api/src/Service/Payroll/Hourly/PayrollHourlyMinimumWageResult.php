<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Hourly;

final readonly class PayrollHourlyMinimumWageResult
{
    /** @param array<string,mixed> $claim @param list<array<string,mixed>> $blockers @param array<string,mixed> $assessmentEvidence */
    public function __construct(
        public bool $complete,
        public array $claim,
        public array $blockers,
        public array $assessmentEvidence,
        public string $evidenceHash,
    ) {}
}
