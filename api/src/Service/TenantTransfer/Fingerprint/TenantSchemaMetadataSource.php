<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

interface TenantSchemaMetadataSource
{
    /** @return list<TenantSchemaTableInventory> */
    public function inventory(): array;

    /**
     * @param list<string> $tableNames
     * @return list<array<string,mixed>>
     */
    public function describe(array $tableNames): array;
}
