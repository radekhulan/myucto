<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Daňový optimalizátor (CZ OSVČ) — srovnání režimů a predikce vůči limitům.
 *
 * Stateless, čisté funkce: vstup = profil (pole) + roční příjem + konstanty roku
 * (z {@see TaxConstants} nebo tabulky `tax_constants`). Žádná DB závislost, aby
 * šlo jednotkově testovat a spouštět z CLI.
 *
 * Profil (pole, vše volitelné kromě activity_rate):
 *   activity_rate    int   30|40|60|80  — sazba výdajového paušálu (typ činnosti)
 *   flat_tax_band    string none|band1|band2|band3
 *   is_vat_payer     bool  — stav k rozhodnému roku výpočtu (dodá TaxAction /
 *                     DpfoReturnDataProvider přes VatStatusService), ne živá cache dneška
 *   is_secondary     bool  — vedlejší činnost (jiná minima pojistného)
 *   spouse_credit    bool  — splněny podmínky slevy na manželku (příjem <68k & dítě <3)
 *   children_count   int
 *   mortgage_interest float
 *   pension_contrib  float
 *   life_insurance   float
 *   donations        float
 */
final class TaxOptimizer
{
    private const BAND_ORDER = ['band1', 'band2', 'band3'];

    /**
     * Hlavní vstup pro retrospektivu: srovná dostupné režimy za uzavřený rok.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c konstanty roku
     * @return array<string,mixed>
     */
    public function compare(array $profile, float $income, array $c): array
    {
        $pausal  = $this->computePausal($profile, $income, $c);
        $regular = $this->computeRegular($profile, $income, $c);

        $candidates = [];
        if ($pausal['applicable']) {
            $candidates['pausal'] = $pausal['total'];
        }
        $candidates['regular'] = $regular['total'];

        asort($candidates);
        $winner = array_key_first($candidates);

        $delta = null;
        if (isset($candidates['pausal'])) {
            $delta = round($regular['total'] - $pausal['total'], 0); // +: paušál levnější
        }

        return [
            'year'    => $c['year'],
            'income'  => round($income, 0),
            'pausal'  => $pausal,
            'regular' => $regular,
            'winner'  => $winner,
            'delta_regular_minus_pausal' => $delta,
        ];
    }

