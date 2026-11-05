<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final readonly class AverageEarningResult
{
    /**
     * @param array<string,int|string|bool|null> $trace
     */
    public function __construct(
        public string $sourceKind,
        public int $averageHourlyMinor,
        public string $supportStatus,
        public array $trace,
    ) {}
}
