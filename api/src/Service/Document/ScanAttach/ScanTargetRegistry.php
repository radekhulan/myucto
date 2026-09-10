<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/** Typy dokladů, ke kterým lze skeny připojovat. */
final class ScanTargetRegistry
{
    /** @var array<string, ScanTargetInterface> */
    private array $targets = [];

    public function __construct(
        PurchaseInvoiceScanTarget $purchaseInvoices,
        IssuedInvoiceScanTarget $invoices,
        CashDocumentScanTarget $cashDocuments,
    ) {
        foreach ([$purchaseInvoices, $invoices, $cashDocuments] as $t) {
            $this->targets[$t->type()] = $t;
        }
    }

    /** @return array<string, ScanTargetInterface> */
    public function all(): array
    {
        return $this->targets;
    }

    public function get(string $type): ?ScanTargetInterface
    {
        return $this->targets[$type] ?? null;
    }

    /** Typ, který existuje a lze k němu teď připojovat. */
    public function available(string $type): ?ScanTargetInterface
    {
        $t = $this->get($type);
        return $t !== null && $t->isAvailable() ? $t : null;
    }
}
