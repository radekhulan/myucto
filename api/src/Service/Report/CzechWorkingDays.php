<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * České pracovní dny pro lhůty daňových podání.
 *
 * § 33 odst. 4 daňového řádu (280/2009 Sb.): připadne-li poslední den lhůty
 * na sobotu, neděli nebo svátek, je posledním dnem lhůty nejblíže následující
 * pracovní den. Bez posunu aplikace chybně hlásila „po termínu" (např.
 * KH 06/2026: 25. 7. 2026 = sobota → skutečný termín pondělí 27. 7. 2026).
 *
 * Svátky dle zákona 245/2000 Sb. čte z ČÍSELNÍKU `public_holidays` (migrace
 * 1781) — novela zákona je od té chvíle jeden řádek v databázi, ne nová verze
 * aplikace. Pohyblivé svátky (Velký pátek, Velikonoční pondělí) nese číselník
 * jako pravidlo „posun ode dne Velikonoční neděle"; samotné Velikonoce se
 * počítají tady (gregoriánský computus), protože jejich datum není rozhodnutí
 * zákonodárce, ale výsledek stabilního algoritmu.
 *
 * Bez naplněného číselníku (instalace bez seedu, CLI bez kontejneru, unit testy)
 * se helper vrací k {@see FALLBACK_FIXED_HOLIDAYS} — a HLASITĚ: jednou za běh
 * zapíše varování do error logu a {@see usingFallback} to přizná volajícímu.
 * Tichý fallback by znamenal, že instalace počítá lhůty podle stavu zákona
 * v době vydání a nikdo se to nedozví.
 */
final class CzechWorkingDays
{
    /**
     * Poslední ověřený stav zákona zapečený v kódu — POJISTKA, ne zdroj pravdy.
     *
     * Používá se jen tehdy, když číselník `public_holidays` není k dispozici nebo
     * je prázdný. Musí zůstat shodný se seedem migrace 1781: odpověď aplikace
     * nesmí záviset na tom, jestli je instalace naseedovaná.
     *
     * @var array<string,array{code:string,name:string}>
     */
    private const FALLBACK_FIXED_HOLIDAYS = [
        '01-01' => ['code' => 'new_year', 'name' => 'Nový rok'],
        '05-01' => ['code' => 'labour_day', 'name' => 'Svátek práce'],
        '05-08' => ['code' => 'victory_day', 'name' => 'Den vítězství'],
        '07-05' => ['code' => 'cyril_methodius', 'name' => 'Den slovanských věrozvěstů Cyrila a Metoděje'],
        '07-06' => ['code' => 'jan_hus', 'name' => 'Den upálení mistra Jana Husa'],
        '09-28' => ['code' => 'statehood_day', 'name' => 'Den české státnosti'],
        '10-28' => ['code' => 'independent_state_day', 'name' => 'Den vzniku samostatného československého státu'],
        '11-17' => ['code' => 'freedom_democracy_day', 'name' => 'Den boje za svobodu a demokracii'],
        '12-24' => ['code' => 'christmas_eve', 'name' => 'Štědrý den'],
        '12-25' => ['code' => 'christmas_day', 'name' => '1. svátek vánoční'],
        '12-26' => ['code' => 'boxing_day', 'name' => '2. svátek vánoční'],
    ];

    /** Pohyblivá část pojistky — tytéž posuny, jaké seeduje migrace 1781. */
    private const FALLBACK_EASTER_HOLIDAYS = [
        ['code' => 'good_friday', 'name' => 'Velký pátek', 'offset' => -2],
        ['code' => 'easter_monday', 'name' => 'Velikonoční pondělí', 'offset' => 1],
    ];

    /**
     * Líná továrna na číselník — nastavuje ji Bootstrap. Líná proto, že helper
     * se volá i z míst, která databázi vůbec nepotřebují (formátování, testy).
     *
     * @var (callable():?PublicHolidayProvider)|null
     */
    private static $providerFactory = null;

    private static ?PublicHolidayProvider $provider = null;

    private static bool $providerResolved = false;

    private static bool $fallbackUsed = false;

    private static bool $fallbackReported = false;

    /** @var array<int,array<string,array{code:string,name:string}>> */
    private static array $yearCache = [];

    /**
     * Předá helperu číselník svátků. Volá se jednou z {@see \MyInvoice\Bootstrap}.
     *
     * @param (callable():?PublicHolidayProvider)|null $factory
     */
    public static function configure(?callable $factory): void
    {
        self::$providerFactory = $factory;
        self::$provider = null;
        self::$providerResolved = false;
        self::$yearCache = [];
        self::$fallbackUsed = false;
    }