    /**
     * Určí efektivní pásmo paušálu dle příjmu × typu činnosti a deklarovaného pásma.
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function effectiveBand(string $declared, int $activityRate, float $income, array $c): array
    {
        if ($declared === 'none' || !isset($c['pausal_annual'][$declared])) {
            return ['applicable' => false, 'reason' => 'not_in_pausal'];
        }
        if ($income > $c['vat_limit_low']) {
            return ['applicable' => false, 'reason' => 'over_2m', 'declared' => $declared];
        }

        $ceilings = $c['band_ceilings'][$activityRate] ?? $c['band_ceilings'][40];
        $idx = array_search($declared, self::BAND_ORDER, true);
        $surcharge = 0.0;

        // Posun nahoru, dokud příjem překračuje strop aktuálního pásma.
        while ($idx < count(self::BAND_ORDER) - 1 && $income > $ceilings[self::BAND_ORDER[$idx]]) {
            $idx++;
        }
        $effective = self::BAND_ORDER[$idx];

        if ($income > $ceilings[$effective]) {
            // I nejvyšší dostupné pásmo nestačí → mimo paušál (de facto >2M).
            return ['applicable' => false, 'reason' => 'over_2m', 'declared' => $declared];
        }
        if ($effective !== $declared) {
            $surcharge = $c['pausal_annual'][$effective] - $c['pausal_annual'][$declared];
        }

        return [
            'applicable' => true,
            'declared'   => $declared,
            'effective'  => $effective,
            'ceiling'    => $ceilings[$effective],
            'surcharge'  => round($surcharge, 0),
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function computePausal(array $profile, float $income, array $c): array
    {
        $declared = (string) ($profile['flat_tax_band'] ?? 'none');
        $rate = (int) ($profile['activity_rate'] ?? 40);

        if (!empty($profile['is_vat_payer'])) {
            return ['applicable' => false, 'reason' => 'vat_payer', 'total' => null];
        }

        $band = $this->effectiveBand($declared, $rate, $income, $c);
        if (!$band['applicable']) {
            return ['applicable' => false, 'reason' => $band['reason'], 'total' => null];
        }

        $total = (float) $c['pausal_annual'][$band['effective']];
        $schedule = $this->pausalSchedule($band['effective'], $c);

        return [
            'applicable'   => true,
            'effective'    => $band['effective'],
            'declared'     => $band['declared'],
            'surcharge'    => $band['surcharge'],
            // Poslední platná měsíční záloha roku. NENÍ to total/12 — sazba se může
            // změnit uprostřed roku (2026: 9 984 → 9 162 Kč od července), pak by
            // průměr odpovídal částce, která nebyla splatná v žádném měsíci.
            'monthly'      => $schedule['current'],
            'monthly_periods' => $schedule['periods'],
            'rate_change'  => $schedule['change'],
            'total'        => round($total, 0),
            'note'         => $band['effective'] !== $band['declared'] ? 'doplatek_do_vyssiho_pasma' : null,
        ];
    }

    /**
     * Rozpad měsíčních záloh pásma v roce + popis poslední změny sazby.
     *
     * `change` slouží UI: při snížení zálohy uprostřed roku vzniká za už zaplacené
     * měsíce přeplatek, o který lze snížit nejbližší zálohu (§ 38lk ZDP), nebo si
     * o něj po skončení roku požádat. Obojí je dopočitatelné z rozvrhu.
     *
     * @param array<string,mixed> $c
     * @return array{current: int|float, periods: list<array<string,mixed>>, change: ?array<string,mixed>}
     */
    private function pausalSchedule(string $band, array $c): array
    {
        $segments = PausalSchedule::normalize($c['pausal_monthly'] ?? []);
        // Rok bereme z rozvrhu — ten je ukotvený k požadovanému roku, zatímco
        // `year` u fallbacku nese rok převzaté tabulky.
        $year = $segments !== []
            ? (int) substr((string) $segments[0]['from'], 0, 4)
            : (int) ($c['year'] ?? date('Y'));
        $periods = [];
        foreach (PausalSchedule::breakdown($year, $segments) as $p) {
            $periods[] = ['from' => $p['from'], 'to' => $p['to'], 'months' => $p['months'], 'amount' => $p[$band]];
        }
        if ($periods === []) {
            $monthly = round(((float) $c['pausal_annual'][$band]) / 12, 2);
            return ['current' => $monthly, 'periods' => [], 'change' => null];
        }

        $last = $periods[count($periods) - 1];
        $change = null;
        if (count($periods) > 1) {
            $prev = $periods[count($periods) - 2];
            $diff = (float) $prev['amount'] - (float) $last['amount'];
            // Měsíce zaplacené starou (vyšší) sazbou → přeplatek, o který jde snížit
            // nejbližší zálohu. Při zvýšení sazby přeplatek nevzniká (nedoplatek se
            // řeší až vyúčtováním), proto jen kladný rozdíl.
            $monthsAtPrev = 0;
            foreach ($periods as $p) {
                if ($p['from'] < $last['from']) {
                    $monthsAtPrev += (int) $p['months'];
                }
            }
            $overpaid = $diff > 0 ? round($diff * $monthsAtPrev, 0) : 0.0;
            $change = [
                'from'             => $last['from'],
                'previous_monthly' => $prev['amount'],
                'monthly'          => $last['amount'],
                'months_at_previous' => $monthsAtPrev,
                'overpaid'         => $overpaid,
                // Snížená nejbližší záloha; přeplatek větší než záloha se přenáší dál.
                'reduced_advance'  => $overpaid > 0 ? round(max(0.0, (float) $last['amount'] - $overpaid), 0) : null,
            ];
        }

        return ['current' => $last['amount'], 'periods' => $periods, 'change' => $change];
    }

