<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\Pohoda\PohodaException;

/**
 * Převody z POHODY: běhy průvodce s protokolem (`pohoda_imports`) a mapa „co už
 * z exportu v MyÚčtu vzniklo" (`pohoda_import_map`, migrace 1844). Stejný princip jako
 * u ostatních převodů ({@see AbstractMigrationImportRepository}): mapa nese idempotenci,
 * všechno je tenantové a převod jedné firmy smí běžet jen jednou naráz.
 */
final class PohodaImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    public const KIND_ACCOUNT = 'account';
    public const KIND_JOURNAL_ENTRY = 'journal_entry';
    public const KIND_CLIENT = 'client';
    public const KIND_CLIENT_MATCH = 'client_match';
    public const KIND_POSTING_RULE = 'posting_rule';
    public const KIND_INVOICE = 'invoice';
    public const KIND_PURCHASE_INVOICE = 'purchase_invoice';
    public const KIND_CASH_REGISTER = 'cash_register';
    public const KIND_CASH_DOCUMENT = 'cash_document';
    public const KIND_BANK_STATEMENT = 'bank_statement';
    public const KIND_BANK_TRANSACTION = 'bank_transaction';
    public const KIND_PAYMENT = 'payment';
    public const KIND_OTHER_ITEM = 'other_item';
    public const KIND_TAX_ADVANCE = 'tax_advance';
    /** Ostatní položka ze zůstatku pohybu bez dokladu ({@see \MyInvoice\Service\Migration\Pohoda\BalanceItemImporter}): `zápis|řádek` => other_items.id. */
    public const KIND_BALANCE_ITEM = 'balance_item';
    /** Oprava odpočtu dlužníka podle § 74b z interního dokladu POHODY (`vat_s74b_corrections.id`). */
    public const KIND_S74B_CORRECTION = 's74b_correction';
    /**
     * Úhrada, kterou převod odvodil u pohybu bez zápisu v deníku POHODY
     * ({@see \MyInvoice\Service\Migration\Pohoda\UnbookedBankPayments}): `tx|id pohybu` => payment_matches.id.
     */
    public const KIND_DERIVED_MATCH = 'derived_match';
    /** Zápis odvozené úhrady a jeho storno: `entry|pohyb|zápis`, `reversal|pohyb|zápis` => journal_entries.id. */
    public const KIND_DERIVED_ENTRY = 'derived_entry';
    /**
     * Návrhové pravidlo, které převod odvodil z kontací pohybů téhož protiúčtu nebo partnera
     * ({@see \MyInvoice\Service\Migration\Pohoda\UnbookedBankProposals}):
     * `směr|account|účet|banka` nebo `směr|name|jméno` => bank_posting_rules.id.
     */
    public const KIND_BANK_PROPOSAL_RULE = 'bank_proposal_rule';
    /** Hlavička interního dokladu nad převzatým zápisem: journal_entries.id => internal_documents.id. */
    public const KIND_INTERNAL_DOCUMENT = 'internal_document';
    public const KIND_ASSET = 'asset';
    public const KIND_SMALL_ASSET = 'small_asset';
    /** Sklad podle kódu skladu v POHODĚ => warehouses.id. */
    public const KIND_STOCK_WAREHOUSE = 'stock_warehouse';
    /** Karta zásoby podle kódu (`SKz.IDS`) => stock_items.id. */
    public const KIND_STOCK_ITEM = 'stock_item';
    /** Počáteční stav karty ve skladu: `kód skladu|kód karty` => stock_document_lines.id. */
    public const KIND_STOCK_OPENING = 'stock_opening';
    /** Ceník POHODY podle kódu => stock_price_levels.id. */
    public const KIND_STOCK_PRICE_LEVEL = 'stock_price_level';
    /** Větev členění skladu (otisk cesty názvů) => stock_categories.id. */
    public const KIND_STOCK_CATEGORY = 'stock_category';
    /** Převedený měsíc mezd: `období|otisk sešitu` => id dávky importu docházky. */
    public const KIND_PAYROLL_MONTH = 'payroll_month';
    /** Převedená trvalá srážka: reference srážky v PAMICA => id případu nebo dohody. */
    public const KIND_PAYROLL_DEDUCTION = 'payroll_deduction';
    /** Převzatá věta registrace ČSSZ: `RegZAM:id:id věty` => id vztahu (nebo osoby). */
    public const KIND_PAYROLL_REGISTRATION = 'payroll_registration';

    protected function runsTable(): string
    {
        return 'pohoda_imports';
    }

    protected function mapTable(): string
    {
        return 'pohoda_import_map';
    }

    protected function keyColumn(): string
    {
        return 'pohoda_key';
    }

    protected function lockPrefix(): string
    {
        return 'pohoda';
    }

    protected function runMeta(array $meta): array
    {
        return [
            'agenda_ico' => self::str($meta['ico'] ?? null, 20),
            'agenda_year' => isset($meta['year']) ? (int) $meta['year'] : null,
            'pohoda_version' => self::str($meta['program'] ?? null, 60),
            'export_sha256' => self::str($meta['sha256'] ?? null, 64),
        ];
    }

    /**
     * Druh převodu z protokolu. Běžící běh protokol ještě nemá, druh nese jeho úloha;
     * jinak by se běžící převod mezd ukázal v průvodci účetnictví a v průvodci PAMICA chyběl.
     * Běh bez obojího (z doby před podporou mezd) je účetnictví.
     */
    protected function listExtraColumns(): string
    {
        return ",\n                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(protocol, '$.kind')),
                        (SELECT JSON_UNQUOTE(JSON_EXTRACT(j.params, '$.kind')) FROM import_jobs j
                          WHERE j.id = pohoda_imports.job_id AND j.supplier_id = pohoda_imports.supplier_id),
                        'accounting') AS kind";
    }

    protected function nullableIntColumns(): array
    {
        return ['agenda_year'];
    }

    protected function mapConflict(string $kind, string $key): \RuntimeException
    {
        return new PohodaException('map_conflict', "Záznam {$kind} {$key} z POHODY už v MyÚčtu převedený je - převod se zastavil, aby nic nezdvojil.");
    }
}
