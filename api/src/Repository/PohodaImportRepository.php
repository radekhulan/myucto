<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use PDO;

/**
 * Převody z POHODY: běhy průvodce s protokolem (`pohoda_imports`) a mapa „co už
 * z exportu v MyÚčtu vzniklo" (`pohoda_import_map`, migrace 1844). Stejný princip jako
 * u ostatních převodů ({@see AbstractMigrationImportRepository}): mapa nese idempotenci,
 * všechno je tenantové a převod jedné firmy smí běžet jen jednou naráz.
 */
final class PohodaImportRepository extends AbstractMigrationImportRepository
{
    public const KIND_PERIOD = 'period';
    /** Rok agendy převedené jako daňová evidence ({@see \MyInvoice\Service\Migration\Pohoda\PohodaTaxEvidenceImporter}): rok => rok. */
    public const KIND_TAX_EVIDENCE_YEAR = 'tax_evidence_year';
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
    /** Zakázka MyÚčta u partnera zakázky POHODY: číslo zakázky => projects.id. */
    public const KIND_PROJECT = 'project';
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
    /** Otevřená vydaná objednávka POHODY podle čísla => purchase_orders.id. */
    public const KIND_PURCHASE_ORDER = 'purchase_order';
    /** Otevřená přijatá objednávka POHODY podle čísla => sales_orders.id. */
    public const KIND_SALES_ORDER = 'sales_order';
    /** Převedený měsíc mezd: `období|otisk sešitu` => id dávky importu docházky. */
    public const KIND_PAYROLL_MONTH = 'payroll_month';
    /** Převedená trvalá srážka: reference srážky v PAMICA => id případu nebo dohody. */
    public const KIND_PAYROLL_DEDUCTION = 'payroll_deduction';
    /** Převzatá věta registrace ČSSZ: `RegZAM:id:id věty` => id vztahu (nebo osoby). */
    public const KIND_PAYROLL_REGISTRATION = 'payroll_registration';

    private const COMPLETED_IMPORT = "mode = 'import' AND status IN ('completed', 'completed_with_warnings')";

    /** @return list<int> firmy s dokončeným převodem z POHODY */
    public function completedSuppliers(): array
    {
        return array_map('intval', $this->db->pdo()->query(
            'SELECT DISTINCT supplier_id FROM pohoda_imports WHERE ' . self::COMPLETED_IMPORT . ' ORDER BY supplier_id'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function hasCompletedImport(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM pohoda_imports WHERE supplier_id = ? AND ' . self::COMPLETED_IMPORT . ' LIMIT 1');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Převzal převod z POHODY zaúčtování mezd měsíce? Převzatý zápis deníku s předpisem na 331
     * (Dal) v rozsahu `[from, until)`. Stejné pravidlo jako u Money S3
     * ({@see MoneyS3ImportRepository::payrollMonthConverted()}): načtení mezd z PAMICY za
     * takový měsíc by předpis mezd i mzdové závazky zdvojilo.
     */
    public function payrollMonthConverted(int $supplierId, string $from, string $until): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id AND ca.supplier_id = l.supplier_id
              WHERE e.supplier_id = ? AND e.entry_date >= ? AND e.entry_date < ?
                AND e.source_type NOT IN ('opening', 'closing') AND e.reversed_by IS NULL
                AND l.side = 'credit' AND (ca.account_code = '331' OR ca.account_code LIKE '331.%')
                AND EXISTS (SELECT 1 FROM pohoda_import_map m
                             WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id)
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $from, $until]);
        return $stmt->fetchColumn() !== false;
    }

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
