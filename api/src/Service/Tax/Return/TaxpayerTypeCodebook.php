<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Číselník atributu `typ_popldpp` (typ poplatníka) formuláře DPPDP9 a číselník
 * `typ_dapdpp` (typ daňového přiznání) téhož formuláře.
 *
 * Zdroj: `xs:documentation` atributů `typ_popldpp` a `typ_dapdpp` v
 * `api/xsd/dppdp9_epo2.xsd` (popis struktury písemnosti DPPDP9 na daňovém portálu
 * MF ČR). Obojí nese kritickou kontrolu „kód musí být vyplněn a nabývat pouze
 * hodnot uvedených v masce položky", takže hodnotu mimo číselník úřad odmítne.
 *
 * SSOT — volá ho {@see DppoXmlBuilder} (validace hodnoty jdoucí do XML) i
 * {@see UnsupportedCaseDetector} (detekce nepodporovaného poplatníka). Nikdy
 * nekopírovat výčet jinam.
 */
final class TaxpayerTypeCodebook
{
    /** Jediný typ poplatníka, pro který aplikace umí sestavit celé přiznání. */
    public const DEFAULT_CODE = '1';

    /** typ_popldpp → český popis (dle dokumentace atributu v dppdp9_epo2.xsd). */
    public const TAXPAYER_TYPES = [
        '0' => 'nositel příslibu investiční pobídky ve formě slevy na dani podle § 35b zákona',
        '1' => 'ostatní',
        '2' => 'daňový nerezident (§ 17 odst. 4 zákona)',
        '3' => 'veřejně prospěšný poplatník (§ 17a zákona)',
        '4' => 'investiční fond podle zákona upravujícího investiční společnosti a investiční fondy vyjma podílových fondů',
        '5' => 'investiční společnost, vč. obhospodařovaných podílových fondů',
        '6' => 'instituce penzijního pojištění nebo penzijní společnost, vč. fondů penzijní společnosti (§ 17 odst. 1 písm. e) zákona)',
        '7' => 'poplatník, který byl po část zdaňovacího období základním investičním fondem (§ 20a zákona)',
        '8' => 'nositel příslibu investiční pobídky v daňové oblasti podle usnesení vlády',
        '9' => 'nositel příslibu investiční pobídky ve formě slevy na dani podle § 35a zákona',
    ];

    /**
     * typ_dapdpp → český popis. Aplikace umí jen 'A'; ostatní kódy mění obsah
     * i rozsah přiznání a datový model je nezná (viz private/DANE-PLAN.md § D).
     */
    public const RETURN_TYPES = [
        'A' => 'daňové přiznání za zdaňovací období',
        'B' => 'daňové přiznání při vstupu do likvidace',
        'C' => 'daňové přiznání v průběhu likvidace',
        'D' => 'daňové přiznání za část období předcházející zániku bez likvidace',
        'G' => 'daňové přiznání při ukončení činnosti v rámci privatizace',
        'H' => 'daňové přiznání za období předcházející návrhu na použití likvidačního zůstatku',
        'J' => 'daňové přiznání za období předcházející rozhodnému dni fúze, převodu jmění nebo rozdělení',
        'K' => 'daňové přiznání za období předcházející zápisu změny právní formy',
        'L' => 'daňové přiznání za období předcházející změně zdaňovacího období (kalendářní ↔ hospodářský rok)',
        'M' => 'daňové přiznání za období počínající dnem vzniku poplatníka',
        'O' => 'daňové přiznání za období předcházející změně daňového rezidenství do zahraničí',
        'P' => 'daňové přiznání ke dni nabytí účinnosti rozhodnutí o úpadku',
        'R' => 'daňové přiznání v průběhu insolvenčního řízení',
        'T' => 'daňové přiznání ke dni předložení konečné zprávy',
        'V' => 'daňové přiznání při ukončení svěřenského fondu',
        'Y' => 'daňové přiznání v průběhu vypořádání majetku svěřenského fondu',
        'Z' => 'daňové přiznání za období předcházející dni zániku svěřenského fondu',
    ];

    /**
     * Stav poplatníka (`supplier.tax_entity_status`) → typy přiznání, které pro něj
     * úřad očekává. Aplikace žádný z nich neumí sestavit — mapa slouží k tomu, aby
     * hláška účetní řekla, co má v portálu EPO vybrat, ne jen „nepodporujeme".
     */
    public const STATUS_RETURN_TYPES = [
        'liquidation' => ['B', 'C', 'H'],
        'insolvency' => ['P', 'R', 'T'],
        'transformation' => ['J', 'K'],
    ];

    /** Stav poplatníka → český popis pro hlášky. */
    public const ENTITY_STATUSES = [
        'normal' => 'běžný',
        'liquidation' => 'v likvidaci',
        'insolvency' => 'v insolvenci',
        'transformation' => 'po fúzi nebo přeměně',
    ];

    /**
     * Účetní vyhlášky (`uv_vyhl`) — dle dokumentace téhož atributu v dppdp9_epo2.xsd.
     * Aplikace sestavuje výkazy pouze podle vyhlášky 500/2002 Sb.
     */
    public const ACCOUNTING_DECREES = [
        '500' => 'vyhláška č. 500/2002 Sb. (podnikatelé v podvojném účetnictví)',
        '501' => 'vyhláška č. 501/2002 Sb. (banky a jiné finanční instituce)',
        '502' => 'vyhláška č. 502/2002 Sb. (pojišťovny)',
        '503' => 'vyhláška č. 503/2002 Sb. (zdravotní pojišťovny)',
        '504' => 'vyhláška č. 504/2002 Sb. (neziskové organizace v podvojném účetnictví)',
        '325' => 'vyhláška č. 325/2015 Sb. (jednoduché účetnictví)',
        '410' => 'vyhláška č. 410/2009 Sb. (vybrané účetní jednotky)',
    ];

    /** Účetní vyhláška, podle níž aplikace sestavuje přílohu účetní závěrky. */
    public const SUPPORTED_DECREE = '500';

    public static function isValidTaxpayerType(string $code): bool
    {
        return array_key_exists($code, self::TAXPAYER_TYPES);
    }

    public static function taxpayerTypeLabel(string $code): string
    {
        return self::TAXPAYER_TYPES[$code] ?? 'neznámý kód ' . $code;
    }

    public static function isValidReturnType(string $code): bool
    {
        return array_key_exists($code, self::RETURN_TYPES);
    }

    public static function returnTypeLabel(string $code): string
    {
        return self::RETURN_TYPES[$code] ?? 'neznámý kód ' . $code;
    }

    public static function entityStatusLabel(string $status): string
    {
        return self::ENTITY_STATUSES[$status] ?? $status;
    }

    public static function accountingDecreeLabel(string $decree): string
    {
        return self::ACCOUNTING_DECREES[$decree] ?? 'neznámé číslo vyhlášky ' . $decree;
    }

    /**
     * Normalizuje hodnotu z `supplier.epo_taxpayer_code`. Prázdno/NULL = účetní typ
     * poplatníka nepotvrdila; volající to musí odlišit od výslovně zvolené „1"
     * (viz {@see UnsupportedCaseDetector}).
     */
    public static function normalize(mixed $raw): ?string
    {
        $code = trim((string) ($raw ?? ''));

        return $code === '' ? null : $code;
    }
}
