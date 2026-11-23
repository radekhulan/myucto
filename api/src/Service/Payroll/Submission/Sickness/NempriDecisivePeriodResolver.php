<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Tax\TaxConstants;

/**
 * Rozhodné období do NEMPRI (`CtRozhodneObdobi`).
 *
 * ## Věta nese období vždy úplné
 *
 * Datová věta NEMPRI25 vede rozhodné období u NEM, VPM, OPP, PPM a u OSE a DLO
 * s akcí vznik jako „povinné vždy" a logické kontroly ČSSZ (č. 7, 8, 16) chtějí
 * buď úplný seznam měsíců se součty, nebo pravděpodobnou výši příjmu — nikdy
 * obojí a nikdy část. Výjimku pro měsíce, které už pokrylo jednotné měsíční
 * hlášení, norma nezná. Dřív se takové měsíce vynechávaly a od 1/2027 by
 * rozhodné období z věty zmizelo úplně.
 *
 * Měsíc se bere v tomto pořadí:
 *
 *  1. ruční doplnění u případu,
 *  2. schválený mzdový běh MyÚčta ({@see NempriPayrollMonthReader}) — tentýž
 *     zdroj jako měsíční hlášení,
 *  3. převzaté mzdy předchozího programu.
 *
 * Chybí-li měsíc ve všech, podání se zastaví s výčtem měsíců. Tichá nula by
 * u ČSSZ snížila denní vyměřovací základ.
 *
 * ## Hranice (§ 18 a § 19 zák. č. 187/2006 Sb.)
 *
 *  - § 18 odst. 3: 12 kalendářních měsíců před měsícem rozhodného dne,
 *  - § 18 odst. 4: začalo-li pojištění později, od jeho začátku,
 *  - § 18 odst. 5: rozhodný den v měsíci vzniku pojištění → pravděpodobný
 *    příjem,
 *  - § 18 odst. 6: období podle odst. 3 bez vyměřovacího základu nebo
 *    s méně než 30 nevyloučenými dny → první předchozí kalendářní rok se
 *    započitatelným příjmem a aspoň 30 dny (hledá se od roku před rokem
 *    rozhodného dne, nejdřív od vzniku pojištění),
 *  - § 19 odst. 7: totéž u kratšího období podle odst. 4 → pravděpodobný
 *    příjem; § 19 odst. 8: § 18 odst. 6 nenajde žádný rok → pravděpodobný
 *    příjem,
 *  - § 19 odst. 11: událost v ochranné lhůtě → rozhodný den je den po
 *    skončení pojištění ({@see decisiveDate()}),
 *  - § 19 odst. 6: převedená těhotná, matka nebo kojící zaměstnankyně →
 *    období ke dni převedení, je-li výhodnější. Výhodnější NEurčuje
 *    zaměstnavatel: Všeobecné zásady NEMPRI chtějí současně DRUHÉ oznámení
 *    s obdobím ke dni převedení a vybere územní správa. První oznámení proto
 *    nese období k rozhodnému dni, druhé ({@see NempriTransferNotice}) se
 *    zjišťuje tímtéž výpočtem s rozhodným dnem = den převedení.
 *
 * ## Částky
 *
 * NEMPRI přijímá jen celé Kč (upozornění ČSSZ k NEMPRI25). Každý měsíc se
 * zaokrouhlí na celé koruny NAHORU — stejně jako se zaokrouhluje vyměřovací
 * základ pojistného, takže měsíc z mzdového běhu i z hlášení zůstane beze
 * změny a haléře z ručního doplnění dávku nesníží. Součet se počítá ze
 * zaokrouhlených měsíců.
 */
final class NempriDecisivePeriodResolver
{
    /** Druhy převedení, u kterých § 19 odst. 6 dovoluje rozhodné období ke dni převedení. */
    public const TRANSFER_REASONS = ['pregnancy', 'maternity', 'breastfeeding'];

    /** § 18 odst. 6 a § 19 odst. 7: méně nevyloučených dnů nestačí. */
    private const MINIMUM_DAYS = 30;

    /**
     * @param ?\Closure(int):?int $annualMaximumMinor roční maximální vyměřovací
     *        základ v haléřích; výchozí z daňových konstant roku
     */
    public function __construct(private ?\Closure $annualMaximumMinor = null) {}

    /**
     * @param string $decisiveDate den, ke kterému se období zjišťuje: den
     *        vzniku sociální události upravený {@see decisiveDate()}, u druhého
     *        oznámení podle § 19 odst. 6 den převedení na jinou práci
     */
    public function resolve(
        string $decisiveDate,
        string $employmentStart,
        ?string $payrollStartPeriod,
        NempriDecisiveSources $sources,
        ?int $probableIncomeCzk,
    ): NempriDecisivePeriod {
        return $this->resolveFor($decisiveDate, $employmentStart, $payrollStartPeriod, $sources, $probableIncomeCzk);
    }

