<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Juvenile;

use MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile;

/**
 * Kontroly pracovní doby mladistvého nad skutečně odpracovanými záznamy.
 *
 * Mladistvý je zaměstnanec mladší 18 let (§ 350 odst. 2 zákoníku práce), věk
 * se posuzuje ke dni podle {@see OvertimeEmploymentProfile::juvenileOn()}.
 * Den, kdy zaměstnanec dovrší 18 let, už se nekontroluje.
 *
 *  - délka směny v jednotlivém dni nejvýše 8 h a týdně nejvýše 40 h (§ 79a);
 *    záznamy všech pracovněprávních vztahů u zaměstnavatele se sčítají,
 *  - nepřetržitý odpočinek mezi dvěma směnami nejméně 12 h (§ 90 odst. 1),
 *  - zákaz noční práce (§ 245 odst. 1), noční doba podle § 78 odst. 1,
 *  - přestávka na jídlo a oddech nejpozději po 4,5 h práce (§ 88 odst. 1);
 *    posuzuje se jen záznam bez přestávky, protože poloha přestávky uvnitř
 *    záznamu se neeviduje.
 *
 * Do kontroly vstupuje jen běžná práce a přesčas. Noční, víkend, svátek
 * a ztížené prostředí jsou příznaky nad toutéž dobou
 * ({@see \MyInvoice\Service\Payroll\Time\PayrollTimeService} je do skutečnosti
 * také nepočítá), takže by se doba počítala dvakrát.
 *
 * Záznam patří ke dni a týdnu podle místního začátku, stejně jako do měsíce.
 * Směnou dne se pro odpočinek rozumí doba od prvního začátku do posledního
 * konce záznamů téhož dne; dělená směna tak nevypadá jako dvě směny bez
 * odpočinku.
 */
final class JuvenileWorkTimeEvaluator
{
    public const DAILY_LIMIT_MINUTES = 8 * 60;
    public const WEEKLY_LIMIT_MINUTES = 40 * 60;
    public const REST_MINIMUM_MINUTES = 12 * 60;
    public const BREAK_AFTER_MINUTES = 270;

    public function __construct(
        private readonly int $nightStartHour = 22,
        private readonly int $nightEndHour = 6,
    ) {}

