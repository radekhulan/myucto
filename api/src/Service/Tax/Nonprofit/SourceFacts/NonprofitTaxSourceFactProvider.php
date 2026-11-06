<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\SourceFacts;

interface NonprofitTaxSourceFactProvider
{
    public function schema(): string;

    /** @param list<array<string,mixed>> $pageRows @return array<int,array<string,mixed>> */
    public function capture(int $supplierId, array $pageRows, bool $currentRead): array;
}
