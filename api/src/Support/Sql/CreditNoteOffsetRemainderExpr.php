<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Složení části dokladu, kterou zbývá uhradit penězi, když ho zčásti vyrovnal zápočet
 * dobropisu (`credit_note_offsets`, {@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}).
 *
 * Zápočet není peněžní tok, peněžní deník bere jen úhradu zbytku faktury. Zbytek ale nemá
 * složení celé faktury: dobropis z ní ubral část ve SVÉM složení (jiná sazba DPH, jen
 * některé položky nebo kusy). Zbytek je proto faktura minus započtené dobropisy. Dělit zbytek
 * poměrem celé faktury nadsadí nebo podhodnotí daňový příjem a výdaj: u faktury se sazbami
 * 21 % a 0 %, ze které dobropis stornuje položku 21 %, by zbytek bez DPH nesl DPH.
 *
 * Brutto zbytku odečítá částku zápočtu (`credit_note_offsets.amount`), tedy tutéž veličinu,
 * kterou od faktury odečítá noha C2 peněžního deníku i {@see PurchaseSettledExpr}. DPH zbytku
 * odečítá DPH dobropisu: kryje-li zápočet celé brutto dobropisu (běžný případ), celé DPH
 * dobropisu prostým odečtením, jinak (dobropis s odečtenou zálohou) jeho poměrnou část
 * podle částky zápočtu. Znaménko dobropisu určuje jeho brutto, ne každý sloupec zvlášť
 * (stejně jako {@see \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSignSql()}):
 * opravný dobropis s brutto −395 a DPH +105 (vrací položku 0 %, doúčtuje položku 21 %)
 * DPH zbytku zvýší.
 *
 * Bez zápočtu je to doklad sám. Doklad celý vyrovnaný zápočtem zbytek nemá, jeho případná
 * úhrada (přeplatek) se dělí podle dokladu. Dobropis se při zápočtu spotřebuje ve vlastním
 * složení, úhrada zbytku dobropisu se proto dělí podle dobropisu.
 *
 * Částky jsou v měně dokladu (v ní je i dobropis). Úhradu pak peněžní deník dělí na základ
 * a DPH poměrem DPH zbytku k brutto zbytku; u úhrady celého zbytku to dá přesně rozdíl
 * zbytku, poměr je nutný jen pro částečnou úhradu a úhradu v jiné měně.
 *
 * Úhrada se dělí jen podle zápočtů s datem nejpozději v den úhrady (datum řádku deníku).
 * Pozdější zápočet rozdělení dřívější úhrady nemění, jinak by přepsal už uzavřený rok.
 * Rozdíl, který zápočet do dřívějších úhrad vnese, nese opravný řádek peněžního deníku ke dni
 * zápočtu ({@see \MyInvoice\Repository\CashJournalRepository::creditNoteOffsetCorrection()}).
 * Ten srovnává zbytek před zápočtem a po něm; zápočty téhož dne řadí podle id.
 *
 * Přijatá strana odečítá týmž pravidlem daňovou část dokladů v
 * {@see \MyInvoice\Service\TaxEvidence\TaxExpenseAllocationCalculator} (řádkový rozpis,
 * krácený odpočet); tenhle výraz u ní slouží jen ručnímu zařazení pohybu s vazbou na doklad.
 */
