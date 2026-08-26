<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final readonly class LeaveEntitlementResult
{
    /**
     * @param array<string,mixed> $trace
     */
    public function __construct(
        public int $weeklyMinutes,
        public int $workedWeekMultiples,
        public int $entitlementMinutes,
        public string $supportStatus,
        public array $trace,
    ) {}
}
