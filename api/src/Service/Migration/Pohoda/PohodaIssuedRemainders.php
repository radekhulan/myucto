<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use PDO;

/**
 * Dorovnání úhrady vydaného dokladu převedeného z POHODY (SSOT pro převod
 * {@see DocumentLinker} i opravu {@see \MyInvoice\Service\Migration\Shared\IssuedRemainderRepair}).
 *
 * Uhrazenou částku dokladu (`paid_total`) musí nést evidované platby. Co POHODA vede
 * jako uhrazené a převod nemá kam navázat (úhrada v cizí měně přepočtená kurzem dokladu,
 * zaokrouhlení v zápisu úhrady, úhrada bez dokladu banky či pokladny), dostane chráněnou
 * platbu {@see DocumentLinker::REMAINDER_NOTE} se zdrojem `settlement`. Bez ní první změna
 * plateb dokladu přepočtem ze součtu plateb uhrazenou část smaže a doklad vrátí mezi
 * neuhrazené.
 *
 * Doklad s ručním vyrovnáním v deníku (vazba ručního zápisu nebo rozpad zápisu na doklady)
 * zbytek nese tím zápisem: saldokonto vydaných dokladů čte platby i tyto zápisy, platba by
 * vyrovnání započetla podruhé.
 */
final class PohodaIssuedRemainders
{
    /** Druhy dokladů, které se v MyÚčtu platí ({@see \MyInvoice\Service\Invoice\InvoicePaymentService}). */
    public const PAYABLE_TYPES = ['invoice', 'proforma'];

    /** SQL podmínka „doklad `$alias` vyrovnává ruční zápis v deníku". */
    public static function journalSettledSql(string $alias): string
    {
        return "(EXISTS (SELECT 1 FROM journal_entry_document_links jl
                          JOIN journal_entries je ON je.id = jl.entry_id AND je.supplier_id = jl.supplier_id AND je.source_type = 'manual'
                         WHERE jl.supplier_id = {$alias}.supplier_id AND jl.doc_type = 'invoice' AND jl.doc_id = {$alias}.id)
                 OR EXISTS (SELECT 1 FROM journal_settlement_allocations ja
                         WHERE ja.supplier_id = {$alias}.supplier_id AND ja.doc_type = 'invoice' AND ja.doc_id = {$alias}.id))";
    }

    /**
     * Datum dorovnání: datum úhrady podle POHODY, u dokladu uhrazeného bez data úhrady DUZP,
     * jinak datum vystavení.
     */
    public static function paidOnSql(string $alias): string
    {
        return "COALESCE(DATE({$alias}.paid_at), {$alias}.tax_date, {$alias}.issue_date)";
    }

    public static function record(PDO $pdo, int $supplierId, int $invoiceId, string $paidOn, float $amount, string $currency, ?int $userId,
        string $note = DocumentLinker::REMAINDER_NOTE): void
    {
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'settlement', ?)"
        )->execute([$supplierId, $invoiceId, $paidOn, number_format($amount, 2, '.', ''), $currency, $note, $userId]);
    }
}
