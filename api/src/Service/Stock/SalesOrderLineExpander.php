<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

interface SalesOrderLineExpander
{
    /**
     * @param array<string,mixed> $line
     * @return array{product_snapshot:array<string,mixed>,component_snapshot:list<array<string,mixed>>}
     */
    public function expand(int $supplierId, array $line, string $currencyCode): array;
}
