<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Provider je povinná závislost: jako volitelný class-param by ho PHP-DI
 * nevyplnil a kalkulátor by za běhu tiše četl default z kódu místo rulesetu
 * účinného podle administrace.
 */
final class SicknessCompensationCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int,average_hourly_minor?:int}> $segments
     */
    public function calculate(
        string $date,
        int $averageHourlyMinor,
        array $segments,
        ?SicknessCompensationReduction $reduction = null,
    ): SicknessCompensationResult {
        $reduction ??= SicknessCompensationReduction::none();
        if ($averageHourlyMinor <= 0) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje kladný hodinový průměr.');
        }
        if ($segments === []) {
            throw new InvalidArgumentException('DPN náhrada vyžaduje alespoň jednu publikovanou směnu.');
        }

        $rules = AbsenceRuleset::forDate($this->rulesets, $date);
        $boundary1 = $rules->hourlyBoundaryMinor(1);
        $boundary2 = $rules->hourlyBoundaryMinor(2);
        $boundary3 = $rules->hourlyBoundaryMinor(3);
        $bandRate1 = $rules->reductionBandBasisPoints(1);
        $bandRate2 = $rules->reductionBandBasisPoints(2);
        $bandRate3 = $rules->reductionBandBasisPoints(3);
        $compensationRate = $rules->compensationRateBasisPoints();

        $reducedNumerator = static fn (int $average): int
            => min($average, $boundary1) * $bandRate1
            + min(max($average - $boundary1, 0), $boundary2 - $boundary1) * $bandRate2
            + min(max($average - $boundary2, 0), $boundary3 - $boundary2) * $bandRate3;

        // ─────────────────────────────────────────────────────────────────────
        // Zaokrouhlení náhrady — co zákon říká a co neříká
        // ─────────────────────────────────────────────────────────────────────
        // § 192 odst. 2 ZP zaokrouhluje na haléře nahoru VÝHRADNĚ redukční
        // hranice („…vynásobí koeficientem 0,175 a poté zaokrouhlí na haléře
        // směrem nahoru"). O zaokrouhlení redukovaného průměrného hodinového
        // výdělku ani výsledné náhrady § 192 ani § 193 neříkají NIC — věta
        // „náhrada mzdy se zaokrouhluje na celé koruny nahoru" v zákoníku práce
        // neexistuje.
        //
        // Zaokrouhlení výsledku plyne odjinud: § 142 odst. 2 („Mzda nebo plat se
        // zaokrouhlují na celé koruny směrem nahoru.") se na náhradu mzdy
        // vztahuje přes § 144, který pro náhradu mzdy nebo platu přikazuje
        // použít § 141 až 143 obdobně. Zaokrouhluje se tedy až VÝSLEDNÁ náhrada
        // při zúčtování, a NAHORU.
        //
        // Z toho plyne postup, který tenhle kalkulátor drží:
        //   1. redukovaný hodinový výdělek se v mezikroku NEZAOKROUHLUJE —
        //      počítá se přesným zlomkem přes všechny směny (pro zákon
        //      neexistující mezikrok nesmí ukrojit haléře; MPSV doporučuje
        //      minimálně 4 desetinná místa, přesný zlomek je víc),
        //   2. zaokrouhluje se AŽ ÚHRN, a to za KALENDÁŘNÍ MĚSÍC, na celé
        //      koruny nahoru — měsíc je výplatní období, o kterém § 142 odst. 2
        //      mluví,
        //   3. zaokrouhlená měsíční částka se rozpustí zpátky do směn metodou
        //      největšího zbytku, takže SOUČET SEGMENTŮ dá přesně měsíční
        //      částku. Není to kosmetika: do mzdy vstupuje `SUM` segmentů
        //      seskupených po měsících (PayrollSicknessInputMaterializer), ne
        //      pole `compensation_minor`. Kdyby se segmenty a úhrn rozešly,
        //      vyplatila by se jiná částka, než jakou modul vykázal.
        //
        // Dřív se zaokrouhlovalo HalfUp na haléře, a to PER SMĚNU. Tím vznikal
        // systematický nedoplatek (matematické zaokrouhlení dolů tam, kde zákon
        // žádá nahoru) a haléřová částka, kterou nelze vyplatit.
        //
        // K OVĚŘENÍ (vědomá odchylka): kalkulátor počítá JEDNU neschopnost.
        // Má-li zaměstnanec v jednom měsíci dvě, zaokrouhlí se dvakrát a náhrada
        // je až o 1 Kč vyšší, než kdyby se úhrn sečetl nejdřív. Odchylka jde ve
        // prospěch zaměstnance; sečíst obojí by znamenalo přesunout zaokrouhlení
        // až do sestavení mzdy, což je samostatný zásah.
        $reducedHourlyNumerator = $reducedNumerator($averageHourlyMinor);
        // Jen do stopy výpočtu a do UI — na aritmetice se nepodílí.
        $reducedHourlyMinor = RoundingMode::Ceil->roundFraction(
            $reducedHourlyNumerator,
            10_000,
        );

        $exactDenominator = 10_000 * 10_000 * 60;
        $calculatedSegments = [];
        $numeratorByPeriod = [];
        $minutesByPeriod = [];
        foreach ($segments as $index => $segment) {
            $planned = $segment['planned_minutes'];
            $eligible = $segment['eligible_minutes'];
            if ($planned <= 0 || $eligible <= 0 || $eligible > $planned) {
                throw new InvalidArgumentException('Minuty DPN směny nejsou platné.');
            }
            $period = substr((string) $segment['local_date'], 0, 7);
            // § 354 odst. 1 ZP: průměr platí pro čtvrtletí, do kterého den patří. Volající
            // dává směnám v dalším čtvrtletí jeho průměr; v rámci měsíce je vždy jeden.
            $segmentAverage = (int) ($segment['average_hourly_minor'] ?? $averageHourlyMinor);
            if ($segmentAverage <= 0) {
                throw new InvalidArgumentException('DPN náhrada vyžaduje kladný hodinový průměr.');
            }
            // Váha směny je přímo počet započitatelných minut: čitatel přesného
            // podílu je `reducedHourlyNumerator * compensationRate * eligible`,
            // tedy konstanta krát minuty. Rozdělovat podle minut je proto
            // matematicky totožné a nepřeteče (čitatele jdou do 10^15 a násobek
            // s částkou by přetekl i 64bitový int).
            $minutesByPeriod[$period][$index] = $eligible;
            $numeratorByPeriod[$period][$index] = $reducedNumerator($segmentAverage)
                * $compensationRate * $eligible;
            $calculatedSegments[$index] = [
                'shift_id' => $segment['shift_id'],
                'local_date' => $segment['local_date'],
                'planned_minutes' => $planned,
                'eligible_minutes' => $eligible,
                'hourly_average_minor' => $segmentAverage,
                'reduced_hourly_minor' => RoundingMode::Ceil->roundFraction($reducedNumerator($segmentAverage), 10_000),
                'compensation_minor' => 0,
                'rounding' => 'ceil-to-czk-on-period-total',
            ];
        }

        // § 192 odst. 4 a 5 ZP: podíl se uplatní na přesný čitatel měsíce, teprve
        // pak se zaokrouhluje na celé koruny nahoru. Polovina zaokrouhlené náhrady by
        // se lišila až o korunu a nebyla by „polovinou náhrady", ale polovinou čísla,
        // které už zaokrouhlení posunulo.
        $keptBasisPoints = $reduction->keptBasisPoints();
        $fullByPeriod = [];
        $totalByPeriod = [];
        foreach ($numeratorByPeriod as $period => $periodNumerators) {
            // § 142 odst. 2 ve spojení s § 144 ZP: na celé koruny nahoru.
            $exact = array_sum($periodNumerators);
            $fullByPeriod[$period] = RoundingMode::Ceil->roundFraction($exact, $exactDenominator * 100) * 100;
            $totalByPeriod[$period] = $keptBasisPoints === null || $keptBasisPoints === 10_000
                ? $fullByPeriod[$period]
                : self::ceilShareToCzk($exact, $exactDenominator * 100, $keptBasisPoints) * 100;
        }
        if ($reduction->amountMinor !== null) {
            /*
             * Snížení pevnou částkou se nedá poctivě rozdělit do více výplatních
             * období: zákon neříká, který měsíc má snížení nést, a jakékoli dělení
             * by byl vymyšlený údaj. U náhrady přes konec měsíce se proto snižuje
             * podílem.
             */
            if (count($totalByPeriod) !== 1) {
                throw new InvalidArgumentException(
                    'Snížení náhrady pevnou částkou jde jen u náhrady v jednom kalendářním měsíci. '
                    . 'Náhrada této neschopnosti padá do více měsíců; zadejte snížení procentem.',
                );
            }
            $period = array_key_first($totalByPeriod);
            if ($reduction->amountMinor > $totalByPeriod[$period]) {
                throw new InvalidArgumentException(sprintf(
                    'Snížení náhrady (%s Kč) je vyšší než celá náhrada (%s Kč). '
                    . 'Má-li se náhrada neposkytnout vůbec, zadejte snížení 100 %%.',
                    number_format($reduction->amountMinor / 100, 2, ',', ' '),
                    number_format($totalByPeriod[$period] / 100, 2, ',', ' '),
                ));
            }
            $totalByPeriod[$period] -= $reduction->amountMinor;
        }

        $totalMinor = 0;
        foreach ($totalByPeriod as $period => $periodTotal) {
            $totalMinor += $periodTotal;
            foreach ($this->distribute($periodTotal, $minutesByPeriod[$period]) as $index => $amount) {
                $calculatedSegments[$index]['compensation_minor'] = $amount;
            }
        }
        $calculatedSegments = array_values($calculatedSegments);

        return new SicknessCompensationResult(
            $reducedHourlyMinor,
            $totalMinor,
            'manual_review',
            $rules->version->id,
            $rules->version->canonicalHash,
            $calculatedSegments,
            [
                'average_hourly_minor' => $averageHourlyMinor,
                'hourly_boundary_1_minor' => $boundary1,
                'hourly_boundary_2_minor' => $boundary2,
                'hourly_boundary_3_minor' => $boundary3,
                'band_1_basis_points' => $bandRate1,
                'band_2_basis_points' => $bandRate2,
                'band_3_basis_points' => $bandRate3,
                'compensation_basis_points' => $compensationRate,
                'window_calendar_days' => $rules->sicknessWindowCalendarDays(),
                'segment_count' => count($calculatedSegments),
                'compensation_minor' => $totalMinor,
                'compensation_rounding' => 'ceil-to-czk-on-period-total',
                'compensation_rounding_basis' => 'zp-142-2-via-144',
                'support_status' => 'manual_review',
                ...($reduction->isNone() ? [] : [
                    'compensation_before_reduction_minor' => array_sum($fullByPeriod),
                    ...$reduction->trace(),
                ]),
            ],
            $reduction,
        );
    }

    /**
     * Výpočet, ze kterého náhrada nevzniká, ale okno § 192 ZP ano.
     *
     * Evidenční list, měsíční hlášení i krácení mzdy čtou okno ze schváleného výpočtu,
     * takže ho potřebují i tam, kde zaměstnavatel nic neplatí: navazující část
     * neschopnosti za vyčerpaným oknem, neschopnost bez nároku na nemocenské
     * (§ 15a zák. č. 187/2006 Sb.) a neschopnost mimo trvání pracovního vztahu.
     */
    public function withoutCompensation(string $date, string $reason): SicknessCompensationResult
    {
        $rules = AbsenceRuleset::forDate($this->rulesets, $date);

        return new SicknessCompensationResult(
            0,
            0,
            'manual_review',
            $rules->version->id,
            $rules->version->canonicalHash,
            [],
            [
                'window_calendar_days' => $rules->sicknessWindowCalendarDays(),
                'segment_count' => 0,
                'compensation_minor' => 0,
                'no_compensation_reason' => $reason,
                'support_status' => 'manual_review',
            ],
        );
    }

    /**
     * `ceil(exact * keptBasisPoints / (denominator * 10 000))` bez přetečení 64bitového
     * čísla: čitatel měsíce jde do 10^16 a vynásobený podílem by přetekl.
     */
    private static function ceilShareToCzk(int $exact, int $denominator, int $keptBasisPoints): int
    {
        $quotient = intdiv($exact, $denominator);
        $remainder = $exact % $denominator;
        $scaled = $quotient * $keptBasisPoints;
        $whole = intdiv($scaled, 10_000);
        $rest = $scaled % 10_000;

        return $whole + RoundingMode::Ceil->roundFraction(
            $rest * $denominator + $remainder * $keptBasisPoints,
            $denominator * 10_000,
        );
    }

    /**
     * Rozpuštění zaokrouhlené měsíční náhrady zpátky do směn.
     *
     * Metoda největšího zbytku: každá směna dostane svůj podíl zaokrouhlený
     * dolů a zbývající haléře připadnou směnám s největším zbytkem, při shodě
     * té dřívější. Součet je z konstrukce PŘESNĚ `$total` — na tom stojí to, že
     * do mzdy vstupuje `SUM(compensation_minor)` po měsících a musí dát tutéž
     * částku, jakou modul vykázal jako náhradu.
     *
     * @param array<int,int> $weights započitatelné minuty směn
     * @return array<int,int>
     */
    private function distribute(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            throw new InvalidArgumentException('Náhradu DPN nelze rozdělit bez kladného základu.');
        }

        $amounts = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $index => $weight) {
            $exact = $total * $weight;
            $amounts[$index] = intdiv($exact, $sum);
            $remainders[$index] = $exact % $sum;
            $assigned += $amounts[$index];
        }

        $order = array_keys($remainders);
        usort(
            $order,
            static fn (int $left, int $right): int
                => [$remainders[$right], $left] <=> [$remainders[$left], $right],
        );
        for ($i = 0; $assigned < $total; $i++, $assigned++) {
            $amounts[$order[$i % count($order)]]++;
        }

        return $amounts;
    }
}
