<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;

final class NonprofitTaxCalculator
{
    public function __construct(private readonly J6AuthoritativeCalculator $authoritative) {}

    public static function forConnection(Connection $db): self
    {
        return new self(new J6AuthoritativeCalculator($db));
    }

    public function calculate(array $snapshot, array $assessment): array
    {
        return $this->authoritative->calculateLegacyArray($snapshot, $assessment);
    }

    public function fundingIncomePools(array $result, array $snapshot, array $assessment): array
    {
        return $this->authoritative->fundingIncomePools($result, $snapshot, $assessment);
    }

    public function fundingCostPools(array $result, array $assessment): array
    {
        return $this->authoritative->fundingCostPools($result, $assessment);
    }
}
