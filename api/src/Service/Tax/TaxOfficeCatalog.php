<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Číselník finančních úřadů a jejich územních pracovišť.
 *
 * Jediný zdroj kódů a názvů pro výběr v nastavení firmy, REGZEL i bankovní účty.
 * Kód úřadu je `c_ufo` z EPO číselníku `ufo` (stejná hodnota jako
 * `supplier.financial_office_code`), kód pracoviště je `c_pracufo` z číselníku
 * `pracufo` (`supplier.workplace_code`). REGZEL vede úřad pod čtyřmístným kódem
 * (`k_ufo_vema`), pracoviště pod stejným kódem jako EPO.
 *
 * Zdroj: https://adisspr.mfcr.cz/adistc/epo_ciselnik?C=ufo (verze 51.5.00670)
 * a ?C=pracufo (verze 51.5.00210), platné záznamy k 3. 11. 2026.
 */
final class TaxOfficeCatalog
{
    public const SPECIALIZED_OFFICE = '13';

    /** kód FÚ (c_ufo) → [název, kód REGZEL] */
    private const OFFICES = [
        '451' => ['Finanční úřad pro hlavní město Prahu', '2000'],
        '452' => ['Finanční úřad pro Středočeský kraj', '2100'],
        '453' => ['Finanční úřad pro Jihočeský kraj', '2200'],
        '454' => ['Finanční úřad pro Plzeňský kraj', '2300'],
        '455' => ['Finanční úřad pro Karlovarský kraj', '2400'],
        '456' => ['Finanční úřad pro Ústecký kraj', '2500'],
        '457' => ['Finanční úřad pro Liberecký kraj', '2600'],
        '458' => ['Finanční úřad pro Královéhradecký kraj', '2700'],
        '459' => ['Finanční úřad pro Pardubický kraj', '2800'],
        '460' => ['Finanční úřad pro Kraj Vysočina', '2900'],
        '461' => ['Finanční úřad pro Jihomoravský kraj', '3000'],
        '462' => ['Finanční úřad pro Olomoucký kraj', '3100'],
        '463' => ['Finanční úřad pro Moravskoslezský kraj', '3200'],
        '464' => ['Finanční úřad pro Zlínský kraj', '3300'],
        self::SPECIALIZED_OFFICE => ['Specializovaný finanční úřad', '4000'],
    ];

