<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Číselník řádků přiznání k dani z příjmů pro pracovní PDF sestavu: číslo řádku
 * tiskopisu a jeho popisek. Jediný zdroj popisků pro souhrn i tabulku řádků sestavy
 * ({@see TaxReturnReportBuilder}), aby se tentýž řádek nejmenoval na dvou místech
 * jinak a žádný řádek nezůstal bez názvu.
 *
 * DPPO: klíče pokrývají všechny řádky {@see DppoXmlBuilder::LINE_ATTR} a řádky, které
 * builder píše mimo ni (220, 280, 330). DPFO: klíčem je atribut XML DPFDP7, hodnotou
 * číslo řádku tiskopisu a popisek, v pořadí tiskopisu.
 */
final class TaxReturnLineCatalog
{
    /** DPPDP9, II. oddíl: číslo řádku → popisek. */
    public const PO = [
        10 => 'Výsledek hospodaření před zdaněním (zisk +, ztráta −)',
        20 => 'Částky neoprávněně zkracující příjmy a hodnota nepeněžních příjmů (§ 23 odst. 3 písm. a) bod 1)',
        30 => 'Částky zvyšující výsledek hospodaření podle § 23 odst. 3 písm. a) (mimo ř. 20 a 40)',
        40 => 'Výdaje neuznávané za výdaje vynaložené k dosažení, zajištění a udržení příjmů (§ 24, § 25)',
        50 => 'Rozdíl, o který účetní odpisy převyšují daňové odpisy (§ 26 až § 33)',
        61 => 'Úprava výsledku hospodaření poplatníka vstupujícího do likvidace (zvýšení)',
        62 => 'Ostatní částky zvyšující výsledek hospodaření (§ 23)',
        70 => 'Úhrn částek zvyšujících výsledek hospodaření (ř. 20 až 62)',
        100 => 'Příjmy, které nejsou předmětem daně (§ 18 odst. 2)',
        101 => 'Příjmy veřejně prospěšného poplatníka, které nejsou předmětem daně (§ 18a odst. 1)',
        109 => 'Příjmy osvobozené od daně (§ 19b)',
        110 => 'Příjmy osvobozené od daně (§ 19)',
        111 => 'Částky snižující výsledek hospodaření podle § 23 odst. 3 písm. b)',
        112 => 'Doplňková informace podle § 23 odst. 3 písm. c), např. paušální výdaj na dopravu',
        120 => 'Příjmy zdaňované zvláštní sazbou daně vybírané srážkou (§ 36)',
        130 => 'Příjmy zdaňované v samostatném základu daně (§ 21 odst. 4)',
        140 => 'Částky nezahrnované do základu daně (§ 23 odst. 4)',
        150 => 'Rozdíl, o který daňové odpisy převyšují účetní odpisy (§ 26 až § 33)',
        160 => 'Rozdíl, o který daňové výdaje převyšují účetní náklady (§ 24), např. daňová zůstatková cena vyřazeného majetku nad účetní',
        161 => 'Úprava výsledku hospodaření poplatníka vstupujícího do likvidace (snížení)',
        162 => 'Ostatní částky snižující výsledek hospodaření (§ 23)',
        170 => 'Úhrn částek snižujících výsledek hospodaření (ř. 100 až 165)',
        200 => 'Základ daně (ř. 10 + ř. 70 − ř. 170)',
        220 => 'Základ daně před odečtem ztráty a darů',
        230 => 'Odečet daňové ztráty (§ 34 odst. 1)',
        242 => 'Odečet na podporu výzkumu a vývoje (§ 34 odst. 4, § 34a až § 34e)',
        243 => 'Odečet na podporu odborného vzdělávání (§ 34 odst. 4, § 34f až § 34h)',
        250 => 'Základ daně snížený o odčitatelné položky podle § 34',
        251 => 'Snížení základu daně veřejně prospěšného poplatníka (§ 20 odst. 7)',
        260 => 'Odečet darů na veřejně prospěšné účely (§ 20 odst. 8)',
        270 => 'Základ daně snížený o dary, zaokrouhlený na celé tisíce Kč dolů',
        280 => 'Sazba daně',
        290 => 'Daň (ř. 270 × sazba daně)',
        300 => 'Slevy na dani (§ 35 odst. 1 a 4)',
        310 => 'Daň po uplatnění slev (ř. 290 − ř. 300)',
        330 => 'Daň podle sazby ze základu na ř. 220 (kontrolní údaj)',
        340 => 'Daň celkem',
        360 => 'Poslední známá daňová povinnost pro stanovení záloh (§ 38a)',
    ];

