<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

/** Jeden sloupcový krok skutečného FK z information_schema. */
final readonly class TenantSchemaForeignKeyInventory
{
    public function __construct(
        public string $column,
        public string $referencedTable,
        public string $referencedColumn,
    ) {
        foreach ([$column, $referencedTable, $referencedColumn] as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
                throw new \InvalidArgumentException('Inventura cizího klíče obsahuje neplatný identifikátor.');
            }
        }
    }
}