    /** kód pracoviště (c_pracufo) → [název, kód FÚ] */
    private const WORKPLACES = [
        '2001' => ['Územní pracoviště pro Prahu 1', '451'],
        '2002' => ['Územní pracoviště pro Prahu 2', '451'],
        '2003' => ['Územní pracoviště pro Prahu 3', '451'],
        '2004' => ['Územní pracoviště pro Prahu 4', '451'],
        '2005' => ['Územní pracoviště pro Prahu 5', '451'],
        '2006' => ['Územní pracoviště pro Prahu 6', '451'],
        '2007' => ['Územní pracoviště pro Prahu 7', '451'],
        '2008' => ['Územní pracoviště pro Prahu 8', '451'],
        '2009' => ['Územní pracoviště pro Prahu 9', '451'],
        '2010' => ['Územní pracoviště pro Prahu 10', '451'],
        '2011' => ['Územní pracoviště pro Prahu - Jižní Město', '451'],
        '2012' => ['Územní pracoviště v Praze - Modřanech', '451'],
        '2101' => ['Územní pracoviště Praha-východ', '452'],
        '2102' => ['Územní pracoviště Praha-západ', '452'],
        '2103' => ['Územní pracoviště v Benešově', '452'],
        '2104' => ['Územní pracoviště v Berouně', '452'],
        '2105' => ['Územní pracoviště v Brandýse n.L.-Staré Bol.', '452'],
        '2106' => ['Územní pracoviště v Čáslavi', '452'],
        '2109' => ['Územní pracoviště v Hořovicích', '452'],
        '2110' => ['Územní pracoviště v Kladně', '452'],
        '2111' => ['Územní pracoviště v Kolíně', '452'],
        '2112' => ['Územní pracoviště v Kralupech nad Vltavou', '452'],
        '2113' => ['Územní pracoviště v Kutné Hoře', '452'],
        '2114' => ['Územní pracoviště v Mělníce', '452'],
        '2115' => ['Územní pracoviště v Mladé Boleslavi', '452'],
        '2118' => ['Územní pracoviště v Nymburku', '452'],
        '2119' => ['Územní pracoviště v Poděbradech', '452'],
        '2120' => ['Územní pracoviště v Příbrami', '452'],
        '2121' => ['Územní pracoviště v Rakovníku', '452'],
        '2122' => ['Územní pracoviště v Říčanech', '452'],
        '2124' => ['Územní pracoviště ve Slaném', '452'],
        '2125' => ['Územní pracoviště ve Vlašimi', '452'],
        '2201' => ['Územní pracoviště v Českých Budějovicích', '453'],
        '2203' => ['Územní pracoviště v Českém Krumlově', '453'],
        '2205' => ['Územní pracoviště v Jindřichově Hradci', '453'],
        '2208' => ['Územní pracoviště v Písku', '453'],
        '2209' => ['Územní pracoviště v Prachaticích', '453'],
        '2211' => ['Územní pracoviště ve Strakonicích', '453'],
        '2212' => ['Územní pracoviště v Táboře', '453'],
        '2301' => ['Územní pracoviště v Plzni', '454'],
        '2302' => ['Územní pracoviště Plzeň-sever', '454'],
        '2303' => ['Územní pracoviště Plzeň-jih', '454'],
        '2305' => ['Územní pracoviště v Domažlicích', '454'],
        '2308' => ['Územní pracoviště v Klatovech', '454'],
        '2312' => ['Územní pracoviště v Rokycanech', '454'],
        '2313' => ['Územní pracoviště v Tachově', '454'],
        '2401' => ['Územní pracoviště v Karlových Varech', '455'],
        '2403' => ['Územní pracoviště v Chebu', '455'],
        '2407' => ['Územní pracoviště v Sokolově', '455'],
        '2501' => ['Územní pracoviště v Ústí nad Labem', '456'],
        '2503' => ['Územní pracoviště v Děčíně', '456'],
        '2504' => ['Územní pracoviště v Chomutově', '456'],
        '2505' => ['Územní pracoviště v Kadani', '456'],
        '2507' => ['Územní pracoviště v Litoměřicích', '456'],
        '2509' => ['Územní pracoviště v Lounech', '456'],
        '2510' => ['Územní pracoviště v Mostě', '456'],
        '2512' => ['Územní pracoviště v Roudnici nad Labem', '456'],
        '2513' => ['Územní pracoviště v Rumburku', '456'],
        '2514' => ['Územní pracoviště v Teplicích', '456'],
        '2515' => ['Územní pracoviště v Žatci', '456'],
        '2601' => ['Územní pracoviště v Liberci', '457'],
        '2602' => ['Územní pracoviště v České Lípě', '457'],
        '2604' => ['Územní pracoviště v Jablonci nad Nisou', '457'],
        '2607' => ['Územní pracoviště v Semilech', '457'],
        '2609' => ['Územní pracoviště v Turnově', '457'],
        '2701' => ['Územní pracoviště v Hradci Králové', '458'],
        '2707' => ['Územní pracoviště v Jičíně', '458'],
        '2709' => ['Územní pracoviště v Náchodě', '458'],
        '2712' => ['Územní pracoviště v Rychnově nad Kněžnou', '458'],
        '2713' => ['Územní pracoviště v Trutnově', '458'],
        '2801' => ['Územní pracoviště v Pardubicích', '459'],
        '2804' => ['Územní pracoviště v Chrudimi', '459'],
        '2808' => ['Územní pracoviště ve Svitavách', '459'],
        '2809' => ['Územní pracoviště v Ústí nad Orlicí', '459'],
        '2810' => ['Územní pracoviště ve Vysokém Mýtě', '459'],
        '2811' => ['Územní pracoviště v Žamberku', '459'],
        '2901' => ['Územní pracoviště v Jihlavě', '460'],
        '2903' => ['Územní pracoviště v Havlíčkově Brodě', '460'],
        '2910' => ['Územní pracoviště v Pelhřimově', '460'],
        '2912' => ['Územní pracoviště v Třebíči', '460'],
        '2913' => ['Územní pracoviště ve Velkém Meziříčí', '460'],
        '2914' => ['Územní pracoviště ve Žďáru nad Sázavou', '460'],
        '3001' => ['Územní pracoviště Brno I', '461'],
        '3002' => ['Územní pracoviště Brno II', '461'],
        '3003' => ['Územní pracoviště Brno III', '461'],
        '3004' => ['Územní pracoviště Brno IV', '461'],
        '3005' => ['Územní pracoviště Brno-venkov', '461'],
        '3006' => ['Územní pracoviště v Blansku', '461'],
        '3007' => ['Územní pracoviště v Boskovicích', '461'],
        '3008' => ['Územní pracoviště v Břeclavi', '461'],
        '3010' => ['Územní pracoviště v Hodoníně', '461'],
        '3011' => ['Územní pracoviště v Hustopečích', '461'],
        '3013' => ['Územní pracoviště v Kyjově', '461'],
        '3018' => ['Územní pracoviště ve Veselí nad Moravou', '461'],
        '3019' => ['Územní pracoviště ve Vyškově', '461'],
        '3020' => ['Územní pracoviště ve Znojmě', '461'],
        '3101' => ['Územní pracoviště v Olomouci', '462'],
        '3102' => ['Územní pracoviště v Hranicích', '462'],
        '3103' => ['Územní pracoviště v Jeseníku', '462'],
        '3106' => ['Územní pracoviště v Prostějově', '462'],
        '3107' => ['Územní pracoviště v Přerově', '462'],
        '3108' => ['Územní pracoviště ve Šternberku', '462'],
        '3109' => ['Územní pracoviště v Šumperku', '462'],
        '3110' => ['Územní pracoviště v Zábřehu', '462'],
        '3201' => ['Územní pracoviště Ostrava I', '463'],
        '3202' => ['Územní pracoviště Ostrava II', '463'],
        '3203' => ['Územní pracoviště Ostrava III', '463'],
        '3205' => ['Územní pracoviště v Bruntále', '463'],
        '3207' => ['Územní pracoviště ve Frýdku-Místku', '463'],
        '3210' => ['Územní pracoviště v Havířově', '463'],
        '3212' => ['Územní pracoviště v Karviné', '463'],
        '3213' => ['Územní pracoviště v Kopřivnici', '463'],
        '3214' => ['Územní pracoviště v Krnově', '463'],
        '3215' => ['Územní pracoviště v Novém Jičíně', '463'],
        '3216' => ['Územní pracoviště v Opavě', '463'],
        '3218' => ['Územní pracoviště v Třinci', '463'],
        '3301' => ['Územní pracoviště ve Zlíně', '464'],
        '3304' => ['Územní pracoviště v Kroměříži', '464'],
        '3306' => ['Územní pracoviště v Otrokovicích', '464'],
        '3307' => ['Územní pracoviště v Rožnově pod Radhoštěm', '464'],
        '3308' => ['Územní pracoviště v Uherském Brodě', '464'],
        '3309' => ['Územní pracoviště v Uherském Hradišti', '464'],
        '3310' => ['Územní pracoviště ve Valašském Meziříčí', '464'],
        '3312' => ['Územní pracoviště ve Vsetíně', '464'],
    ];

