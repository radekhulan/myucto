<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Operace hromadné akce ({@see BulkJobRunner}). Každá položka se zpracuje zvlášť a vždy
 * touž cestou jako úprava jednoho záznamu v aplikaci; operace jen připraví vstup,
 * zavolá tu cestu a výsledek vrátí jako řádek `accounting_bulk_items`.
 *
 * Řádek položky nese `status` (would_change / unchanged / skipped / excluded / applied /
 * failed), `reason_code` a `message` u přeskočení, `before` a `after` (stav před a po)
 * a `fingerprint`, podle kterého ostrý běh i vrácení poznají změnu mezi běhy.
 */
interface BulkOperation
{
    public function key(): string;

    /** Jde ostrý běh vrátit? */
    public function supportsRollback(): bool;

    /**
     * Proč operaci nad daným druhem záznamů uživatel nesmí nebo firma nemůže spustit
     * (NULL = smí). Ptá se požadavku s rolí uživatele, ne role samotné.
     */
    public function unavailableReason(Request $request, int $supplierId, string $target): ?string;

    /**
     * Ověří a znormalizuje parametry operace.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     * @throws BulkActionException
     */
    public function normalizeParams(int $supplierId, string $target, array $raw): array;

    /**
     * Běh nanečisto: co by se se záznamem stalo. Nic trvale nezapíše.
     *
     * @param array<string,mixed> $row záznam z výběru ({@see BulkSelection::rows()})
     * @return array<string,mixed>
     */
    public function evaluate(BulkContext $ctx, array $row): array;

    /**
     * @param array<string,mixed> $dry položka běhu nanečisto
     * @return array<string,mixed>
     */
    public function apply(BulkContext $ctx, array $dry): array;

    /**
     * @param array<string,mixed> $applied položka ostrého běhu
     * @return array<string,mixed>
     */
    public function rollback(BulkContext $ctx, array $applied): array;
}
