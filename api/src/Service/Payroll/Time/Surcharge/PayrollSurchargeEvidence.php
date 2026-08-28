<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;

/**
 * Ze zápisů docházky udělá doloženou dobu, za kterou příplatek náleží.
 *
 * ── Den zápisu ───────────────────────────────────────────────────────────────
 *
 * Zápis patří ke dni, na který připadá jeho MÍSTNÍ ZAČÁTEK. Stejně to dělá
 * {@see \MyInvoice\Service\Payroll\Time\PayrollTimeService::startingInPeriod()},
 * když zařazuje zápisy do měsíce, a stejně je klíčované náhradní volno za
 * přesčas (`payroll_overtime_compensations.overtime_date`, migrace 1492).
 * Noční směna 22:00–06:00 tedy patří celá ke dni, kdy začala. Kdyby se
 * rozdělovala půlnocí, přestal by sedět klíč na náhradní volno a odečet by
 * padal do jiného dne, než ve kterém přesčas vznikl.
 *
 * ── Minuty ───────────────────────────────────────────────────────────────────
 *
 * Do příplatku jdou ČISTÉ minuty (interval bez přestávky), protože přestávka
 * v práci není výkonem práce a mzda za ni nepřísluší. Do KONTROLY, že zápis
 * leží uvnitř noční doby nebo na víkendu, jde naopak HRUBÝ interval: přestávka
 * se v datech nepolohuje, takže není známo, kterou část intervalu ubrala,
 * a předstírat, že vyplní zrovna tu závadnou minutu, by kontrolu vypnulo.
 *
 * ── Co se odmítne ────────────────────────────────────────────────────────────
 *
 * Fail-closed na čtyřech místech, pokaždé proto, že tichý průchod by vyrobil
 * číslo, které vypadá jako výsledek:
 *
 *  1. `night` zápis, který zasahuje mimo noční dobu podle § 78 odst. 1 písm. k).
 *  2. `weekend` zápis, který zasahuje na jiný den než sobotu nebo neděli.
 *  3. `holiday` zápis, který zasahuje na den, který svátkem není.
 *  4. Součet příznakových minut dne vyšší než odpracované minuty téhož dne.
 *     Kategorie jsou PŘEKRYVNÉ příznaky nad odpracovanou dobou, ne samostatné
 *     řezy — víc nočních minut než odpracovaných je proto vada evidence, ne
 *     bohatší podklad.
 */
final class PayrollSurchargeEvidence
{
    /** U pracovního vztahu není doložený počet ztěžujících vlivů podle § 117. */
    public const DIFFICULTY_FACTORS_MISSING = 'difficulty_factors_missing';

    /** Práce ve svátek je evidovaná, ale způsob odměnění podle § 115 sjednán není. */
    public const HOLIDAY_ARRANGEMENT_MISSING = 'holiday_arrangement_missing';

    /**
     * Za svátek se má poskytnout náhradní volno, ale modul nemá kam zapsat, že
     * bylo za KONKRÉTNÍ svátek poskytnuto — `payroll_overtime_compensations` je
     * podle migrace 1492 klíčované dnem PŘESČASU a na svátek se nevztahuje,
     * `absence_compensatory_time_off` (migrace 1499) je zase jen den ČERPÁNÍ
     * a k svátku ho přiřadit nelze.
     */
    public const HOLIDAY_TIME_OFF_EVIDENCE_MISSING = 'holiday_time_off_evidence_missing';

    /** Náhradní volno za přesčas je sjednáno, lhůta § 114 odst. 2 ještě běží. */
    public const OVERTIME_TIME_OFF_PENDING = 'overtime_time_off_pending';

    /** Lhůta § 114 odst. 2 uplynula, aniž bylo náhradní volno poskytnuto. */
    public const OVERTIME_TIME_OFF_LAPSED = 'overtime_time_off_lapsed';

    public function __construct(private readonly CzechHolidayCalendar $holidays) {}