    /** DPPDP9, údaje mimo II. oddíl (věta D): atribut → popisek. */
    public const PO_OTHER = [
        'kc_v_1' => 'Úhrn zaplacených záloh na daň',
    ];

    /** DPFDP7: atribut XML → [číslo řádku, popisek], v pořadí tiskopisu. */
    public const FO = [
        'kc_prij6' => ['31', 'Úhrn příjmů ze závislé činnosti (§ 6)'],
        'kc_zd6' => ['34', 'Dílčí základ daně z příjmů ze závislé činnosti (§ 6)'],
        'kc_zd7' => ['37', 'Dílčí základ daně z příjmů ze samostatné činnosti (§ 7)'],
        'kc_zakldan8' => ['38', 'Dílčí základ daně z kapitálového majetku (§ 8)'],
        'kc_zd9' => ['39', 'Dílčí základ daně z nájmu (§ 9)'],
        'kc_zd10' => ['40', 'Dílčí základ daně z ostatních příjmů (§ 10)'],
        'kc_uhrn' => ['41', 'Úhrn dílčích základů daně § 7 až § 10 (ř. 37 až 40)'],
        'kc_zakldan23' => ['42', 'Základ daně'],
        'kc_ztrata2' => ['44', 'Uplatňovaná daňová ztráta (§ 34)'],
        'kc_zakldan' => ['45', 'Základ daně po odečtení ztráty (ř. 42 − ř. 44)'],
        'kc_odcelk' => ['54', 'Úhrn nezdanitelných částí základu daně a odčitatelných položek (§ 15)'],
        'kc_zdsniz' => ['55', 'Základ daně snížený o nezdanitelné části (ř. 45 − ř. 54)'],
        'kc_zdzaokr' => ['56', 'Základ daně zaokrouhlený na celá sta Kč dolů'],
        'da_dan16' => ['57', 'Daň podle § 16'],
        'uhrn_slevy35ba' => ['70', 'Úhrn slev na dani podle § 35ba'],
        'da_slevy35ba' => ['71', 'Daň po uplatnění slev podle § 35ba'],
        'kc_dazvyhod' => ['72', 'Daňové zvýhodnění na vyživované dítě (§ 35c)'],
        'kc_slevy35c' => ['73', 'Sleva na dani podle § 35c'],
        'da_slevy35c' => ['74', 'Daň po uplatnění slevy podle § 35c'],
        'kc_danbonus' => ['75', 'Daňový bonus'],
        'kc_zalzavc' => ['84', 'Úhrn sražených záloh na daň ze závislé činnosti'],
        'kc_zalpred' => ['85', 'Zaplacené zálohy na daň'],
        'kc_zbyvpred' => ['91', 'Zbývá doplatit (+) nebo přeplatek (−)'],
    ];

    public static function po(int $line): string
    {
        return self::PO[$line] ?? throw new \OutOfBoundsException("Řádek DPPDP9 {$line} není v číselníku.");
    }

    /** @return array{0:string,1:string} číslo řádku a popisek */
    public static function fo(string $attr): array
    {
        return self::FO[$attr] ?? throw new \OutOfBoundsException("Atribut DPFDP7 {$attr} není v číselníku.");
    }
}