final class CreditNoteOffsetRemainderExpr
{
    /**
     * Připojení zápočtů dokladu známých k datu úhrady jako odvozené tabulky: zápočty firmy
     * seskupené po dokladu a dni s kumulativním brutto a DPH a dnem dalšího zápočtu. Podmínka
     * spojení vybere den posledního zápočtu nejpozději v den úhrady, takže se k řádku dokladu
     * připojí nejvýš jeden řádek a zbytek se nepočítá korelovaným poddotazem pro každou úhradu.
     *
     * @param string $docType    `invoice` (vydaný doklad) nebo `purchase_invoice` (přijatý)
     * @param int    $supplierId firma (inline, int)
     * @param string $docAlias   alias tabulky dokladu v okolním dotazu
     * @param string $asOfSql    SQL datum, ke kterému se zápočty berou (datum úhrady)
     * @param string $alias      alias připojené tabulky pro {@see grossSql()} a {@see vatSql()}
     */
    public static function joinSql(string $docType, int $supplierId, string $docAlias, string $asOfSql, string $alias): string
    {
        [$type, $table] = self::document($docType);
        return "LEFT JOIN (
                    SELECT d.invoice_id, d.offset_on,
                           LEAD(d.offset_on) OVER (PARTITION BY d.invoice_id ORDER BY d.offset_on) AS until_on,
                           SUM(d.gross) OVER (PARTITION BY d.invoice_id ORDER BY d.offset_on) AS gross,
                           SUM(d.vat) OVER (PARTITION BY d.invoice_id ORDER BY d.offset_on) AS vat
                      FROM (SELECT cnr_o.invoice_id, cnr_o.offset_on, SUM(cnr_o.amount) AS gross,
                                   SUM(" . self::offsetVatSql() . ") AS vat
                              FROM credit_note_offsets cnr_o
                              LEFT JOIN {$table} cnr_d ON cnr_d.id = cnr_o.credit_note_id AND cnr_d.supplier_id = cnr_o.supplier_id
                             WHERE cnr_o.supplier_id = {$supplierId} AND cnr_o.doc_type = '{$type}'
                             GROUP BY cnr_o.invoice_id, cnr_o.offset_on) d
                ) {$alias} ON {$alias}.invoice_id = {$docAlias}.id AND {$alias}.offset_on <= {$asOfSql}
                          AND ({$alias}.until_on IS NULL OR {$alias}.until_on > {$asOfSql})";
    }

    /**
     * Brutto části dokladu k úhradě penězi nad připojením z {@see joinSql()}.
     *
     * @param string $docAlias alias tabulky dokladu
     * @param string $alias    alias připojených zápočtů
     */
    public static function grossSql(string $docAlias, string $alias): string
    {
        $remaining = "({$docAlias}.total_with_vat - COALESCE({$alias}.gross, 0))";
        return "(CASE WHEN {$remaining} > 0.005 THEN {$remaining} ELSE {$docAlias}.total_with_vat END)";
    }

    /**
     * DPH části dokladu k úhradě penězi nad připojením z {@see joinSql()}.
     *
     * @param string $docAlias alias tabulky dokladu
     * @param string $alias    alias připojených zápočtů
     */
    public static function vatSql(string $docAlias, string $alias): string
    {
        return "(CASE WHEN {$docAlias}.total_with_vat - COALESCE({$alias}.gross, 0) > 0.005
                      THEN {$docAlias}.total_vat - COALESCE({$alias}.vat, 0) ELSE {$docAlias}.total_vat END)";
    }

    /**
     * Brutto k úhradě penězi se zápočty do daného zápočtu (opravný řádek srovnává zbytek
     * před zápočtem a po něm). Korelovaný poddotaz, běží jednou na zápočet, ne na úhradu.
     *
     * @param string $docType      `invoice` nebo `purchase_invoice`
     * @param string $alias        alias tabulky dokladu v okolním dotazu
     * @param string $asOfSql      SQL datum zápočtu
     * @param string $throughIdSql SQL id posledního zápočtu dne $asOfSql, který se ještě bere
     */
    public static function grossAsOfOffsetSql(string $docType, string $alias, string $asOfSql, string $throughIdSql): string
    {
        $remaining = self::remainingGrossSql($docType, $alias, $asOfSql, $throughIdSql);
        return "(CASE WHEN {$remaining} > 0.005 THEN {$remaining} ELSE {$alias}.total_with_vat END)";
    }

    /**
     * DPH k úhradě penězi se zápočty do daného zápočtu, viz {@see grossAsOfOffsetSql()}.
     */
    public static function vatAsOfOffsetSql(string $docType, string $alias, string $asOfSql, string $throughIdSql): string
    {
        return '(CASE WHEN ' . self::remainingGrossSql($docType, $alias, $asOfSql, $throughIdSql) . ' > 0.005
                      THEN ' . self::remainingVatSql($docType, $alias, $asOfSql, $throughIdSql) . " ELSE {$alias}.total_vat END)";
    }

    /** Brutto dokladu minus částky zápočtů dobropisů proti němu. */
    public static function remainingGrossSql(string $docType, string $alias, string $asOfSql, string $throughIdSql): string
    {
        [$type] = self::document($docType);
        $asOf = self::throughOffset($asOfSql, $throughIdSql);
        return "({$alias}.total_with_vat - (SELECT COALESCE(SUM(cnr_o.amount), 0)
                     FROM credit_note_offsets cnr_o
                    WHERE cnr_o.supplier_id = {$alias}.supplier_id AND cnr_o.doc_type = '{$type}'
                      AND cnr_o.invoice_id = {$alias}.id AND {$asOf}))";
    }

    /** DPH dokladu minus DPH započtených dobropisů ({@see offsetVatSql()}). */
    private static function remainingVatSql(string $docType, string $alias, string $asOfSql, string $throughIdSql): string
    {
        [$type, $table] = self::document($docType);
        $asOf = self::throughOffset($asOfSql, $throughIdSql);
        return "({$alias}.total_vat - (SELECT COALESCE(SUM(" . self::offsetVatSql() . "), 0)
                     FROM credit_note_offsets cnr_o
                     JOIN {$table} cnr_d ON cnr_d.id = cnr_o.credit_note_id AND cnr_d.supplier_id = cnr_o.supplier_id
                    WHERE cnr_o.supplier_id = {$alias}.supplier_id AND cnr_o.doc_type = '{$type}'
                      AND cnr_o.invoice_id = {$alias}.id AND {$asOf}))";
    }

    /**
     * DPH jednoho zápočtu (`cnr_o`) ve směru snížení faktury, dobropis `cnr_d`. Kryje-li
     * zápočet celé brutto dobropisu, je to celé DPH dobropisu se znaménkem z jeho brutto
     * (prosté odečtení), jinak poměrná část podle částky zápočtu.
     */
    private static function offsetVatSql(): string
    {
        return 'CASE WHEN cnr_o.amount = ABS(cnr_d.total_with_vat)
                     THEN SIGN(cnr_d.total_with_vat) * cnr_d.total_vat
                     ELSE cnr_o.amount * cnr_d.total_vat / NULLIF(cnr_d.total_with_vat, 0) END';
    }

    /** Zápočty do daného zápočtu: dřívější dny a téhož dne jen do daného id. */
    private static function throughOffset(string $asOfSql, string $throughIdSql): string
    {
        return "(cnr_o.offset_on < {$asOfSql} OR (cnr_o.offset_on = {$asOfSql} AND cnr_o.id <= {$throughIdSql}))";
    }

    /** @return array{0:string,1:string} `credit_note_offsets.doc_type` a tabulka dokladů */
    private static function document(string $docType): array
    {
        return $docType === 'purchase_invoice' ? ['purchase_invoice', 'purchase_invoices'] : ['invoice', 'invoices'];
    }
}