    /** @return list<array{code:string,name:string,regzel_code:string}> */
    public static function offices(): array
    {
        $offices = [];
        foreach (self::OFFICES as $code => [$name, $regzel]) {
            $offices[] = ['code' => (string) $code, 'name' => $name, 'regzel_code' => $regzel];
        }
        return $offices;
    }

    /** @return list<array{code:string,name:string,office_code:string}> */
    public static function workplaces(?string $officeCode = null): array
    {
        $office = $officeCode === null ? null : self::normalizeOffice($officeCode);
        $workplaces = [];
        foreach (self::WORKPLACES as $code => [$name, $parent]) {
            if ($office !== null && $parent !== $office) continue;
            $workplaces[] = ['code' => (string) $code, 'name' => $name, 'office_code' => $parent];
        }
        return $workplaces;
    }

    public static function officeName(?string $officeCode): ?string
    {
        return self::OFFICES[self::normalizeOffice((string) $officeCode)][0] ?? null;
    }

    public static function workplaceName(?string $workplaceCode): ?string
    {
        return self::WORKPLACES[trim((string) $workplaceCode)][0] ?? null;
    }

    /** Kód úřadu, pod který pracoviště patří, nebo null u neznámého kódu. */
    public static function officeOfWorkplace(?string $workplaceCode): ?string
    {
        return self::WORKPLACES[trim((string) $workplaceCode)][1] ?? null;
    }

    /** Čtyřmístný kód úřadu pro REGZEL (kodFU), nebo null u neznámého kódu FÚ. */
    public static function regzelOfficeCode(?string $officeCode): ?string
    {
        return self::OFFICES[self::normalizeOffice((string) $officeCode)][1] ?? null;
    }

    /** Kód FÚ (c_ufo) k REGZEL kódu úřadu, nebo null u neznámého kódu. */
    public static function officeForRegzelCode(string $regzelCode): ?string
    {
        foreach (self::OFFICES as $code => [, $regzel]) {
            if ($regzel === trim($regzelCode)) return (string) $code;
        }
        return null;
    }

    /** EPO kód bez úvodních nul, jak ho píše ARES i starší data („0451" → „451"). */
    private static function normalizeOffice(string $code): string
    {
        $code = ltrim(trim($code), '0');
        return $code === '' ? '0' : $code;
    }
}
