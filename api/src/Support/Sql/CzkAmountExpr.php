<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Jediný zdroj pravdy pro otázku „kolik je tahle částka v CZK".
 *
 * Doklad drží částky v MĚNĚ DOKLADU (`total_without_vat` u faktury v EUR je v eurech)
 * a kurz k účetní měně zvlášť ve sloupci `exchange_rate`. Jakmile agregace míchá měny
 * do JEDNOHO čísla, musí kurzem projít — jinak sečte eura s korunami a vyjde nesmysl,
 * který je o to zákeřnější, že vypadá jako platná koruna.
 *
 * Vzniklo z reklamace zákazníka (2026-09), který srovnával graf tržeb s Pohodou a nesedělo
 * mu 271 tis. Kč. Data i evidence DPH byly správně na haléř; rozdíl dělaly jen EUR faktury,
 * které per-měnová řada grafu do CZK součtu nezahrnula, a nikde v UI nebylo celkové číslo,
 * se kterým by šlo srovnávat. Tentýž výraz do té doby v repu žil ve DVOU zápisech:
 *
 *   COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)         -- vydané
 *   IF(cur.code = 'CZK' OR pi.exchange_rate IS NULL, 1, pi.exchange_rate)  -- přijaté
 *
 * Sémanticky totéž, ale dvě různá znění se rozcházejí při první úpravě jednoho z nich —
 * a rozdílné výsledky pak svítí na dvou stránkách nad týmiž doklady.
 *
 * CZK POJISTKA JE ZÁMĚR, NE OPATRNOST NAVÍC: korunový doklad se zbloudilým `exchange_rate`
 * (import, změna měny po zadání) by se bez ní vynásobil kurzem. Proto se na kurz sáhne
 * teprve tehdy, když měna NENÍ CZK; `COALESCE` je až druhá pojistka pro chybějící kurz.
 *
 * Fallback 1.0 u cizí měny bez kurzu je VĚDOMÝ ÚSTUP pro STATISTIKY, ne pro daně: řádek
 * radši podhodnotí, než aby zmizel z grafu. Daňová cesta ho takhle brát NESMÍ —
 * {@see \MyInvoice\Service\Report\VatLedgerService} proto tentýž případ označí příznakem
 * `exchange_rate_missing` a akce ho odmítne (issue #238). Nepoužívej tenhle helper tam,
 * kde chybějící kurz musí být tvrdá chyba.
 */
final class CzkAmountExpr
{
    /**
     * Kurzový násobitel — 1 pro CZK i pro chybějící kurz, jinak `exchange_rate` dokladu.
     *
     * @param string $docAlias      alias tabulky dokladu (`i` = invoices, `pi` = purchase_invoices)
     * @param string $currencyAlias alias JOINnuté `currencies` v témže dotazu
     */
    public static function rate(string $docAlias, string $currencyAlias = 'cur'): string
    {
        return sprintf(
            "COALESCE(IF(%s.code = 'CZK', 1, %s.exchange_rate), 1)",
            $currencyAlias,
            $docAlias,
        );
    }

    /**
     * Částka přepočtená na CZK. `$amountExpr` je sloupec nebo výraz v měně dokladu
     * (typicky `i.total_without_vat` / `pi.total_with_vat` podle plátcovství DPH).
     *
     * @param string $amountExpr    SQL výraz s částkou v měně dokladu
     * @param string $docAlias      alias tabulky dokladu
     * @param string $currencyAlias alias JOINnuté `currencies`
     */
    public static function amount(string $amountExpr, string $docAlias, string $currencyAlias = 'cur'): string
    {
        return sprintf('%s * %s', $amountExpr, self::rate($docAlias, $currencyAlias));
    }
}
