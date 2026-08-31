<?php

declare(strict_types=1);

namespace MyInvoice\Service\Codebook;

/**
 * Číselník českých zdravotních pojišťoven (kód → název).
 *
 * Proč sdílená třída: číselník potřebují dvě nezávislé agendy — daňová
 * (Přehled OSVČ pro ZP) a mzdová (evidence pojišťovny zaměstnance, Přehled
 * o platbě pojistného, materializace závazků). Do té doby existoval jen jako
 * privátní konstanta daňové služby a mzdová větev si na pěti místech
 * duplikovala pouhou kontrolu tvaru `\d{3}`. Neexistující kód (typicky `999`)
 * tak prošel validací až do zákonného podání. Číselník proto žije mimo
 * `Service/Tax` i `Service/Payroll`, aby na něm mohly viset obě agendy,
 * aniž by na sobě navzájem závisely.
 *
 * Třída je záměrně bez frameworkových závislostí — jen statická data.
 */
final class HealthInsurers
{
    /**
     * Kód → název. Pozor: PHP kanonické číselné klíče převede na int, takže
     * klíče tady jsou `int`, i když se s nimi všude pracuje jako s řetězcem
     * („111"). Vyhledání `self::CODES['111']` funguje díky téže konverzi;
     * `'0111'` naopak zůstane řetězcem a správně se nenajde.
     *
     * @var array<array-key,string>
     */
    public const CODES = [
        '111' => 'Všeobecná zdravotní pojišťovna ČR (VZP)',
        '201' => 'Vojenská zdravotní pojišťovna ČR (VoZP)',
        '205' => 'Česká průmyslová zdravotní pojišťovna (ČPZP)',
        '207' => 'Oborová zdravotní pojišťovna (OZP)',
        '209' => 'Zaměstnanecká pojišťovna Škoda (ZPŠ)',
        '211' => 'Zdravotní pojišťovna ministerstva vnitra ČR (ZPMV)',
        '213' => 'Revírní bratrská pokladna (RBP)',
    ];

    /**
     * Krátké zkratky pro chybové hlášky — plné názvy by hlášku znečitelnily.
     *
     * @var array<array-key,string>
     */
    private const ABBREVIATIONS = [
        '111' => 'VZP',
        '201' => 'VoZP',
        '205' => 'ČPZP',
        '207' => 'OZP',
        '209' => 'ZPŠ',
        '211' => 'ZPMV',
        '213' => 'RBP',
    ];

    /** @return array<array-key,string> kód → název */
    public static function all(): array
    {
        return self::CODES;
    }

    public static function isValid(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    public static function name(string $code): ?string
    {
        return self::CODES[$code] ?? null;
    }

    /**
     * Zkratka pojišťovny („VZP"). Plný název je do popisku položky v seznamu
     * moc dlouhý, ale samotný kód účetní s pojišťovnou nespojí.
     */
    public static function abbreviation(string $code): ?string
    {
        return self::ABBREVIATIONS[$code] ?? null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(strval(...), array_keys(self::CODES));
    }

    /** Výčet „111 VZP, 201 VoZP, …" do chybových hlášek. */
    public static function listForMessage(): string
    {
        $parts = [];
        foreach (self::ABBREVIATIONS as $code => $abbreviation) {
            $parts[] = $code . ' ' . $abbreviation;
        }

        return implode(', ', $parts);
    }

    /**
     * Jednotná, akceschopná hláška pro neplatný kód: uživatel se z ní dozví,
     * co zadal špatně, i z čeho má vybírat.
     */
    public static function invalidCodeMessage(string $code): string
    {
        $shown = $code === '' ? '(prázdný)' : $code;

        return sprintf(
            'Kód zdravotní pojišťovny %s neexistuje. Použijte kód ze seznamu: %s.',
            $shown,
            self::listForMessage(),
        );
    }
}
