<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Pohyb banky, se kterým se v MyÚčtu pracovalo: nese peníze (párování, úhrada, alokace,
 * mzdy, převod mezi účty), zápis deníku, rozhodnutí uživatele (stav párování, schválený
 * nebo zaúčtovaný návrh, zařazení pohybu, blokace automatického párování) nebo vazbu
 * z jiné evidence (záloha na daň, žádost o doklad, tankování, podání přijaté faktury).
 * Takový pohyb převod nesmí smazat ani přepsat.
 *
 * Jediné místo pravidla pro převody z cizích programů: mazání pohybů jiného zdroje na
 * účtu Money S3 ({@see BankStatementImportWriter::foreignMovements()}) i převod měny
 * výpisů POHODY ({@see \MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair}).
 */
final class BankTransactionLinks
{
    /** Tabulka => sloupce s odkazem na pohyb, které vazbu nesou. */
    public const TABLES = [
        'payment_matches' => ['bank_transaction_id'],
        'invoice_payments' => ['bank_transaction_id'],
        'other_item_allocations' => ['bank_transaction_id'],
        'other_item_auto_match_blocks' => ['bank_transaction_id'],
        'payment_event_allocations' => ['bank_transaction_id'],
        'payroll_payment_matches' => ['bank_transaction_id'],
        'unit_payment_sources' => ['bank_transaction_id'],
        'unit_payment_workflows' => ['bank_transaction_id'],
        'gopay_clearings' => ['bank_transaction_id', 'payout_match_transaction_id'],
        'de_movement_classification' => ['bank_transaction_id'],
        'bank_transfer_matches' => ['in_transaction_id', 'out_transaction_id'],
        'tax_advance_schedules' => ['matched_transaction_id'],
        'document_requests' => ['bank_transaction_id'],
        'fuelings' => ['source_bank_transaction_id'],
        'purchase_invoice_submissions' => ['bank_transaction_id'],
    ];

    /** Návrh zaúčtování, o kterém ještě nikdo nerozhodl (nic nezaúčtoval). */
    public const OPEN_SUGGESTIONS = "('pending','needs_input','blocked','rejected')";

    /**
     * SQL podmínka „pohyb `$alias` nese vazbu" (viz popis třídy). Dva parametry `?`, oba
     * firma (vazba zápisu na pohyb a zápis deníku).
     */
    public static function linkedSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias pohybu.');
        }
        $exists = [];
        foreach (self::TABLES as $table => $columns) {
            foreach ($columns as $column) {
                $exists[] = "EXISTS (SELECT 1 FROM {$table} x WHERE x.{$column} = {$alias}.id)";
            }
        }
        return "({$alias}.match_status <> 'unmatched' OR " . implode(' OR ', $exists) . "
                OR EXISTS (SELECT 1 FROM bank_posting_suggestions bps WHERE bps.bank_transaction_id = {$alias}.id AND bps.status NOT IN " . self::OPEN_SUGGESTIONS . ")
                OR EXISTS (SELECT 1 FROM journal_entry_document_links jdl WHERE jdl.supplier_id = ? AND jdl.doc_type = 'bank' AND jdl.doc_id = {$alias}.id)
                OR EXISTS (SELECT 1 FROM journal_entries bje WHERE bje.supplier_id = ? AND bje.source_type = 'bank' AND bje.source_id = {$alias}.id))";
    }
}