    /**
     * @param list<array{starts_at_utc:string,ends_at_utc:string,break_minutes:int,timezone_name:string}> $entries
     *        záznamy osoby ze všech jejích vztahů, i z týdnů přesahujících měsíc
     * @param string $periodStart první den posuzovaného měsíce
     * @return list<JuvenileWorkTimeFinding>
     */
    public function evaluate(array $entries, OvertimeEmploymentProfile $profile, string $periodStart): array
    {
        if ($profile->birthDate === null) {
            return [];
        }
        $monthFirst = substr($periodStart, 0, 7) . '-01';
        $monthLast = (new \DateTimeImmutable($monthFirst))->modify('last day of this month')->format('Y-m-d');
        $inMonth = static fn (string $date): bool => $date >= $monthFirst && $date <= $monthLast;

        $intervals = [];
        foreach ($entries as $entry) {
            $timezone = new \DateTimeZone($entry['timezone_name']);
            $start = (new \DateTimeImmutable($entry['starts_at_utc'], new \DateTimeZone('UTC')))->setTimezone($timezone);
            $end = (new \DateTimeImmutable($entry['ends_at_utc'], new \DateTimeZone('UTC')))->setTimezone($timezone);
            $gross = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
            $intervals[] = [
                'date' => $start->format('Y-m-d'),
                'start' => $start,
                'end' => $end,
                'break' => $entry['break_minutes'],
                'net' => max(0, $gross - $entry['break_minutes']),
            ];
        }
        usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $findings = [];
        $days = [];
        $nightByDay = [];
        foreach ($intervals as $interval) {
            $date = $interval['date'];
            $days[$date] ??= ['net' => 0, 'start' => $interval['start'], 'end' => $interval['end']];
            $days[$date]['net'] += $interval['net'];
            if ($interval['end'] > $days[$date]['end']) {
                $days[$date]['end'] = $interval['end'];
            }
            if (!$inMonth($date) || !$profile->juvenileOn($date)) {
                continue;
            }
            $night = $this->nightMinutes($interval['start'], $interval['end']);
            if ($night > 0) {
                $nightByDay[$date] = ($nightByDay[$date] ?? 0) + $night;
            }
            if ($interval['break'] === 0 && $interval['net'] > self::BREAK_AFTER_MINUTES) {
                $findings[] = new JuvenileWorkTimeFinding(
                    JuvenileWorkTimeFinding::CODE_BREAK,
                    sprintf(
                        '%s: záznam %s až %s trvá %s h bez přestávky. Mladistvému musí zaměstnavatel poskytnout '
                        . 'přestávku na jídlo a oddech nejpozději po 4,5 h nepřetržité práce (§ 88 odst. 1 zákoníku práce).',
                        self::czechDate($date),
                        $interval['start']->format('G:i'),
                        $interval['end']->format('G:i'),
                        self::hours($interval['net']),
                    ),
                    $date,
                    $date,
                    $interval['net'],
                    self::BREAK_AFTER_MINUTES,
                    '§ 88 odst. 1 zákoníku práce',
                );
            }
        }
        ksort($days);

        foreach ($days as $date => $day) {
            if (!$inMonth($date) || !$profile->juvenileOn($date) || $day['net'] <= self::DAILY_LIMIT_MINUTES) {
                continue;
            }
            $findings[] = new JuvenileWorkTimeFinding(
                JuvenileWorkTimeFinding::CODE_DAILY,
                sprintf(
                    '%s: mladistvý odpracoval %s h. Délka směny mladistvého nesmí v jednotlivých dnech přesáhnout '
                    . '8 h, u více pracovněprávních vztahů se doba sčítá (§ 79a zákoníku práce).',
                    self::czechDate($date),
                    self::hours($day['net']),
                ),
                $date,
                $date,
                $day['net'],
                self::DAILY_LIMIT_MINUTES,
                '§ 79a zákoníku práce',
            );
        }

        $weeks = [];
        foreach ($days as $date => $day) {
            if (!$profile->juvenileOn($date)) {
                continue;
            }
            $monday = (new \DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
            $weeks[$monday] = ($weeks[$monday] ?? 0) + $day['net'];
        }
        foreach ($weeks as $monday => $minutes) {
            $sunday = (new \DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');
            if ($minutes <= self::WEEKLY_LIMIT_MINUTES || $sunday < $monthFirst || $monday > $monthLast) {
                continue;
            }
            $findings[] = new JuvenileWorkTimeFinding(
                JuvenileWorkTimeFinding::CODE_WEEKLY,
                sprintf(
                    'Týden %s až %s: mladistvý odpracoval %s h. Týdenní pracovní doba mladistvého nesmí přesáhnout '
                    . '40 h, u více pracovněprávních vztahů se doba sčítá (§ 79a zákoníku práce).',
                    self::czechDate($monday),
                    self::czechDate($sunday),
                    self::hours($minutes),
                ),
                $monday,
                $sunday,
                $minutes,
                self::WEEKLY_LIMIT_MINUTES,
                '§ 79a zákoníku práce',
            );
        }

        $previous = null;
        foreach ($days as $date => $day) {
            if ($previous !== null && $inMonth($date) && $profile->juvenileOn($date)) {
                $rest = max(0, intdiv($day['start']->getTimestamp() - $previous['end']->getTimestamp(), 60));
                if ($rest < self::REST_MINIMUM_MINUTES) {
                    $findings[] = new JuvenileWorkTimeFinding(
                        JuvenileWorkTimeFinding::CODE_REST,
                        sprintf(
                            '%s: mezi koncem předchozí směny (%s %s) a začátkem směny (%s) měl mladistvý odpočinek '
                            . 'jen %s h. Mladistvému musí zaměstnavatel rozvrhnout nepřetržitý odpočinek mezi dvěma '
                            . 'směnami alespoň 12 h během 24 hodin po sobě jdoucích (§ 90 odst. 1 zákoníku práce).',
                            self::czechDate($date),
                            self::czechDate($previous['end']->format('Y-m-d')),
                            $previous['end']->format('G:i'),
                            $day['start']->format('G:i'),
                            self::hours($rest),
                        ),
                        $date,
                        $date,
                        $rest,
                        self::REST_MINIMUM_MINUTES,
                        '§ 90 odst. 1 zákoníku práce',
                    );
                }
            }
            $previous = $day;
        }

        foreach ($nightByDay as $date => $minutes) {
            $findings[] = new JuvenileWorkTimeFinding(
                JuvenileWorkTimeFinding::CODE_NIGHT,
                sprintf(
                    '%s: mladistvý pracoval %s h v noční době (%d:00 až %d:00). Noční práce mladistvých je '
                    . 'zakázána (§ 245 odst. 1 zákoníku práce). Výjimkou je jen mladistvý starší 16 let, a to '
                    . 'nejvýše 1 h, je-li to potřebné pro jeho přípravu na povolání a navazuje-li noční práce '
                    . 'bezprostředně na denní; tuto výjimku aplikace sama nepozná.',
                    self::czechDate($date),
                    self::hours($minutes),
                    $this->nightStartHour,
                    $this->nightEndHour,
                ),
                $date,
                $date,
                $minutes,
                0,
                '§ 245 odst. 1 zákoníku práce',
            );
        }

        usort($findings, static fn (JuvenileWorkTimeFinding $a, JuvenileWorkTimeFinding $b): int => [$a->scopeFrom, $a->code] <=> [$b->scopeFrom, $b->code]);

        return $findings;
    }

    /** Minuty záznamu v noční době, okna se počítají v místním čase záznamu. */
    private function nightMinutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $minutes = 0;
        $day = $start->setTime(0, 0)->modify('-1 day');
        $last = $end->setTime(0, 0);
        while ($day <= $last) {
            $from = $day->setTime($this->nightStartHour, 0);
            $to = $day->modify('+1 day')->setTime($this->nightEndHour, 0);
            $overlap = min($end->getTimestamp(), $to->getTimestamp()) - max($start->getTimestamp(), $from->getTimestamp());
            if ($overlap > 0) {
                $minutes += intdiv($overlap, 60);
            }
            $day = $day->modify('+1 day');
        }

        return $minutes;
    }

    private static function czechDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }

    private static function hours(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
