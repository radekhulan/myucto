<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Jediný zápis převzatých dokladů z cizích účetních programů (Money S3, POHODA, PREMIER,
 * Stereo NX) do `invoices` / `invoice_items` a `purchase_invoices` / `purchase_invoice_items`.
 * Stejnou cestou zapisuje i import už vystaveného dokladu přes API
 * ({@see \MyInvoice\Action\Invoice\ImportIssuedInvoiceAction}): doklad z e-shopu je převzatý
 * doklad, jen nepřichází souborem.
 *
 * Zdrojový importér jen mapuje svůj doklad na {@see MigratedIssuedDocument} /
 * {@see MigratedPurchaseDocument} a {@see MigratedDocumentItem}; o daňové povaze dokladu
 * rozhoduje on, zapisovač nic nedopočítává ani nedoplňuje. Sloupce, které DTO nese
 * s výchozí hodnotou, dostanou totéž, co by jim dal DEFAULT tabulky.
 *
 * Zapisovač hlavičku a položky NESPOJUJE do jednoho volání: Stereo NX páruje sazbu položky
 * až po zápisu hlavičky a nenalezenou sazbu hlásí výjimkou uprostřed zápisu položek.
 * Přepočet cache seznamu klientů ({@see \MyInvoice\Service\Stats\StatsRecomputer}) je
 * odpovědností volajícího importéru - jen ten ví, kdy běh končí a jestli je v transakci.
 */
final class MigratedDocumentWriter
{
    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(private readonly Connection $db) {}

    /** @return int id nového dokladu v `invoices` */
    public function insertIssued(MigratedIssuedDocument $d): int
    {
        $this->stmt('issued', 'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, payment_variable_symbol, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, note_above_items, note_below_items, client_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, advance_paid_amount, paid_total, paid_at,
                 status, booked_at, booked_by, vat_classification_code, reverse_charge, payment_method,
                 prices_include_vat, created_by, supplier_snapshot, bank_snapshot, parent_invoice_id,
                 corrective_delivered_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $d->supplierId,
            $d->invoiceType,
            $d->clientId,
            $d->varsymbol,
            $d->paymentVariableSymbol,
            $d->issueDate,
            $d->taxDate,
            $d->dueDate,
            $d->currencyId,
            $d->exchangeRate,
            $d->noteAboveItems,
            $d->noteBelowItems,
            $d->clientSnapshot,
            $d->totalWithoutVat,
            $d->totalVat,
            $d->totalWithVat,
            $d->rounding,
            $d->advancePaidAmount,
            $d->paidTotal,
            $d->paidAt,
            $d->status,
            $d->bookedAt,
            $d->bookedBy,
            $d->vatClassificationCode,
            $d->reverseCharge ? 1 : 0,
            $d->paymentMethod,
            $d->pricesIncludeVat ? 1 : 0,
            $d->createdBy,
            $d->supplierSnapshot,
            $d->bankSnapshot,
            $d->parentInvoiceId,
            $d->correctiveDeliveredOn,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<int,MigratedDocumentItem> $items klíč = `order_index` */
    public function insertIssuedItems(int $invoiceId, array $items): void
    {
        foreach ($items as $i => $item) {
            $this->insertIssuedItem($invoiceId, $item, $i);
        }
    }

    public function insertIssuedItem(int $invoiceId, MigratedDocumentItem $item, int $orderIndex): void
    {
        $oss = $item->oss;
        $this->stmt('issued_item', 'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                 oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type, oss_needs_manual_review,
                 oss_taxable_amount_return, oss_vat_amount_return, revenue_account_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $invoiceId, $item->description, $item->quantity, $item->unit, $item->unitPrice,
            $item->vatRateId, $item->vatRateSnapshot, $item->totalWithoutVat, $item->totalVat, $item->totalWithVat,
            $orderIndex, $item->vatClassificationCode,
            $oss['oss_applicable'], $oss['oss_consumer_country'], $oss['oss_rate_type'],
            $oss['oss_supply_type'], $oss['oss_needs_manual_review'],
            $oss['oss_taxable_amount_return'], $oss['oss_vat_amount_return'],
            $item->revenueAccountCode,
        ]);
    }

    /** @return int id nového dokladu v `purchase_invoices` */
    public function insertPurchase(MigratedPurchaseDocument $d): int
    {
        $this->stmt('purchase', 'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, received_at_source, currency_id, exchange_rate,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, rounding, advance_paid_amount,
                 payment_variable_symbol, payment_constant_symbol, payment_account_number, payment_bank_code,
                 payment_method, status, paid_at, booked_at, booked_by, note_above_items, note_below_items,
                 external_barcode, vat_deduction, vat_classification_code, reverse_charge, is_fixed_asset,
                 prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $d->supplierId,
            $d->vendorId,
            $d->vendorIsVatPayer ? 1 : 0,
            $d->varsymbol,
            $d->vendorInvoiceNumber,
            $d->documentKind,
            $d->issueDate,
            $d->taxDate,
            $d->dueDate,
            $d->receivedAt,
            $d->receivedAtSource,
            $d->currencyId,
            $d->exchangeRate,
            $d->vendorSnapshot,
            $d->totalWithoutVat,
            $d->totalVat,
            $d->totalWithVat,
            $d->rounding,
            $d->advancePaidAmount,
            $d->paymentVariableSymbol,
            $d->paymentConstantSymbol,
            $d->paymentAccountNumber,
            $d->paymentBankCode,
            $d->paymentMethod,
            $d->status,
            $d->paidAt,
            $d->bookedAt,
            $d->bookedBy,
            $d->noteAboveItems,
            $d->noteBelowItems,
            $d->externalBarcode,
            $d->vatDeduction,
            $d->vatClassificationCode,
            $d->reverseCharge ? 1 : 0,
            $d->isFixedAsset ? 1 : 0,
            $d->pricesIncludeVat ? 1 : 0,
            $d->createdBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<int,MigratedDocumentItem> $items klíč = `order_index` */
    public function insertPurchaseItems(int $purchaseInvoiceId, array $items): void
    {
        foreach ($items as $i => $item) {
            $this->insertPurchaseItem($purchaseInvoiceId, $item, $i);
        }
    }

    public function insertPurchaseItem(int $purchaseInvoiceId, MigratedDocumentItem $item, int $orderIndex): void
    {
        $this->stmt('purchase_item', 'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, is_fixed_asset, expense_kind,
                 expense_account_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $purchaseInvoiceId, $item->description, $item->quantity, $item->unit, $item->unitPrice,
            $item->vatRateId, $item->vatRateSnapshot, $item->totalWithoutVat, $item->totalVat, $item->totalWithVat,
            $orderIndex, $item->vatClassificationCode, $item->isFixedAsset ? 1 : 0, $item->expenseKind,
            $item->expenseAccountCode,
        ]);
    }

    /** Připravený dotaz patří spojení, na kterém vznikl - uvolněné a znovu otevřené spojení dostane nový. */
    private function stmt(string $key, string $sql): \PDOStatement
    {
        $pdo = $this->db->pdo();
        return $this->stmts[spl_object_id($pdo) . '|' . $key] ??= $pdo->prepare($sql);
    }
}
