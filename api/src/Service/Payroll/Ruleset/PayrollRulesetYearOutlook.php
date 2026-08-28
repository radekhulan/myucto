<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use DateTimeImmutable;

/**
 * Výhled pokrytí mzdových let DOPŘEDU.
 *
 * ── Proč to existuje ─────────────────────────────────────────────────────────
 * Registry umí fail-closed odmítnout výpočet pro rok, na který nemá ruleset
 * ({@see PayrollRulesetYearCoverage}). To je správně, ale je to zpráva doručená
 * v nejhorší možný okamžik: účetní ji uvidí až v lednu, když spustí lednovou
 * mzdu, a to je den, kdy se s tím nedá nic dělat rychle.
 *
 * Zákonné hodnoty na příští rok přitom vycházejí NA PODZIM — vyhláška
 * o průměrné mzdě podle § 15 odst. 4 z. č. 155/1995 Sb. se vyhlašuje do konce
 * září, nařízení o minimální mzdě a o nezabavitelných částkách bývají v listopadu
 * a prosinci. Existuje tedy několikaměsíční okno, ve kterém je sada doplnitelná
 * v klidu. Tenhle výhled to okno hlídá.
 *
 * ── Co to NENÍ ───────────────────────────────────────────────────────────────
 * Není to dopočet chybějícího roku ani jeho kostra. Kostra sady s nevyplněnými
 * hodnotami by byla horší než nic: {@see PayrollRulesetYearCoverage} počítá
 * pokrytí z ÚČINNOSTI verze, ne z toho, jestli má použitelné hodnoty, takže
 * prázdná sada pro 2027 by rok rozsvítila v `SupportMatrix::supportedYears()`
 * jako podporovaný. Chybějící rok proto zůstává chybějící a mluví se o něm
 * varováním, ne fiktivní verzí.
 */
final class PayrollRulesetYearOutlook
{
    /**
     * Kolik let dopředu se kontroluje. Dvě: příští rok (ten musí být hotový)
     * a přespříští (ten je zatím jen informace, hodnoty pro něj neexistují).
     */
    public const HORIZON_YEARS = 2;

    /**
     * Den, od kterého se chybějící PŘÍŠTÍ rok hlásí jako naléhavý. Do té doby
     * je to jen poznámka — hodnoty ještě nejsou vyhlášené a nemá cenu s tím
     * účetní strašit.
     *
     * 1. října: vyhláška o všeobecném vyměřovacím základu a přepočítacím
     * koeficientu (§ 15 odst. 4 z. č. 155/1995 Sb.) musí vyjít do 30. září,
     * takže od 1. října je průměrná mzda pro příští rok známá a sada je
     * sestavitelná.
     */
    public const URGENT_FROM_MONTH = 10;

    public const URGENT_FROM_DAY = 1;

    /**
     * Stupně závažnosti výhledu, od nejmírnějšího. Pořadí je zároveň pořadím
     * naléhavosti, které používá {@see self::worstSeverity()}.
     *
     * @var list<string>
     */
    public const SEVERITIES = ['ok', 'info', 'warning', 'critical'];

    /**
     * @return list<array{
     *   year:int,
     *   covered:bool,
     *   severity:string,
     *   missing_domains:list<string>,
     *   code:string,
     *   message:string
     * }>
     */
    public static function forProvider(
        PayrollRulesetProvider $rulesets,
        ?DateTimeImmutable $today = null,
    ): array {
        $today ??= new DateTimeImmutable('now');
        $currentYear = (int) $today->format('Y');

        $outlook = [];
        for ($offset = 1; $offset <= self::HORIZON_YEARS; $offset++) {
            $year = $currentYear + $offset;
            $missing = self::missingDomains($rulesets, $year);
            $covered = $missing === [];
            $severity = self::severity($covered, $offset, $today);

            $outlook[] = [
                'year' => $year,
                'covered' => $covered,
                'severity' => $severity,
                'missing_domains' => $missing,
                'code' => $covered ? 'year_covered' : 'year_ruleset_missing',
                'message' => self::message($year, $covered, $severity, $missing, $currentYear),
            ];
        }

        return $outlook;
    }

    /**
     * Nejzávažnější stupeň výhledu — pro jednoduché „svítí / nesvítí" v UI.
     * Pořadí je `ok` < `info` < `warning` < `critical`.
     */
    public static function worstSeverity(
        PayrollRulesetProvider $rulesets,
        ?DateTimeImmutable $today = null,
    ): string {
        $rank = array_flip(self::SEVERITIES);
        $worst = 'ok';
        foreach (self::forProvider($rulesets, $today) as $entry) {
            if ($rank[$entry['severity']] > $rank[$worst]) {
                $worst = $entry['severity'];
            }
        }

        return $worst;
    }

    /** @return list<string> */
    private static function missingDomains(PayrollRulesetProvider $rulesets, int $year): array
    {
        $missing = [];
        foreach (PayrollRulesetYearCoverage::CALCULATION_CRITICAL_DOMAINS as $domain) {
            if (!PayrollRulesetYearCoverage::coversYear($rulesets, $domain, $year)) {
                $missing[] = $domain->value;
            }
        }
        sort($missing, SORT_STRING);

        return $missing;
    }

    private static function severity(bool $covered, int $offset, DateTimeImmutable $today): string
    {
        if ($covered) {
            return 'ok';
        }
        // Přespříští rok chybí legitimně: hodnoty pro něj ještě nikdo nevyhlásil.
        if ($offset > 1) {
            return 'info';
        }

        return self::pastUrgentThreshold($today) ? 'critical' : 'warning';
    }

    private static function pastUrgentThreshold(DateTimeImmutable $today): bool
    {
        $threshold = $today->setDate(
            (int) $today->format('Y'),
            self::URGENT_FROM_MONTH,
            self::URGENT_FROM_DAY,
        )->setTime(0, 0);

        return $today >= $threshold;
    }

    /** @param list<string> $missing */
    private static function message(
        int $year,
        bool $covered,
        string $severity,
        array $missing,
        int $currentYear,
    ): string {
        if ($covered) {
            return "Mzdový rok {$year} je pokrytý legislativní sadou.";
        }

        $domains = implode(', ', $missing);
        $base = "Pro mzdový rok {$year} chybí legislativní sada domén: {$domains}.";

        return match ($severity) {
            'critical' => $base
                . " Zákonné hodnoty pro rok {$year} už jsou vyhlášené — doplňte sadu"
                . ' v administraci mzdových rulesetů (Mzdy → Legislativní sady) JEŠTĚ LETOS.'
                . " Od 1. 1. {$year} mzdový modul bez ní nespočítá ani jednu výplatu.",
            'warning' => $base
                . ' Hodnoty se vyhlašují na podzim (průměrná mzda do 30. 9., minimální mzda'
                . ' a nezabavitelné částky obvykle v listopadu a prosinci). Sada musí být'
                . " v administraci mzdových rulesetů nejpozději k 31. 12. {$currentYear}.",
            default => $base
                . ' Zákonné hodnoty pro tenhle rok zatím vyhlášené nejsou; je to informace,'
                . ' ne úkol.',
        };
    }
}