    /**
     * Rozhodný den podle § 19 odst. 11: vznikla-li sociální událost po skončení
     * zaměstnání (v ochranné lhůtě), zjišťuje se období, jako by vznikla den po
     * skončení pojištění.
     */
    public static function decisiveDate(string $incapacityFrom, ?string $employmentEnd): string
    {
        if ($employmentEnd === null || $employmentEnd === '' || $incapacityFrom <= $employmentEnd) {
            return $incapacityFrom;
        }

        return (new \DateTimeImmutable($employmentEnd))->modify('+1 day')->format('Y-m-d');
    }

    /**
     * Hranice rozhodného období podle § 18 odst. 3 a 4.
     *
     * Vrácené `od` může být po `do` — to je rozhodný den v prvním měsíci
     * zaměstnání (§ 18 odst. 5), kdy rozhodné období nevzniklo.
     *
     * @return array{0:string,1:string}
     */
    public static function bounds(string $decisiveDate, string $employmentStart): array
    {
        $eventMonth = (new \DateTimeImmutable($decisiveDate))->modify('first day of this month');
        $from = $eventMonth->modify('-12 months')->format('Y-m-d');
        $to = $eventMonth->modify('-1 day')->format('Y-m-d');

        return [max($from, $employmentStart), $to];
    }

    private function resolveFor(
        string $decisiveDate,
        string $employmentStart,
        ?string $payrollStartPeriod,
        NempriDecisiveSources $sources,
        ?int $probableIncomeCzk,
    ): NempriDecisivePeriod {
        [$from, $to] = self::bounds($decisiveDate, $employmentStart);
        if ($from > $to) {
            // § 18 odst. 5: rozhodný den v měsíci vzniku pojištění, rozhodné
            // období se neurčuje. Věta ho přesto nese povinně, a tak podle
            // Všeobecných zásad NEMPRI od = den nástupu do zaměstnání a do = den
            // předcházející vzniku sociální události. Vznikla-li událost přímo
            // v den nástupu, zůstane do = den nástupu (do < od by věta neprošla
            // kontrolou 15).
            $dayBefore = (new \DateTimeImmutable($decisiveDate))->modify('-1 day')->format('Y-m-d');

            return self::probable($employmentStart, max($employmentStart, $dayBefore), $probableIncomeCzk, '§ 18 odst. 5');
        }
        if (self::inclusiveDays($from, $to) < self::MINIMUM_DAYS) {
            // Období podle § 18 odst. 4 kratší než 30 kalendářních dnů: na
            // jednotlivých měsících nezáleží, 30 nevyloučených dnů mít nemůže.
            return self::probable($from, $to, $probableIncomeCzk, '§ 19 odst. 7');
        }
        $period = $this->collect($from, $to, $payrollStartPeriod, $sources);
        if (self::sufficient($period)) {
            return $period;
        }
        $fullPeriod = $from === (new \DateTimeImmutable($decisiveDate))
            ->modify('first day of this month')->modify('-12 months')->format('Y-m-d');
        if (!$fullPeriod) {
            return self::probable($from, $to, $probableIncomeCzk, '§ 19 odst. 7');
        }
        $startYear = (int) substr($employmentStart, 0, 4);
        for ($year = (int) substr($decisiveDate, 0, 4) - 1; $year >= $startYear; $year--) {
            $yearFrom = max(sprintf('%04d-01-01', $year), $employmentStart);
            $candidate = $this->collect($yearFrom, sprintf('%04d-12-31', $year), $payrollStartPeriod, $sources);
            if (self::sufficient($candidate)) {
                return $candidate;
            }
        }

        return self::probable($from, $to, $probableIncomeCzk, '§ 19 odst. 8');
    }

    private static function sufficient(NempriDecisivePeriod $period): bool
    {
        return $period->incomeMinor() > 0
            && self::inclusiveDays($period->from, $period->to) - $period->excludedDays() >= self::MINIMUM_DAYS;
    }

    private static function probable(string $from, string $to, ?int $probableIncomeCzk, string $paragraph): NempriDecisivePeriod
    {
        if ($probableIncomeCzk === null) {
            throw new SicknessException(
                'nempri_probable_income_missing',
                "Rozhodné období nemá vyměřovací základ nebo aspoň 30 nevyloučených dnů, takže ÚSSZ "
                . "vychází z pravděpodobné výše příjmu ({$paragraph} zákona č. 187/2006 Sb.). Zadejte ji "
                . 'u případu — návrh je měsíční hrubá mzda ze smlouvy, u dohody a malého rozsahu '
                . 'příjem v měsíci události.',
            );
        }

        return new NempriDecisivePeriod($from, $to, [], false, $probableIncomeCzk);
    }