    /**
     * @param list<array<string,mixed>> $entries řádky `payroll_time_entries`
     * @param list<array<string,mixed>> $compensations řádky `payroll_overtime_compensations`
     * @param string $periodStart první den zpracovávaného měsíce (RRRR-MM-01)
     * @param string $assessedOn den, ke kterému se posuzuje lhůta § 114 odst. 2
     */
    public function collect(
        array $entries,
        array $compensations,
        PayrollSurchargePolicy $policy,
        PayrollSurchargeRuleset $ruleset,
        string $periodStart,
        string $assessedOn,
    ): PayrollSurchargeEvidenceResult {
        $period = substr($this->assertDate($periodStart, 'periodStart'), 0, 7);
        $this->assertDate($assessedOn, 'assessedOn');

        $workedByDate = [];
        // Klíč je (druh, den, počet ztěžujících vlivů). Vlivy jsou v klíči proto,
        // že se v jednom dni mohou lišit zápis od zápisu — sloučit je do jednoho
        // průměru by u § 117 vyrobilo jiné číslo, než jaké zákon přiznává.
        /** @var array<string,array<string,array<int,int>>> $flagged */
        $flagged = [];
        foreach ($entries as $entry) {
            $status = $this->text($entry, 'status', 'draft');
            if ($status === 'superseded') {
                continue;
            }
            $category = $this->text($entry, 'category', '');
            [$localStart, $localEnd, $netMinutes] = $this->localBounds($entry);
            $date = $localStart->format('Y-m-d');
            if (substr($date, 0, 7) !== $period) {
                continue;
            }
            if ($category === 'regular' || $category === 'overtime') {
                $workedByDate[$date] = ($workedByDate[$date] ?? 0) + $netMinutes;
            }
            $kind = PayrollSurchargeKind::tryFrom($category);
            if ($kind === null) {
                continue;
            }
            if ($netMinutes <= 0) {
                continue;
            }
            $this->assertIntervalFitsCategory($kind, $localStart, $localEnd, $ruleset);
            $factors = $this->factors($kind, $entry, $policy, $date);
            $flagged[$kind->value][$date][$factors] =
                ($flagged[$kind->value][$date][$factors] ?? 0) + $netMinutes;
        }

        $this->assertOverlayFitsWorkedTime($flagged, $workedByDate);

        $segments = [];
        $waived = [];
        $findings = [];
        foreach (PayrollSurchargeKind::all() as $kind) {
            $days = $flagged[$kind->value] ?? [];
            ksort($days);
            foreach ($days as $date => $byFactors) {
                ksort($byFactors);
                foreach ($byFactors as $factors => $minutes) {
                    $segment = new PayrollSurchargeSegment(
                        $kind,
                        (string) $date,
                        $minutes,
                        $factors,
                    );
                    [$due, $off, $finding] = $this->applyCompensation(
                        $segment,
                        $policy,
                        $ruleset,
                        $compensations,
                        $assessedOn,
                    );
                    if ($due !== null) {
                        $segments[] = $due;
                    }
                    if ($off !== null) {
                        $waived[$kind->value][] = $off;
                    }
                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return new PayrollSurchargeEvidenceResult($segments, $waived, $findings);
    }

    /**
     * § 114 a § 115 — náhradní volno místo příplatku.
     *
     * @param list<array<string,mixed>> $compensations
     * @return array{0:?PayrollSurchargeSegment,1:?array<string,mixed>,2:?array{reason:string,message:string,local_date:?string}}
     */
    private function applyCompensation(
        PayrollSurchargeSegment $segment,
        PayrollSurchargePolicy $policy,
        PayrollSurchargeRuleset $ruleset,
        array $compensations,
        string $assessedOn,
    ): array {
        $kind = $segment->kind;
        if (!$kind->allowsCompensatoryTimeOff()) {
            return [$segment, null, null];
        }
        $mode = $policy->mode($kind);

        if ($kind === PayrollSurchargeKind::Holiday) {
            // § 115 odst. 1 dává jako VÝCHOZÍ náhradní volno, ne příplatek.
            // Bez sjednané zásady tedy nejde ani vyplatit (nebyla dohoda podle
            // odst. 2), ani mlčky nevyplatit (nikdo nedoložil, že se volno
            // poskytne). Fail-closed je tady jediná pravdivá odpověď.
            if ($policy->isStatutoryDefault) {
                throw PayrollSurchargeException::of(
                    self::HOLIDAY_ARRANGEMENT_MISSING,
                    sprintf(
                        'Ke dni %s je evidovaná práce ve svátek, ale u pracovního vztahu není '
                        . 'sjednáno, zda se podle § 115 poskytne náhradní volno, nebo příplatek.',
                        $segment->localDate,
                    ),
                );
            }
            if ($mode === PayrollSurchargeCompensationMode::CompensatoryTimeOff) {
                return [
                    null,
                    [
                        'local_date' => $segment->localDate,
                        'minutes' => $segment->minutes,
                        'reason' => self::HOLIDAY_TIME_OFF_EVIDENCE_MISSING,
                    ],
                    [
                        'reason' => self::HOLIDAY_TIME_OFF_EVIDENCE_MISSING,
                        'local_date' => $segment->localDate,
                        'message' => sprintf(
                            'Za práci ve svátek %s se podle § 115 odst. 1 poskytuje náhradní volno '
                            . 'nejpozději do konce %d. kalendářního měsíce po svátku. Modul jeho '
                            . 'poskytnutí k tomuto dni neeviduje — ověřte ho ručně.',
                            $segment->localDate,
                            $ruleset->compensatoryTimeOffMonths($kind),
                        ),
                    ],
                ];
            }

            return [$segment, null, null];
        }

        // Dál už jen § 114 (přesčas).
        if ($mode === PayrollSurchargeCompensationMode::IncludedInWage) {
            return [
                null,
                [
                    'local_date' => $segment->localDate,
                    'minutes' => $segment->minutes,
                    'reason' => 'wage_includes_overtime',
                ],
                null,
            ];
        }

        $compensated = 0;
        $granted = null;
        foreach ($compensations as $row) {
            if ($this->text($row, 'overtime_date', '') !== $segment->localDate) {
                continue;
            }
            $compensated += $this->int($row, 'minutes', 0);
            $grantedOn = $row['granted_on'] ?? null;
            if (is_string($grantedOn) && $grantedOn !== '') {
                $granted = $grantedOn;
            }
        }
        if ($compensated > $segment->minutes) {
            throw PayrollSurchargeException::of(
                'compensation_exceeds_overtime',
                sprintf(
                    'Ke dni %s je evidováno %d minut náhradního volna za přesčas, '
                    . 'ale přesčasu je jen %d minut.',
                    $segment->localDate,
                    $compensated,
                    $segment->minutes,
                ),
            );
        }

        $lapsed = $this->timeOffDeadlineLapsed(
            $segment->localDate,
            $ruleset->compensatoryTimeOffMonths($kind),
            $assessedOn,
        );

        // § 114 odst. 2 — neposkytne-li zaměstnavatel náhradní volno v době tří
        // kalendářních měsíců po výkonu práce přesčas, příplatek NÁLEŽÍ. Dokud
        // lhůta běží, příplatek se nevyplácí; jakmile uplyne bez poskytnutí,
        // vyplácí se za celou dobu.
        if ($compensated === 0) {
            if ($mode !== PayrollSurchargeCompensationMode::CompensatoryTimeOff) {
                return [$segment, null, null];
            }
            if ($lapsed) {
                return [
                    $segment,
                    null,
                    [
                        'reason' => self::OVERTIME_TIME_OFF_LAPSED,
                        'local_date' => $segment->localDate,
                        'message' => sprintf(
                            'Náhradní volno za přesčas %s nebylo poskytnuto ve lhůtě § 114 odst. 2, '
                            . 'proto se vyplácí příplatek.',
                            $segment->localDate,
                        ),
                    ],
                ];
            }

            return [
                null,
                [
                    'local_date' => $segment->localDate,
                    'minutes' => $segment->minutes,
                    'reason' => self::OVERTIME_TIME_OFF_PENDING,
                ],
                [
                    'reason' => self::OVERTIME_TIME_OFF_PENDING,
                    'local_date' => $segment->localDate,
                    'message' => sprintf(
                        'Za přesčas %s je sjednáno náhradní volno a lhůta § 114 odst. 2 běží. '
                        . 'Nebude-li poskytnuto, vznikne nárok na příplatek.',
                        $segment->localDate,
                    ),
                ],
            ];
        }

        $remaining = $segment->minutes - $compensated;
        $waivedRow = [
            'local_date' => $segment->localDate,
            'minutes' => $compensated,
            'granted_on' => $granted,
            'reason' => 'compensatory_time_off_granted',
        ];
        if ($granted === null && $lapsed) {
            // Zapsané, ale neposkytnuté náhradní volno lhůtu nestaví.
            return [
                $segment,
                null,
                [
                    'reason' => self::OVERTIME_TIME_OFF_LAPSED,
                    'local_date' => $segment->localDate,
                    'message' => sprintf(
                        'Náhradní volno za přesčas %s je evidované bez dne čerpání a lhůta '
                        . '§ 114 odst. 2 uplynula, proto se vyplácí příplatek za celou dobu.',
                        $segment->localDate,
                    ),
                ],
            ];
        }

        return [
            $remaining > 0 ? $segment->withMinutes($remaining) : null,
            $waivedRow,
            $granted === null
                ? [
                    'reason' => self::OVERTIME_TIME_OFF_PENDING,
                    'local_date' => $segment->localDate,
                    'message' => sprintf(
                        'Náhradní volno za přesčas %s je sjednané, ale bez dne čerpání.',
                        $segment->localDate,
                    ),
                ]
                : null,
        ];
    }

    /**
     * Lhůta „do konce třetího kalendářního měsíce následujícího po výkonu práce"
     * (§ 114 odst. 2, § 115 odst. 1). Počítá se v celých kalendářních měsících,
     * ne po devadesáti dnech — zákon je v tomhle jednoznačný.
     */
    private function timeOffDeadlineLapsed(string $workDate, int $months, string $assessedOn): bool
    {
        $deadline = (new DateTimeImmutable($workDate . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('first day of this month')
            ->modify('+' . $months . ' months')
            ->modify('last day of this month')
            ->format('Y-m-d');

        return $assessedOn > $deadline;
    }

    /**
     * @param array<string,array<string,array<int,int>>> $flagged
     * @param array<string,int> $workedByDate
     */
    private function assertOverlayFitsWorkedTime(array $flagged, array $workedByDate): void
    {
        foreach ($flagged as $category => $days) {
            foreach ($days as $date => $byFactors) {
                $worked = $workedByDate[$date] ?? 0;
                $minutes = array_sum($byFactors);
                if ($minutes > $worked) {
                    throw PayrollSurchargeException::of(
                        'overlay_exceeds_worked_time',
                        sprintf(
                            'Ke dni %s je evidováno %d minut kategorie %s, ale odpracováno bylo '
                            . 'jen %d minut. Kategorie příplatků jsou příznaky nad odpracovanou '
                            . 'dobou, ne samostatné hodiny.',
                            $date,
                            $minutes,
                            $category,
                            $worked,
                        ),
                    );
                }
            }
        }
    }

    private function assertIntervalFitsCategory(
        PayrollSurchargeKind $kind,
        DateTimeImmutable $localStart,
        DateTimeImmutable $localEnd,
        PayrollSurchargeRuleset $ruleset,
    ): void {
        if ($kind === PayrollSurchargeKind::Night) {
            $start = $ruleset->nightWindowStartHour();
            $end = $ruleset->nightWindowEndHour();
            foreach ($this->spannedDays($localStart, $localEnd) as [$dayStart, $from, $to]) {
                unset($dayStart);
                // Denní doba je doplněk noční: od konce noci do jejího začátku.
                if ($this->overlaps($from, $to, $end * 60, $start * 60)) {
                    throw PayrollSurchargeException::of(
                        'night_outside_window',
                        sprintf(
                            'Zápis noční práce %s až %s zasahuje mimo noční dobu %02d:00–%02d:00 '
                            . 'podle § 78 odst. 1 písm. k). Rozdělte ho.',
                            $localStart->format('Y-m-d H:i'),
                            $localEnd->format('Y-m-d H:i'),
                            $start,
                            $end,
                        ),
                    );
                }
            }

            return;
        }

        if ($kind === PayrollSurchargeKind::Weekend) {
            foreach ($this->spannedDays($localStart, $localEnd) as [$dayStart]) {
                if (!in_array((int) $dayStart->format('N'), [6, 7], true)) {
                    throw PayrollSurchargeException::of(
                        'weekend_outside_weekend',
                        sprintf(
                            'Zápis víkendové práce %s až %s zasahuje na %s, který sobotou ani '
                            . 'nedělí není. Rozdělte ho.',
                            $localStart->format('Y-m-d H:i'),
                            $localEnd->format('Y-m-d H:i'),
                            $dayStart->format('Y-m-d'),
                        ),
                    );
                }
            }

            return;
        }

        if ($kind === PayrollSurchargeKind::Holiday) {
            foreach ($this->spannedDays($localStart, $localEnd) as [$dayStart]) {
                $day = $dayStart->format('Y-m-d');
                if (!isset($this->holidays->forYear((int) $dayStart->format('Y'))[$day])) {
                    throw PayrollSurchargeException::of(
                        'holiday_not_a_holiday',
                        sprintf(
                            'Zápis práce ve svátek %s až %s zasahuje na %s, který svátkem není. '
                            . 'Rozdělte ho.',
                            $localStart->format('Y-m-d H:i'),
                            $localEnd->format('Y-m-d H:i'),
                            $day,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Místní dny, které interval protíná, i s minutovým rozsahem uvnitř dne.
     *
     * @return list<array{0:DateTimeImmutable,1:int,2:int}>
     */
    private function spannedDays(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $days = [];
        $cursor = $start->setTime(0, 0);
        $guard = 0;
        while ($cursor < $end) {
            $next = $cursor->modify('+1 day')->setTime(0, 0);
            $from = max($start, $cursor);
            $to = min($end, $next);
            if ($to > $from) {
                $days[] = [
                    $cursor,
                    intdiv($from->getTimestamp() - $cursor->getTimestamp(), 60),
                    intdiv($to->getTimestamp() - $cursor->getTimestamp(), 60),
                ];
            }
            $cursor = $next;
            if (++$guard > 40) {
                throw PayrollSurchargeException::of(
                    'interval_too_long',
                    'Zápis docházky pokrývá nepřípustně mnoho dnů.',
                );
            }
        }

        return $days;
    }

    private function overlaps(int $fromA, int $toA, int $fromB, int $toB): bool
    {
        return $fromA < $toB && $fromB < $toA;
    }

    /** @param array<string,mixed> $entry */
    private function factors(
        PayrollSurchargeKind $kind,
        array $entry,
        PayrollSurchargePolicy $policy,
        string $date,
    ): int {
        if ($kind !== PayrollSurchargeKind::DifficultEnvironment) {
            return 1;
        }
        $raw = $entry['difficulty_factor_count'] ?? null;
        $factors = is_int($raw) || (is_string($raw) && $raw !== '')
            ? (int) $raw
            : $policy->difficultEnvironmentFactors;
        if ($factors === null || $factors < 1) {
            throw PayrollSurchargeException::of(
                self::DIFFICULTY_FACTORS_MISSING,
                sprintf(
                    'Ke dni %s je evidovaná práce ve ztíženém pracovním prostředí, ale není '
                    . 'doložený počet ztěžujících vlivů. § 117 přiznává příplatek za KAŽDÝ '
                    . 'ztěžující vliv, takže bez počtu ho spočítat nelze.',
                    $date,
                ),
            );
        }

        return $factors;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:int}
     */
    private function localBounds(array $entry): array
    {
        $zone = new DateTimeZone($this->text($entry, 'timezone_name', 'UTC'));
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($this->text($entry, 'starts_at_utc', ''), $utc);
        $end = new DateTimeImmutable($this->text($entry, 'ends_at_utc', ''), $utc);
        $gross = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        $net = max(0, $gross - $this->int($entry, 'break_minutes', 0));

        return [$start->setTimezone($zone), $end->setTimezone($zone), $net];
    }

    /** @param array<string,mixed> $row */
    private function text(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param array<string,mixed> $row */
    private function int(array $row, string $key, int $default): int
    {
        $value = $row[$key] ?? null;

        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : $default;
    }

    private function assertDate(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw PayrollSurchargeException::of(
                'invalid_date',
                "Parametr {$field} není datum ve tvaru RRRR-MM-DD.",
            );
        }

        return $value;
    }
}
