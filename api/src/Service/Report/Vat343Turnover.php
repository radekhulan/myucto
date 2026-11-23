<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Service\Tax\TaxOfficeBankAccounts;

/**
 * Které zápisy na účtu 343 nejsou daní za období, ale převodem nebo vypořádáním. Jediné
 * místo pravidla pro kontrolu obratu 343 proti přiznání ({@see VatCrossCheckService})
 * i pro kontrolu 343 po dimenzích v sestavě DPH podle dimenze:
 *
 *   • uzavření a otevření knih (`closing`, `opening`, ČÚS 002) — počáteční stav 343
 *     převzatý k 1. dni období a jeho převod při uzávěrce,
 *   • úhrada vlastní daně finančnímu úřadu a vratka nadměrného odpočtu. Řádek 343 je
 *     vypořádáním, když platí aspoň jedno:
 *       1. zápis je z bankovního pohybu, jehož protiúčet je u ČNB (kód banky 0710, účty
 *          finančních úřadů {@see TaxOfficeBankAccounts}) — doložená vazba, poplatek
 *          nebo penále na jiném účtu ve stejném zápisu na tom nic nemění,
 *       2. řádek je v jednoduchém zápisu ve dvojici s peněžním účtem (211, 221, 261),
 *       3. zápis (z banky, pokladny i ruční) má jen účty 343 a peněžní účty a žádnou
 *          jinou protistranu.
 *     DPH z bankovního poplatku (568/343/221 bez protiúčtu u ČNB) nebo pokladní doklad
 *     s DPH (211/5xx/343) protistranu nákladu či výnosu mají, a v obratu proto zůstávají.
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

    /**
     * SQL predikát nad aliasy ŘÁDKU 343 a jeho zápisu: řádek je úhradou daně finančnímu
     * úřadu nebo vratkou odpočtu (pravidla v popisu třídy).
     */
    public static function settlementLineSql(string $lineAlias, string $entryAlias): string
    {
        $bank = ltrim(TaxOfficeBankAccounts::BANK_CODE, '0');
        return "(({$entryAlias}.source_type = 'bank'
                  AND EXISTS (SELECT 1 FROM bank_transactions v343_bt
                               WHERE v343_bt.id = {$entryAlias}.source_id
                                 AND (TRIM(LEADING '0' FROM v343_bt.counterparty_bank) = '{$bank}'
                                      OR REPLACE(v343_bt.counterparty_account, ' ', '') LIKE 'CZ__" . TaxOfficeBankAccounts::BANK_CODE . "%')))
              OR ({$lineAlias}.pair_no IS NOT NULL
                  AND EXISTS (SELECT 1 FROM journal_entry_lines v343_pl
                                JOIN chart_of_accounts v343_pa ON v343_pa.id = v343_pl.account_id
                               WHERE v343_pl.supplier_id = {$lineAlias}.supplier_id AND v343_pl.entry_id = {$lineAlias}.entry_id
                                 AND v343_pl.pair_no = {$lineAlias}.pair_no AND v343_pl.id <> {$lineAlias}.id
                                 AND " . self::moneySql('v343_pa') . "))
              OR (EXISTS (SELECT 1 FROM journal_entry_lines v343_ml
                            JOIN chart_of_accounts v343_mca ON v343_mca.id = v343_ml.account_id
                           WHERE v343_ml.supplier_id = {$entryAlias}.supplier_id AND v343_ml.entry_id = {$entryAlias}.id
                             AND " . self::moneySql('v343_mca') . ")
                  AND NOT EXISTS (SELECT 1 FROM journal_entry_lines v343_ol
                            JOIN chart_of_accounts v343_oca ON v343_oca.id = v343_ol.account_id
                           WHERE v343_ol.supplier_id = {$entryAlias}.supplier_id AND v343_ol.entry_id = {$entryAlias}.id
                             AND v343_oca.account_code NOT LIKE '343%'
                             AND NOT " . self::moneySql('v343_oca') . ')))';
    }

    private static function moneySql(string $accountAlias): string
    {
        return '(' . implode(' OR ', array_map(
            static fn (string $p): string => "{$accountAlias}.account_code LIKE '{$p}%'",
            self::MONEY_ACCOUNT_PREFIXES,
        )) . ')';
    }
}
