<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

interface FulfillmentSourceProvider
{
    public function type(): string;

    /** @return array{source_snapshot:array<string,mixed>,lines:list<array<string,mixed>>,claimed_stock_document_id:?int} */
    public function loadForClaim(int $supplierId, string $sourceId): array;
}
