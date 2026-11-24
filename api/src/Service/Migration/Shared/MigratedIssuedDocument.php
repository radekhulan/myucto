<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Hlavička vydaného dokladu (`invoices`) převzatého z cizího účetního programu, tak jak ji
 * zdrojový importér namapoval. Zapisuje ji {@see MigratedDocumentWriter}.
 *
 * Čtyři údaje, na kterých stojí daňový výklad dokladu, nemají výchozí hodnotu a zdroj je
 * musí uvést vždy: `pricesIncludeVat` (brutto cena by se jinak přepočítala jako netto),
 * `reverseCharge`, měna a kurz (`exchangeRate` = null znamená doklad v domácí měně, kurz
 * se nezapisuje). Ostatní nepovinné parametry mají výchozí hodnotu shodnou s DEFAULT
 * sloupce v databázi, takže zdroj, který je nenese, zapíše totéž, co by zapsal bez nich.
 */
final class MigratedIssuedDocument
{
    public function __construct(
        public readonly int $supplierId,
        public readonly string $invoiceType,
        public readonly int $clientId,
        public readonly string $varsymbol,
        public readonly string $issueDate,
        public readonly ?string $taxDate,
        public readonly string $dueDate,
        public readonly int $currencyId,
        public readonly ?float $exchangeRate,
        public readonly bool $pricesIncludeVat,
        public readonly bool $reverseCharge,
        public readonly ?string $noteAboveItems,
        public readonly ?string $noteBelowItems,
        public readonly string $clientSnapshot,
        public readonly float $totalWithoutVat,
        public readonly float $totalVat,
        public readonly float $totalWithVat,
        public readonly float $rounding,
        public readonly string $status,
        public readonly ?int $createdBy,
        public readonly ?string $paymentVariableSymbol = null,
        public readonly float $advancePaidAmount = 0.0,
        public readonly float $paidTotal = 0.0,
        public readonly ?string $paidAt = null,
        public readonly ?string $bookedAt = null,
        public readonly ?int $bookedBy = null,
        public readonly ?string $vatClassificationCode = null,
        public readonly string $paymentMethod = 'bank_transfer',
        // Snapshoty dodavatele a banky, vazba dobropisu na opravovaný doklad a den doručení
        // opravného dokladu (§ 42 odst. 3). Převody je nenesou (NULL = DEFAULT sloupce),
        // vyplňuje je import vystaveného dokladu přes API ({@see \MyInvoice\Action\Invoice\ImportIssuedInvoiceAction}).
        public readonly ?string $supplierSnapshot = null,
        public readonly ?string $bankSnapshot = null,
        public readonly ?int $parentInvoiceId = null,
        public readonly ?string $correctiveDeliveredOn = null,
        public readonly array $czkSnapshot = [],
    ) {}
}