    /** Jen pro testy — zahodí číselník i cache a vrátí helper do výchozího stavu. */
    public static function reset(): void
    {
        self::configure(null);
        self::$fallbackReported = false;
    }

    /**
     * Odpověděl poslední dotaz z pojistky v kódu místo z číselníku?
     *
     * Vrací stav POSLEDNÍHO vyhodnocení roku, ne konfigurace — číselník může
     * existovat a přesto být prázdný.
     */
    public static function usingFallback(): bool
    {
        return self::$fallbackUsed;
    }

    /**
     * Zákonný termín podání jako `Y-m-d` — zadané datum už posunuté podle § 33/4 DŘ.
     * Jediné místo, kde se lhůty výkazů skládají, ať se posun nikde nezapomene
     * (DPH přiznání, kontrolní i souhrnné hlášení, upozornění na dashboardu).
     */
    public static function deadline(int $year, int $month, int $day = 25): string
    {
        return self::shiftToWorkingDay(
            new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day))
        )->format('Y-m-d');
    }

    /**
     * Lhůta zadaná jako `MM-DD` v roce podání, posunutá podle § 33/4 DŘ.
     *
     * Termíny přiznání a přehledů jsou v daňových konstantách uložené jako holý
     * den v roce a jsou navíc uživatelsky editovatelné, takže posun se musí
     * uplatnit až při ČTENÍ — zapéct ho do konstanty by znamenalo, že platí
     * jen pro rok, ve kterém ji někdo naposledy přepsal. Neplatný vstup se
     * vrací beze změny, aby validaci tvaru řešila jediná vrstva (kodebook).
     */
    public static function deadlineFromMonthDay(int $year, string $monthDay): string
    {
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D', $monthDay) !== 1) {
            return sprintf('%04d-%s', $year, $monthDay);
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%s', $year, $monthDay),
        );
        if (!$date instanceof \DateTimeImmutable) {
            return sprintf('%04d-%s', $year, $monthDay);
        }

        return self::shiftToWorkingDay($date)->format('Y-m-d');
    }

    /** Posune datum na nejbližší NÁSLEDUJÍCÍ pracovní den (samo o sobě včetně). */
    public static function shiftToWorkingDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        while (!self::isWorkingDay($d)) {
            $d = $d->modify('+1 day');
        }
        return $d;
    }

    public static function isWorkingDay(\DateTimeImmutable $d): bool
    {
        // N = ISO den v týdnu (6 sobota, 7 neděle)
        if ((int) $d->format('N') >= 6) {
            return false;
        }
        return !self::isPublicHoliday($d);
    }

    public static function isPublicHoliday(\DateTimeImmutable $d): bool
    {
        return isset(self::holidaysForYear((int) $d->format('Y'))[$d->format('Y-m-d')]);
    }

    /**
     * Všechny svátky roku jako `Y-m-d` → kód a název, seřazené podle data.
     *
     * Odpověď na „je tenhle den svátek" i „jak se ten svátek jmenuje" se skládá
     * z jedné a téže množiny — dva nezávislé seznamy by se dřív nebo později
     * rozešly a projevilo by se to posunutou lhůtou nebo špatným fondem
     * pracovní doby.
     *
     * @return array<string,array{code:string,name:string}>
     */
    public static function holidaysForYear(int $year): array
    {
        if (isset(self::$yearCache[$year])) {
            return self::$yearCache[$year];
        }

        $rules = self::rules();
        $holidays = $rules === []
            ? self::fallbackHolidays($year)
            : self::fromRules($rules, $year);

        ksort($holidays);

        return self::$yearCache[$year] = $holidays;
    }

    /**
     * Velikonoční neděle (gregoriánský kalendář) — anonymní/Meeusův algoritmus.
     * Záměrně bez ext-calendar (easter_date), aby helper běžel všude.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    // ── Číselník ─────────────────────────────────────────────────────────────

    /**
     * Pravidla z číselníku, nebo prázdné pole (= použij pojistku).
     *
     * Selhání číselníku se NEPOLYKÁ do ticha: databáze může být nedostupná při
     * migraci, v CLI skriptu nebo během instalace, a helper musí i tak vrátit
     * odpověď — ale musí být poznat, že ji vrátil z kódu.
     *
     * @return list<array<string,mixed>>
     */
    private static function rules(): array
    {
        if (!self::$providerResolved) {
            self::$providerResolved = true;
            $factory = self::$providerFactory;
            if ($factory !== null) {
                try {
                    self::$provider = $factory();
                } catch (\Throwable $e) {
                    self::$provider = null;
                    self::reportFallback('číselník svátků není dostupný: ' . $e->getMessage());
                }
            }
        }
        if (self::$provider === null) {
            return [];
        }
        try {
            return self::$provider->holidayRules();
        } catch (\Throwable $e) {
            self::reportFallback('čtení číselníku svátků selhalo: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @return array<string,array{code:string,name:string}>
     */
    private static function fromRules(array $rules, int $year): array
    {
        self::$fallbackUsed = false;
        $holidays = [];

        foreach ($rules as $rule) {
            $date = self::ruleDate($rule, $year);
            if ($date === null) {
                continue;
            }
            $from = (string) ($rule['valid_from'] ?? '');
            $to = $rule['valid_to'] ?? null;
            if ($from !== '' && $date < $from) {
                continue;
            }
            if ($to !== null && $date > (string) $to) {
                continue;
            }
            $holidays[$date] = [
                'code' => (string) ($rule['code'] ?? ''),
                'name' => (string) ($rule['name'] ?? ''),
            ];
        }

        if ($holidays === []) {
            // Číselník řádky MÁ, ale pro tenhle rok žádný neplatí. Pojistka se
            // tu ZÁMĚRNĚ nepouští: datovaná platnost je vědomé rozhodnutí
            // správce a přebít ji konstantou by znamenalo, že zrušený svátek
            // aplikace dál posouvá. Hlásí se to ale nahlas — „rok bez jediného
            // svátku" je skoro jistě chyba v datech.
            error_log(sprintf(
                '[svatky] číselník `public_holidays` nemá pro rok %d žádný platný řádek — '
                    . 'zkontroluj valid_from/valid_to.',
                $year,
            ));
        }

        return $holidays;
    }

    /**
     * @param array<string,mixed> $rule
     * @return string|null `Y-m-d`, nebo NULL když je pravidlo neúplné
     */
    private static function ruleDate(array $rule, int $year): ?string
    {
        if ((string) ($rule['rule_type'] ?? 'fixed') === 'easter') {
            if (!isset($rule['easter_offset'])) {
                return null;
            }
            $offset = (int) $rule['easter_offset'];

            return self::easterSunday($year)
                ->modify(sprintf('%+d days', $offset))
                ->format('Y-m-d');
        }

        $monthDay = (string) ($rule['month_day'] ?? '');
        if (preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/D', $monthDay) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%s', $year, $monthDay));

        // 29. 2. v nepřestupném roce: PHP by ho posunulo na 1. 3., což by svátek
        // přesunul na jiný den. Takový rok pravidlo prostě nemá.
        return $date instanceof \DateTimeImmutable && $date->format('m-d') === $monthDay
            ? $date->format('Y-m-d')
            : null;
    }

    /** @return array<string,array{code:string,name:string}> */
    private static function fallbackHolidays(int $year): array
    {
        self::$fallbackUsed = true;
        self::reportFallback('číselník svátků `public_holidays` je prázdný nebo nedostupný');

        $holidays = [];
        foreach (self::FALLBACK_FIXED_HOLIDAYS as $monthDay => $holiday) {
            $holidays[sprintf('%04d-%s', $year, $monthDay)] = $holiday;
        }
        $easter = self::easterSunday($year);
        foreach (self::FALLBACK_EASTER_HOLIDAYS as $holiday) {
            $date = $easter->modify(sprintf('%+d days', $holiday['offset']))->format('Y-m-d');
            $holidays[$date] = ['code' => $holiday['code'], 'name' => $holiday['name']];
        }

        return $holidays;
    }

    /**
     * Jednou za běh do error logu. Opakovat u každého dotazu nemá smysl (helper
     * se volá stokrát za request), zamlčet taky ne — lhůty by se počítaly podle
     * stavu zákona v době vydání aplikace a nikdo by o tom nevěděl.
     */
    private static function reportFallback(string $reason): void
    {
        if (self::$fallbackReported) {
            return;
        }
        self::$fallbackReported = true;
        error_log(sprintf(
            '[svatky] %s — lhůty se počítají z pojistky zapečené v kódu (stav z. 245/2000 Sb. '
                . 'k vydání aplikace). Spusť migrace, jinak novela zákona nebude vidět.',
            $reason,
        ));
    }
}