    private function collect(
        string $from,
        string $to,
        ?string $payrollStartPeriod,
        NempriDecisiveSources $sources,
    ): NempriDecisivePeriod {
        $months = [];
        $missingTakeover = [];
        $missingPayroll = [];
        $unknownExcluded = [];
        $capped = [];
        foreach (self::periodsBetween($from, $to) as $period) {
            [$year, $month] = array_map('intval', explode('-', $period));
            $spanDays = self::inclusiveDays(
                max($from, $period . '-01'),
                min($to, (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d')),
            );
            $manual = $sources->manual($period);
            if ($manual !== null) {
                $months[] = new NempriDecisiveMonth(
                    $year,
                    $month,
                    self::wholeCzk($manual['income_minor']),
                    min($spanDays, $manual['excluded_days']),
                    NempriDecisiveMonth::SOURCE_MANUAL,
                );
                continue;
            }
            $payroll = $sources->payroll($period);
            if ($payroll !== null) {
                if ($payroll['income_minor'] === null) {
                    $missingPayroll[] = $period;
                    continue;
                }
                if ($payroll['excluded_days'] === null) {
                    $unknownExcluded[] = $period;
                    continue;
                }
                $months[] = new NempriDecisiveMonth(
                    $year,
                    $month,
                    self::wholeCzk($payroll['income_minor']),
                    min($spanDays, $payroll['excluded_days']),
                    NempriDecisiveMonth::SOURCE_PAYROLL,
                );
                continue;
            }
            $rows = $sources->takeover($period);
            if ($rows === []) {
                if (PayrollHistoricalPeriodService::precedesStart($payrollStartPeriod, $period)) {
                    $missingTakeover[] = $period;
                } else {
                    $missingPayroll[] = $period;
                }
                continue;
            }
            $income = 0;
            foreach ($rows as $row) {
                $income += self::takeoverIncome($row);
            }
            $excluded = self::takeoverExcludedDays($rows, $income);
            if ($excluded === null) {
                $unknownExcluded[] = $period;
                continue;
            }
            $excluded = min($spanDays, $excluded);
            foreach ($rows as $row) {
                if ($this->cappedTakeoverMonth($row, $sources, $excluded, $spanDays)) {
                    $capped[] = $period;
                    continue 2;
                }
            }
            $months[] = new NempriDecisiveMonth(
                $year,
                $month,
                self::wholeCzk($income),
                $excluded,
                NempriDecisiveMonth::SOURCE_TAKEOVER,
            );
        }
        if ($missingTakeover !== [] || $missingPayroll !== []) {
            $parts = [];
            if ($missingPayroll !== []) {
                $parts[] = 'Mzdu za ' . implode(', ', $missingPayroll) . ' počítá MyÚčto, ale schválený '
                    . 'mzdový běh s vyměřovacím základem tohoto vztahu k nim není. Mzdu za ně spočítejte '
                    . 'a schvalte.';
            }
            if ($missingTakeover !== []) {
                $parts[] = 'Za ' . implode(', ', $missingTakeover) . ' chybí převzatá mzda z předchozího '
                    . 'programu. Doplňte ji v Kontrole převodu mezd.';
            }
            throw new SicknessException(
                'nempri_decisive_month_missing',
                'Rozhodné období ' . $from . ' – ' . $to . ' musí ve větě nést všechny měsíce. '
                . implode(' ', $parts)
                . ' Měsíce lze také zadat u případu ručně (započitatelný příjem a vyloučené dny).',
                // Měsíc, který počítá MyÚčto, se opravuje mzdovým během, převzatý
                // v Kontrole převodu mezd — obrazovka podle toho nabídne proklik.
                ['payroll_periods' => $missingPayroll, 'takeover_periods' => $missingTakeover],
            );
        }
        if ($unknownExcluded !== []) {
            throw new SicknessException(
                'nempri_decisive_excluded_days_unknown',
                'U měsíců ' . implode(', ', $unknownExcluded) . ' nejde zjistit vyloučené dny podle '
                . '§ 18 odst. 7 zákona č. 187/2006 Sb. (převzaté podklady je nenesou, nebo je '
                . 'z nepřítomností schváleného běhu nejde odvodit). Nula by snížila denní vyměřovací '
                . 'základ. Zadejte tyto měsíce u případu ručně, případně u nemoci znovu schvalte mzdový běh.',
            );
        }
        if ($capped !== []) {
            throw new SicknessException(
                'nempri_capped_base_unsupported',
                'Převzatý vyměřovací základ za ' . implode(', ', $capped) . ' je krácený ročním '
                . 'maximálním vyměřovacím základem (nebo chybí, ačkoli byl zúčtován příjem). Do '
                . 'započitatelného příjmu patří i část nad maximem (§ 18 odst. 2 zákona č. 187/2006 Sb.), '
                . 'kterou převzaté hlášení nenese. Zadejte tyto měsíce u případu ručně.',
            );
        }

        return new NempriDecisivePeriod($from, $to, $months, true, null);
    }

    /**
     * Příjem převzatého měsíce: vyměřovací základ (10477), a když je vyšší,
     * příjem včetně nepojištěné činnosti (10476, § 19 odst. 9). Nesčítá se —
     * u běžného pracovního poměru jsou obě čísla stejná a součet by měsíc
     * zdvojil.
     */
    private static function takeoverIncome(PayrollTakeoverMonth $row): int
    {
        return max($row->socialBaseMinor, $row->uninsuredIncomeMinor ?? 0);
    }

    /**
     * Vyloučené dny převzatého měsíce podle § 18 odst. 7.
     *
     * Když zdroj veličinu nevydal a měsíc má příjem, zůstává vyloučenou dobou
     * převzatý úhrn § 16 odst. 4 — tak se měsíc posílal i dřív a zdroje bez
     * rozpadu (PAMICA, POHODA, PREMIER, tabulkový import) jiný údaj nemají.
     * Měsíc BEZ příjmu s neznámou hodnotou se zastaví: tam rozhoduje právě
     * počet vyloučených dnů (neplacené volno) a tichá nula by denní vyměřovací
     * základ podhodnotila.
     *
     * @param list<PayrollTakeoverMonth> $rows
     */
    private static function takeoverExcludedDays(array $rows, int $income): ?int
    {
        $known = 0;
        $legacy = 0;
        $unknown = false;
        foreach ($rows as $row) {
            $legacy = max($legacy, $row->excludedDays);
            if ($row->sicknessExcludedDays === null) {
                $unknown = true;
                continue;
            }
            $known = max($known, $row->sicknessExcludedDays);
        }
        if (!$unknown) {
            return $known;
        }

        return $income > 0 ? max($known, $legacy) : null;
    }

    /**
     * § 18 odst. 2 věta třetí: do započitatelného příjmu patří i vyměřovací
     * základ nad ročním maximem. Převzaté hlášení nese jen zastropovaný 10477,
     * takže měsíc po dosažení maxima by šel do věty s nulou nebo zkrácený.
     * Nahradit ho hrubou mzdou by byl vymyšlený údaj; měsíc se proto zastaví.
     *
     * Krácení se pozná dvojím způsobem: součet převzatých základů roku do
     * tohoto měsíce dosáhl maxima, nebo účastný měsíc má zúčtovaný příjem,
     * ale nulový základ a nevysvětlují to vyloučené dny.
     */
    private function cappedTakeoverMonth(
        PayrollTakeoverMonth $row,
        NempriDecisiveSources $sources,
        int $excludedDays,
        int $spanDays,
    ): bool {
        if ($row->pensionParticipation
            && $row->grossMinor > 0
            && $row->socialBaseMinor === 0
            && ($row->uninsuredIncomeMinor ?? 0) === 0
            && $excludedDays < $spanDays
        ) {
            return true;
        }
        $maximum = $this->annualMaximum((int) substr($row->period, 0, 4));
        if ($maximum === null) {
            return false;
        }
        $cumulative = 0;
        foreach ($sources->takeoverYear((int) substr($row->period, 0, 4)) as $month) {
            if ($month->period <= $row->period) {
                $cumulative += $month->socialBaseMinor;
            }
        }

        return $cumulative >= $maximum;
    }

    private function annualMaximum(int $year): ?int
    {
        if ($this->annualMaximumMinor !== null) {
            return ($this->annualMaximumMinor)($year);
        }
        try {
            $maximum = TaxConstants::forYear($year)['social_max_base'] ?? null;
        } catch (\OutOfRangeException) {
            return null;
        }

        return is_numeric($maximum) ? (int) round((float) $maximum * 100) : null;
    }

    /** Haléře na celé koruny nahoru (NEMPRI přijímá jen celé Kč). */
    private static function wholeCzk(int $minor): int
    {
        return $minor <= 0 ? 0 : intdiv($minor + 99, 100) * 100;
    }

    /** @return list<string> `YYYY-MM` od měsíce `$from` do měsíce `$to` */
    private static function periodsBetween(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        $periods = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = substr($to, 0, 7);
        while ($cursor->format('Y-m') <= $last) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $periods;
    }

    private static function inclusiveDays(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))
            ->diff(new \DateTimeImmutable($to))->days + 1;
    }
}
