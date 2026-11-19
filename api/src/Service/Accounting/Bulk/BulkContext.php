<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Úloha, ve které operace zpracovává položku: firma, kdo úlohu spustil, druh záznamů,
 * parametry a syntetický požadavek s jeho rolí ({@see \MyInvoice\Security\CrossSupplierAuthorization::backgroundRequestFor()}),
 * podle kterého se ověřují práva k jednotlivým záznamům. Stránka Přeúčtovat požadavek
 * nepotřebuje (`request` NULL).
 */
final class BulkContext
{
    /** @param array<string,mixed> $params */
    public function __construct(
        public readonly int $jobId,
        public readonly int $supplierId,
        public readonly int $userId,
        public readonly string $target,
        public readonly array $params,
        public readonly ?Request $request,
    ) {}

    public function request(): Request
    {
        if ($this->request === null) {
            throw new \LogicException('Operace potřebuje požadavek s rolí uživatele.');
        }
        return $this->request;
    }
}
