<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\Fuel;

/**
 * Klíčová slova pro rozpoznání pohonných hmot na položkách faktur a v detailních
 * výpisech benzínek. Match je diakritiku-insensitive a case-insensitive.
 */
final class FuelKeywords
{
    /** Pohonné hmoty / palivo / dobíjení (řádek se stane tankováním/nabíjením). */
    private const FUEL = [
        'nafta', 'diesel', 'benzin', 'natural', 'super', 'premium', 'premiova',
        'adblue', 'ad blue', 'lpg', 'cng', 'phm', 'pohonne hmoty', 'pohonnych hmot', 'palivo',
        'efecta', 'verva', 'miles',
        // Elektromobilita — nabíjení v kWh (zrcadlí ELECTRIC).
        'nabij', 'dobij', 'kwh', 'elektromobil', 'wallbox', 'emobility', 'e-mobility', 'charging', 'recharge',
    ];

    /** Bezpečné výrazy pro účetní texty bez předem známého kontextu čerpací stanice. */
    private const ACCOUNTING_FUEL = [
        'nafta', 'motorova nafta', 'diesel', 'diesel fuel', 'benzin', 'automobilovy benzin',
        'natural 95', 'natural 98', 'super 95', 'super e5', 'super e10', 'super plus',
        'premium diesel', 'premiova nafta', 'adblue', 'ad blue', 'lpg', 'cng', 'phm',
        'pohonne hmoty', 'pohonnych hmot', 'palivo', 'tankovani phm', 'tankovanie',
        'kraftstoff', 'betankung', 'refuelling', 'motor fuel', 'unleaded petrol',
        'efecta', 'verva', 'nabijeni vozidla', 'nabijeni kwh', 'dobijeni elektromobilu',
        'nabijanie vozidla', 'dobijanie elektromobilu', 'ladevorgang', 'ladestrom',
        'e auto laden', 'ev charging', 'ev charging session', 'charging session',
        'vehicle charging', 'vehicle recharge', 'emobility', 'e-mobility',
    ];

    /** Elektrické dobíjení (energie v kWh) — podmnožina FUEL, slouží i k volbě jednotky. */
    private const ELECTRIC = [
        'nabij', 'dobij', 'kwh', 'elektromobil', 'wallbox', 'emobility', 'e-mobility', 'charging', 'recharge',
    ];

    /**
     * Nepalivové služby a zboží — veto, vyhrává nad FUEL (řádek se NEstane tankováním).
     *
     * Druhá skupina jsou NABÍJECÍ ZAŘÍZENÍ. Elektromobilita zavedla do FUEL obecné
     * „nabij"/„dobij"/„charging", jenže ta slova sedí i na nabíječku telefonu nebo
     * powerbanku — reálně to dry-run back-fillu 2026 ukázal na položkách „Mobile Origin
     * GaN 65W Charger". Nabíječka je věc (drobný majetek), ne palivo; nabíjení je služba.
     */
    private const NON_FUEL = [
        'myti', 'mytí', 'plosna cena', 'plošná cena', 'poplatek', 'dalnicni', 'dálniční',
        'mytne', 'mýtné', 'znamka', 'známka', 'parkovani', 'parkování', 'obcerstveni', 'občerstvení',
        // Nabíjecí zařízení (věc), nikoli nabíjení (energie).
        'nabijecka', 'nabíječka', 'nabijecky', 'nabíječky', 'charger', 'powerbank', 'power bank',
        'adapter', 'adaptér', 'kabel',
    ];

    /** SQL fragment (LOWER(col) REGEXP …) pro hrubý filtr palivových/nabíjecích položek na fakturách. */
    public const SQL_REGEXP = 'benzin|nafta|diesel|natural|adblue|phm|palivo|pohonn|premiov|verva|efecta|kwh|nabij|dobij|elektromobil|wallbox';

    public static function isFuel(string $text): bool
    {
        $n = self::normalize($text);
        if ($n === '') return false;
        // Nejdřív vyluč jednoznačné nepalivové služby.
        foreach (self::NON_FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) {
                // „premium myti" apod. je služba — non-fuel vyhrává.
                return false;
            }
        }
        foreach (self::FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) return true;
        }
        return false;
    }

    /**
     * Přísnější varianta {@see isFuel()} pro ÚČTOVÁNÍ (klasifikace nákladu na PHM).
     *
     * Rozdíl je jen v tom, JAK se matchuje — seznam slov je týž, aby kniha tankování
     * a účetnictví nikdy nerozhodly opačně. Zatímco na benzínkovém výpisu je substring
     * bezpečný (kontext je předem palivový), na popisech položek faktur by obecná slova
     * jako „super", „premium", „natural" nebo „miles" chytila i „Premium podpora" či
     * „Supermarket". Proto se tu matchuje na HRANICI SLOVA — stejná sémantika jako
     * ExpenseKindClassifier::containsWord(), odkud se tahle metoda volá.
     *
     * Veto NON_FUEL platí beze změny (mytí, dálniční známka, občerstvení… nejsou PHM).
     */
    public static function isFuelForAccounting(string $text): bool
    {
        $n = self::normalize($text);
        if ($n === '') {
            return false;
        }
        if (self::isNonFuelService($n)) {
            return false;
        }
        foreach (self::ACCOUNTING_FUEL as $kw) {
            if (preg_match('/(?:^| )' . preg_quote(self::normalize($kw), '/') . '/', $n) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function isNonFuelService(string $text): bool
    {
        $n = self::normalize($text);
        foreach (self::NON_FUEL as $kw) {
            if (str_contains($n, self::normalize($kw))) return true;
        }
        return false;
    }

    /** Popis odpovídá elektrickému dobíjení (energie v kWh), ne kapalnému palivu. */
    public static function isElectric(string $text): bool
    {
        $n = self::normalize($text);
        if ($n === '') return false;
        foreach (self::ELECTRIC as $kw) {
            if (str_contains($n, self::normalize($kw))) return true;
        }
        return false;
    }

    /**
     * Kanonická jednotka tankování/nabíjení: 'kWh' pro elektrické dobíjení, jinak 'l'.
     * Rozhoduje explicitní jednotka z dokladu (kWh) → jinak povaha popisu (nabíjení…).
     */
    public static function canonicalUnit(?string $unit, string $desc = ''): string
    {
        $u = str_replace([' ', '.', "\u{00A0}"], '', self::normalize((string) $unit));
        if (str_contains($u, 'kwh')) return 'kWh';
        // Bez jednoznačně kapalné jednotky se řídíme popisem (Nabíjení / kWh / wallbox…).
        if (self::isElectric($desc) && !in_array($u, ['l', 'litr', 'litru', 'litry', 'lt'], true)) {
            return 'kWh';
        }
        return 'l';
    }

    /** Odstraní diakritiku, lowercase, sjednotí whitespace. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = \MyInvoice\Support\Slugifier::transliterate($s); // sdílená mapa (superset)
        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
