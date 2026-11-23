<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollDuration;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeCompensationMode;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeEvidence;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;

/**
 * Příplatkové vrstvy ze stejné docházky jako tarif. Noc a víkend bez
 * překryvného záznamu odvodí ze směn (přestávka mimo příplatkovou dobu).
 */
final class PayrollHourlyNativePremiums
{
    public static function project(array $entries, array $segments, array $policies, PayrollSurchargeRuleset $rules, string $period, string $end, ?array $calendar): array
    {
        $result = [];
        foreach ($segments as $segment) {
            $row = ['date_from' => $segment['date_from'], 'date_to' => $segment['date_to'],
                'night_duration_units' => null, 'weekend_duration_units' => null, 'holiday_work_duration_units' => null,
                'difficult_exposures' => null, 'holiday_work_days' => [], 'overtime_work_days' => []];
            $matching = array_values(array_filter($policies, static fn(array $p): bool => $p['date_from'] <= $segment['date_from'] && $p['date_to'] >= $segment['date_to']));
            if (count($matching) !== 1) { $result[] = $row; continue; }
            $policy = $matching[0];
            $selected = [];
            foreach ($entries as $entry) {
                [$start] = PayrollHourlyScheduleProof::bounds($entry);
                $date = $start->format('Y-m-d');
                if ($date >= $segment['date_from'] && $date <= $segment['date_to']) $selected[] = $entry;
            }
            try {
                // Pouze sběr faktů: neaplikuje staré rozhodnutí o volnu ani zaokrouhlení částek.
                $factsPolicy = PayrollSurchargePolicy::agreed(PayrollSurchargeCompensationMode::Surcharge, PayrollSurchargeCompensationMode::Surcharge, $policy['difficult_environment_factors'], [], $rules);
                $facts = (new PayrollSurchargeEvidence(new CzechHolidayCalendar()))->collect($selected, [], $factsPolicy, $rules, $period, $end);
                $row['difficult_exposures'] = [];
            } catch (\InvalidArgumentException|\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException) {
                $result[] = $row;
                continue;
            }
            foreach (['night' => 'night_duration_units', 'weekend' => 'weekend_duration_units', 'holiday' => 'holiday_work_duration_units'] as $kind => $key) {
                $units = 0;
                foreach ($facts->segmentsFor(PayrollSurchargeKind::from($kind)) as $fact) {
                    $units = PayrollDuration::add($units, PayrollDuration::fromMinutes($fact->minutes));
                    if ($kind === 'holiday') $row['holiday_work_days'][] = ['date' => $fact->localDate, 'duration_units' => PayrollDuration::fromMinutes($fact->minutes)];
                }
                $range = self::eligibleRange($selected, $kind, $rules, $calendar);
                /*
                 * Noc a víkend bez překryvného záznamu se odvodí ze směn samy.
                 *
                 * Dřív chybějící překryv znamenal „nedoloženo" a směna So 18:00
                 * až Ne 02:30 zastavila výpočet na příplatku za noc. Rozhodnutí
                 * maintainera: dobu odvodit z intervalů běžné práce a přesčasu
                 * podle okna noci (PayrollSurchargeRuleset) a víkendu (So/Ne
                 * v místním čase). Přestávka s neznámou polohou se klade MIMO
                 * příplatkovou dobu, bere se tedy horní mez min(hrubá doba
                 * v okně, čistá doba záznamu). Je to hodnota ve prospěch
                 * zaměstnance; zákon stanoví minimum, vyšší plnění je bezpečné.
                 * Existující překryvný záznam dál výslovně dokládá polohu
                 * přestávky a platí přednostně (ruční přebití). Svátek se
                 * neodvozuje: tam rozhoduje i volba náhradního volna.
                 */
                if ($kind !== 'holiday' && $range !== null && !self::hasOverlay($selected, $kind)) {
                    $row[$key] = $range[1];
                    if ($range[1] > 0) $row[$kind . '_derived_from_shifts'] = true;
                    continue;
                }
                // Chybějící flag svátku není potvrzená nula. Existující překryvná
                // vrstva naopak výslovně dokládá polohu přestávky.
                if ($range !== null && $units >= $range[0] && $units <= $range[1] && ($units > 0 || $range[1] === 0)) $row[$key] = $units;
            }
            foreach ($facts->segmentsFor(PayrollSurchargeKind::DifficultEnvironment) as $fact) $row['difficult_exposures'][] = ['duration_units' => PayrollDuration::fromMinutes($fact->minutes), 'factors' => $fact->factors];
            foreach ($facts->segmentsFor(PayrollSurchargeKind::Overtime) as $fact) $row['overtime_work_days'][] = ['date' => $fact->localDate, 'duration_units' => PayrollDuration::fromMinutes($fact->minutes)];
            if ($facts->requiresManualReview()) $row['difficult_exposures'] = null;
            $result[] = $row;
        }
        return $result;
    }

    /** Má úsek aspoň jeden živý překryvný záznam daného druhu? */
    private static function hasOverlay(array $entries, string $kind): bool
    {
        foreach ($entries as $entry) {
            if (($entry['category'] ?? null) === $kind && ($entry['status'] ?? null) !== 'superseded') return true;
        }
        return false;
    }

    /** Dolní/horní mez; skutečnou část přestávky bez důkazu nepřidělujeme. */
    private static function eligibleRange(array $entries, string $kind, PayrollSurchargeRuleset $rules, ?array $calendar): ?array
    {
        if ($kind === 'holiday' && $calendar === null) return null;
        $minimum = $maximum = 0;
        foreach ($entries as $entry) {
            if (!in_array($entry['category'], ['regular', 'overtime'], true)) continue;
            [$start, $end, $net] = PayrollHourlyScheduleProof::bounds($entry);
            $grossEligible = 0;
            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                $windows = [];
                if ($kind === 'night') {
                    $from = $rules->nightWindowStartHour();
                    $to = $rules->nightWindowEndHour();
                    $windows = $from > $to ? [[$day, $day->setTime($to, 0)], [$day->setTime($from, 0), $day->modify('+1 day')]] : [[$day->setTime($from, 0), $day->setTime($to, 0)]];
                } elseif (($kind === 'weekend' && (int) $day->format('N') >= 6) || ($kind === 'holiday' && in_array($day->format('Y-m-d'), $calendar['dates'], true))) $windows = [[$day, $day->modify('+1 day')]];
                foreach ($windows as [$a, $b]) $grossEligible += intdiv(max(0, min($end->getTimestamp(), $b->getTimestamp()) - max($start->getTimestamp(), $a->getTimestamp())), 60);
            }
            $minimum = PayrollDuration::add($minimum, PayrollDuration::fromMinutes(max(0, $grossEligible - (int) $entry['break_minutes'])));
            $maximum = PayrollDuration::add($maximum, PayrollDuration::fromMinutes(min($grossEligible, $net)));
        }
        return [$minimum, $maximum];
    }
}
