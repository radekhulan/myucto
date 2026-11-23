<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Které zápisy na účtu 343 nejsou daní za období, ale převodem nebo vypořádáním:
 *
 *   • uzavření a otevření knih (`closing`, `opening`, ČÚS 002) — počáteční stav 343
 *     převzatý k 1. dni období a jeho převod při uzávěrce,
 *   • úhrada vlastní daně finančnímu úřadu a vratka nadměrného odpočtu: zápis z banky
 *     nebo pokladny, jehož řádky stojí jen na 343 a peněžních účtech (211, 221, 261)
 *     a žádnou jinou (základovou, nákladovou, výnosovou) protistranu nemají. DPH
 *     z bankovního poplatku (568/343/221) nebo pokladní doklad s DPH (211/5xx/343)
 *     protistranu mají, tak zůstávají.
 *
 * Totéž pravidlo používá kontrola obratu 343 proti přiznání
 * ({@see VatCrossCheckService}, `booked343ByDoc`), kde zatím žije jako vlastní SQL.
 */
final class Vat343Turnover
{
    /** Zdroje zápisu, které převádějí zůstatek 343 mezi účetními obdobími. */
    public const BOOK_TRANSFER_SOURCES = ['closing', 'opening'];

    /** Peněžní účty, proti kterým se daň platí nebo vrací. */
    public const MONEY_ACCOUNT_PREFIXES = ['211', '221', '261'];

    /** SQL predikát nad aliasem zápisu: uzavření nebo otevření knih. */
    public static function bookTransferSql(string $entryAlias): string
    {
        return "{$entryAlias}.source_type IN ('" . implode("','", self::BOOK_TRANSFER_SOURCES) . "')";
    }

    /** SQL predikát nad aliasem zápisu: úhrada daně finančnímu úřadu nebo vratka odpočtu. */
    public static function settlementPaymentSql(string $entryAlias): string
    {
        $money = static fn (string $alias): string => '(' . implode(' OR ', array_map(
            static fn (string $p): string => "{$alias}.account_code LIKE '{$p}%'",
            self::MONEY_ACCOUNT_PREFIXES,
        )) . ')';
        return "({$entryAlias}.source_type IN ('bank', 'cash')
            AND EXISTS (SELECT 1 FROM journal_entry_lines v343_ml
                          JOIN chart_of_accounts v343_mca ON v343_mca.id = v343_ml.account_id
                         WHERE v343_ml.supplier_id = {$entryAlias}.supplier_id AND v343_ml.entry_id = {$entryAlias}.id
                           AND " . $money('v343_mca') . ")
            AND NOT EXISTS (SELECT 1 FROM journal_entry_lines v343_ol
                          JOIN chart_of_accounts v343_oca ON v343_oca.id = v343_ol.account_id
                         WHERE v343_ol.supplier_id = {$entryAlias}.supplier_id AND v343_ol.entry_id = {$entryAlias}.id
                           AND v343_oca.account_code NOT LIKE '343%'
                           AND NOT " . $money('v343_oca') . '))';
    }
}