    /**
     * Skutečný režim (§7 OSVČ). Deleguje na sdílené jádro {@see DpfoCalculator::computeSection7}
     * (jeden zdroj pravdy s přiznáním DPFO); chování 1:1 hlídají regresní testy optimalizátoru.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function computeRegular(array $profile, float $income, array $c): array
    {
        return DpfoCalculator::computeSection7($profile, $income, $c);
    }

    /**
     * Predikce běžícího roku: projekce příjmu a měsíc překročení limitů.
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function predict(array $profile, float $ytdIncome, int $monthsElapsed, array $c): array
    {
        $runRate = $monthsElapsed > 0 ? $ytdIncome / $monthsElapsed : 0.0;
        $projected = $runRate * 12;

        $rate = (int) ($profile['activity_rate'] ?? 40);
        $declared = (string) ($profile['flat_tax_band'] ?? 'none');

        // Projekce ZISKU (příjmy − výdaje) pro limit vedlejší činnosti. Výdaje dle
        // profilu: skutečné (roční odhad), nebo výdajový paušál % se stropem — stejně
        // jako computeRegular(). Rozhodná částka se měří proti zisku, ne příjmu.
        $useActual = !empty($profile['use_actual_expenses']);
        $projectedExpenses = $useActual
            ? max(0.0, (float) ($profile['actual_expenses'] ?? 0))
            : min($projected * $rate / 100, (float) ($c['expense_caps'][$rate] ?? PHP_INT_MAX));
        $projectedProfit = max(0.0, $projected - $projectedExpenses);

        // Vedlejší SVČ: rozhodná částka pro povinnou účast na důchodovém pojištění.
        // Měsíc dopočítáme z rovnoměrného tempa zisku (nepotřebuje YTD výdaje).
        $secondarySocial = null;
        if (!empty($profile['is_secondary']) && isset($c['social_secondary_participation_threshold'])) {
            $threshold = (float) $c['social_secondary_participation_threshold'];
            $willCross = $projectedProfit >= $threshold;
            $profitRunRate = $projectedProfit / 12;
            $secondarySocial = [
                'threshold'        => $threshold,
                'projected_profit' => round($projectedProfit, 0),
                'will_cross'       => $willCross,
                'month'            => $willCross && $profitRunRate > 0 ? (int) ceil($threshold / $profitRunRate) : null,
            ];
        }

        $thresholds = [];
        if ($declared !== 'none' && isset($c['band_ceilings'][$rate][$declared])) {
            $thresholds[] = ['key' => 'band_ceiling', 'label' => 'strop pásma ' . $declared, 'value' => (float) $c['band_ceilings'][$rate][$declared]];
        }
        $thresholds[] = ['key' => 'vat_low',  'label' => 'limit DPH / paušálu (' . self::formatMillions((float) $c['vat_limit_low']) . ')', 'value' => (float) $c['vat_limit_low']];
        $thresholds[] = ['key' => 'vat_high', 'label' => 'okamžitý plátce DPH (' . self::formatMillions((float) $c['vat_limit_high']) . ')', 'value' => (float) $c['vat_limit_high']];

        $crossings = [];
        foreach ($thresholds as $t) {
            if ($runRate <= 0) {
                continue;
            }
            $monthReached = ($t['value'] - $ytdIncome) / $runRate + $monthsElapsed;
            $willCross = $projected >= $t['value'];
            $crossings[] = [
                'key'        => $t['key'],
                'label'      => $t['label'],
                'value'      => $t['value'],
                'will_cross' => $willCross,
                'month'      => $willCross ? (int) ceil($monthReached) : null,
            ];
        }

        // Doporučení „odlož fakturu": překročení 2 M nastane pozdě v roce → posun do ledna.
        $defer = null;
        foreach ($crossings as $cr) {
            if ($cr['key'] === 'vat_low' && $cr['will_cross'] && $cr['month'] !== null && $cr['month'] >= 11 && $cr['month'] <= 12) {
                $defer = [
                    'month'   => $cr['month'],
                    'message' => 'Překročení 2 M nastane na konci roku. Posunutím prosincových faktur do ledna zůstaneš pod limitem (neplátce + paušál).',
                ];
            }
        }

        return [
            'year'           => $c['year'],
            'ytd_income'     => round($ytdIncome, 0),
            'months_elapsed' => $monthsElapsed,
            'run_rate'       => round($runRate, 0),
            'projected'      => round($projected, 0),
            'projected_profit' => round($projectedProfit, 0),
            'crossings'      => $crossings,
            'secondary_social' => $secondarySocial,
            'defer_advice'   => $defer,
        ];
    }

    /** Popisek limitu v milionech (Kč) — odvozen ze stejné hodnoty, kterou nese `value`. */
    private static function formatMillions(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value / 1_000_000, 2, ',', ''), '0'), ',');

        return $formatted . ' M';
    }

    /**
     * Orientační měsíční odhad z anualizovaných příjmů a skutečných nákladů.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    public function estimateMonthly(array $profile, float $monthIncome, float $monthExpenses, array $c): array
    {
        $annualIncome = $monthIncome * 12;
        $annualProfile = $profile;
        if (!empty($annualProfile['use_actual_expenses'])) {
            $annualProfile['actual_expenses'] = $monthExpenses * 12;
        }
        $annualProfile['activities'] = $this->projectActivities(
            (array) ($annualProfile['activities'] ?? []),
            $annualIncome,
            $monthExpenses * 12,
        );
        $annual = $this->computeRegular($annualProfile, $annualIncome, $c);

        $incomeTax = round((float) $annual['income_tax'] / 12, 0);
        $social = round((float) $annual['social'] / 12, 0);
        $health = round((float) $annual['health'] / 12, 0);
        $revenue = round($monthIncome, 0);
        $expenses = round($monthExpenses, 0);
        $profit = round($monthIncome - $monthExpenses, 0);

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'profit' => $profit,
            'income_tax' => $incomeTax,
            'social' => $social,
            'health' => $health,
            'net_income' => $profit - $incomeTax - $social - $health,
        ];
    }

    /** @param list<array<string,mixed>> $activities @return list<array<string,mixed>> */
    private function projectActivities(array $activities, float $annualIncome, float $annualExpenses): array
    {
        if ($activities === []) {
            return [];
        }
        $configuredIncome = array_sum(array_map(
            static fn (array $activity): float => max(0.0, (float) ($activity['income'] ?? 0)),
            $activities,
        ));
        if ($configuredIncome <= 0.0) {
            return [];
        }
        $actualExpenseTotal = array_sum(array_map(
            static fn (array $activity): float => ($activity['expense_mode'] ?? 'pausal') === 'actual'
                ? max(0.0, (float) ($activity['expenses'] ?? 0))
                : 0.0,
            $activities,
        ));
        foreach ($activities as &$activity) {
            $incomeShare = max(0.0, (float) ($activity['income'] ?? 0)) / $configuredIncome;
            $activity['income'] = $annualIncome * $incomeShare;
            if (($activity['expense_mode'] ?? 'pausal') === 'actual') {
                $expenseShare = $actualExpenseTotal > 0.0
                    ? max(0.0, (float) ($activity['expenses'] ?? 0)) / $actualExpenseTotal
                    : $incomeShare;
                $activity['expenses'] = $annualExpenses * $expenseShare;
            }
        }
        unset($activity);
        return $activities;
    }

}
