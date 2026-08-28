<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollMealShiftEvidenceRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Kolik osvobozených příspěvků na stravování za měsíc VZNIKLO — a čím je to doložené.
 *
 * Doslovné znění § 6 odst. 9 písm. b) ZDP účinné pro rok 2026 dává tři podmínky
 * a dvě větve. Podmínky jsou:
 *
 *  1. příspěvek je poskytnut „za jednu směnu podle jiného právního předpisu",
 *  2. „během této směny zaměstnanec vykonával práci alespoň 3 hodiny",
 *  3. „nevznikl mu během této směny nárok na stravné v rámci cestovních náhrad".
 *
 * Druhý příspěvek téže směny má vlastní, stejně vysoký limit, ale jen tehdy,
 * „pokud její délka V ÚHRNU S PŘESTÁVKOU v práci povinně poskytovanou
 * zaměstnavatelem … je delší než 11 hodin". Nerovnost je tu OSTRÁ („delší než"),
 * na rozdíl od „alespoň 3 hodiny", které je neostré — a měří se HRUBÝ interval
 * směny, protože zákon přestávku výslovně přičítá.
 *
 * Druhá větev platí pro zaměstnance, „jejíž výkon není rozvržen na směny podle
 * jiného právního předpisu". Tam je jednotkou KALENDÁŘNÍ DEN a podmínka druhého
 * příspěvku zní jinak: „pokud během tohoto dne zaměstnanec vykonával práci
 * alespoň 11 hodin", tedy NEOSTŘE a o odpracované době, ne o délce intervalu.
 * Větev je explicitní vlastností pracovního vztahu. Absence publikovaného
 * rozvrhu sama o sobě nikdy nesmí změnit právní režim na kalendářní dny.
 *
 * ── Fail-closed ────────────────────────────────────────────────────────────────
 * Nárok se nikdy neodhaduje z úvazku ani z počtu pracovních dnů. Není-li docházka
 * měsíce UZAVŘENÁ, je počet nároků jen mezistav a {@see PayrollMealShiftEntitlement}
 * ho označí za nedoložený — schválení mzdového vstupu na něm stojí a hláška
 * pojmenuje, co chybí. Uzavřená docházka bez jediné odpracované směny naopak
 * doložený podklad JE: znamená nula nároků, tedy celý příspěvek zdanitelný.
 */
final class PayrollMealShiftEvidenceService
{
    /** Osoba u zaměstnavatele nemá jediný pracovní vztah. */
    public const MISSING_EMPLOYMENT = 'employment_missing';

    /** Docházka měsíce není uzavřená, počet směn tedy ještě není podklad. */
    public const MISSING_ATTENDANCE_MONTH = 'attendance_month_open';

    /**
     * Za období není evidovaná docházka VŮBEC — ani měsíc, ani směna, ani
     * odpracovaný čas. Nula nároků by tu byla tvrzení, ne zjištění.
     */
    public const MISSING_ATTENDANCE = 'attendance_missing';

    /** Směnový režim má odpracovaný čas, ale chybí publikovaný rozvrh. */
    public const MISSING_SHIFT_SCHEDULE = 'shift_schedule_missing';

    /** Kalendářní režim je v rozporu s publikovanými směnami. */
    public const MEAL_BASIS_CONFLICT = 'meal_basis_conflict';

    /** Přestávku v intervalu přes půlnoc nelze bez další evidence přiřadit ke dni. */
    public const CALENDAR_DAY_BREAK_ALLOCATION_MISSING = 'calendar_day_break_allocation_missing';

    public function __construct(
        private readonly PayrollMealShiftEvidenceRepository $repository,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @throws PayrollRulesetException když ruleset pro období netvrdí meze
     */
    public function forPeriod(
        int $supplierId,
        int $employeeId,
        string $periodStart,
    ): PayrollMealShiftEntitlement {
        $minimumWork = $this->minutes($periodStart, 'benefit_exemption.meal.minimum_work_minutes');
        $secondShift = $this->minutes(
            $periodStart,
            'benefit_exemption.meal.second_contribution_shift_minutes',
        );
        $secondDay = $this->minutes(
            $periodStart,
            'benefit_exemption.meal.second_contribution_day_minutes',
        );

        $employments = $this->repository->employments($supplierId, $employeeId, $periodStart);
        if ($employments === []) {
            return new PayrollMealShiftEntitlement(
                $periodStart,
                PayrollMealShiftEntitlement::BASIS_SHIFT,
                0,
                0,
                false,
                [self::MISSING_EMPLOYMENT],
            );
        }
        $ids = array_map(
            static fn (array $employment): int => $employment['employment_id'],
            $employments,
        );

        $shifts = $this->inPeriod(
            $this->repository->shifts($supplierId, $ids, $periodStart),
            $periodStart,
        );
        $worked = $this->repository->workedIntervals($supplierId, $ids, $periodStart);
        $trips = $this->repository->mealAllowanceTrips($supplierId, $ids, $periodStart);
        $missing = $this->missingEvidence($employments, $shifts, $worked, $periodStart);

        $qualifying = 0;
        $second = 0;
        $bases = [];
        foreach ($employments as $employment) {
            $employmentId = $employment['employment_id'];
            $employmentShifts = $this->forEmployment($shifts, $employmentId);
            $employmentWorked = $this->forEmployment($worked, $employmentId);
            $employmentTrips = $this->forEmployment($trips, $employmentId);
            $basis = $employment['meal_entitlement_basis'];
            if ($basis === PayrollMealShiftEntitlement::BASIS_CALENDAR_DAY) {
                if ($this->hasAmbiguousCalendarDayBreak($employmentWorked, $periodStart)) {
                    $employmentQualifying = 0;
                    $employmentSecond = 0;
                } else {
                    [$employmentQualifying, $employmentSecond] = $this->byCalendarDay(
                        $employmentWorked,
                        $employmentTrips,
                        $minimumWork,
                        $secondDay,
                        $periodStart,
                    );
                }
            } else {
                [$employmentQualifying, $employmentSecond] = $this->byShift(
                    $employmentShifts,
                    $employmentWorked,
                    $employmentTrips,
                    $minimumWork,
                    $secondShift,
                );
            }
            $bases[$basis] = true;
            $qualifying += $employmentQualifying;
            $second += $employmentSecond;
        }

        $basis = match (count($bases)) {
            0 => PayrollMealShiftEntitlement::BASIS_CALENDAR_DAY,
            1 => (string) array_key_first($bases),
            default => PayrollMealShiftEntitlement::BASIS_MIXED,
        };

        return new PayrollMealShiftEntitlement(
            $periodStart,
            $basis,
            $qualifying,
            $second,
            $missing === [],
            $missing,
        );
    }

    /**
     * Co v podkladu chybí — a chybí-li něco, nárok se nesmí spočítat.
     *
     * Dvě různé situace, dva různé kódy:
     *
     *  - `attendance_missing`: osoba za období nemá ani měsíc docházky, ani směnu,
     *    ani odpracovaný čas. Nula nároků by tu byla tvrzení, ne zjištění, a tiše
     *    by zdanila celý příspěvek.
     *  - `attendance_month_open`: podklad existuje, ale měsíc není uzavřený, takže
     *    se počet směn může ještě změnit. Kontroluje se jen u vztahů, které
     *    nějakou evidenci opravdu mají — jinak by statutární orgán bez docházky
     *    blokoval schválení celé firmě.
     *
     * @param list<array{employment_id:int, meal_entitlement_basis:string, month_status:?string}> $employments
     * @param list<array{employment_id:int, ...}> $shifts
     * @param list<array{employment_id:int, ...}> $worked
     * @return list<string>
     */
    private function missingEvidence(
        array $employments,
        array $shifts,
        array $worked,
        string $periodStart,
    ): array
    {
        $reasons = [];
        foreach ($employments as $employment) {
            $id = $employment['employment_id'];
            $employmentShifts = $this->forEmployment($shifts, $id);
            $employmentWorked = $this->forEmployment($worked, $id);
            if ($employment['month_status'] === null) {
                $reasons[self::MISSING_ATTENDANCE] = true;
                continue;
            }
            if ($employment['month_status'] !== 'approved') {
                $reasons[self::MISSING_ATTENDANCE_MONTH] = true;
            }
            if ($employment['meal_entitlement_basis'] === PayrollMealShiftEntitlement::BASIS_SHIFT
                && $this->inPeriod($employmentWorked, $periodStart) !== []
                && $employmentShifts === []
            ) {
                $reasons[self::MISSING_SHIFT_SCHEDULE] = true;
            }
            if ($employment['meal_entitlement_basis'] === PayrollMealShiftEntitlement::BASIS_CALENDAR_DAY
                && $employmentShifts !== []
            ) {
                $reasons[self::MEAL_BASIS_CONFLICT] = true;
            }
            if ($employment['meal_entitlement_basis'] === PayrollMealShiftEntitlement::BASIS_CALENDAR_DAY
                && $this->hasAmbiguousCalendarDayBreak($employmentWorked, $periodStart)
            ) {
                $reasons[self::CALENDAR_DAY_BREAK_ALLOCATION_MISSING] = true;
            }
        }

        return array_keys($reasons);
    }

    /**
     * @param list<array{employment_id:int, ...}> $rows
     * @return list<array{employment_id:int, ...}>
     */
    private function forEmployment(array $rows, int $employmentId): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['employment_id'] === $employmentId,
        ));
    }

    /**
     * Větev směn. Odpracovaná doba se směně přiřazuje PRŮNIKEM intervalů a
     * přestávka se odečítá celá — kdyby část přestávky ležela mimo směnu, výsledek
     * je nižší, tedy ve prospěch zdanění. Odhadovat opačným směrem se u osvobození
     * nesmí.
     *
     * @param list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}> $shifts
     * @param list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}> $worked
     * @param list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string}> $trips
     * @return array{0:int,1:int}
     */
    private function byShift(
        array $shifts,
        array $worked,
        array $trips,
        int $minimumWork,
        int $secondShift,
    ): array {
        $qualifying = 0;
        $second = 0;
        foreach ($shifts as $shift) {
            [$start, $end] = $this->bounds($shift);
            if ($this->overlapsTrip($start, $end, $trips)) {
                continue;
            }
            $minutes = 0;
            foreach ($worked as $entry) {
                [$entryStart, $entryEnd] = $this->bounds($entry);
                $overlap = $this->overlapMinutes($start, $end, $entryStart, $entryEnd);
                if ($overlap === 0) {
                    continue;
                }
                $minutes += $overlap - $entry['break_minutes'];
            }
            if (max(0, $minutes) < $minimumWork) {
                continue;
            }
            ++$qualifying;
            if (intdiv($end - $start, 60) > $secondShift) {
                ++$second;
            }
        }

        return [$qualifying, $second];
    }

    /**
     * Větev kalendářních dnů — výkon práce není rozvržen na směny.
     *
     * @param list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}> $worked
     * @param list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string}> $trips
     * @return array{0:int,1:int}
     */
    private function byCalendarDay(
        array $worked,
        array $trips,
        int $minimumWork,
        int $secondDay,
        string $periodStart,
    ): array {
        /** @var array<string,int> $days */
        $days = [];
        /** @var array<string,bool> $tripDays */
        $tripDays = [];
        foreach ($worked as $entry) {
            foreach ($this->calendarDaySegments($entry) as [$day, $start, $end, $minutes]) {
                if (substr($day, 0, 7) !== substr($periodStart, 0, 7)) {
                    continue;
                }
                $days[$day] = ($days[$day] ?? 0) + $minutes;
                if ($this->overlapsTrip($start, $end, $trips)) {
                    $tripDays[$day] = true;
                }
            }
        }
        $qualifying = 0;
        $second = 0;
        foreach ($days as $day => $minutes) {
            if (isset($tripDays[$day]) || $minutes < $minimumWork) {
                continue;
            }
            ++$qualifying;
            if ($minutes >= $secondDay) {
                ++$second;
            }
        }

        return [$qualifying, $second];
    }

    /**
     * Rozpad jednoho UTC intervalu po místních kalendářních dnech.
     *
     * Interval přes půlnoc s přestávkou sem nesmí projít: bez přesnější evidence
     * nelze určit, kterému dni se má přestávka odečíst, a caller ho proto označí
     * jako nedoložený podklad.
     *
     * @param array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int} $entry
     * @return list<array{0:string,1:int,2:int,3:int}>
     */
    private function calendarDaySegments(array $entry): array
    {
        $zone = new \DateTimeZone($entry['timezone_name']);
        $utc = new \DateTimeZone('UTC');
        $cursor = (new \DateTimeImmutable($entry['starts_at_utc'], $utc))->setTimezone($zone);
        $end = (new \DateTimeImmutable($entry['ends_at_utc'], $utc))->setTimezone($zone);
        $segments = [];
        while ($cursor < $end) {
            $nextMidnight = $cursor->modify('tomorrow')->setTime(0, 0);
            $segmentEnd = $nextMidnight < $end ? $nextMidnight : $end;
            $startTimestamp = $cursor->getTimestamp();
            $endTimestamp = $segmentEnd->getTimestamp();
            $segments[] = [
                $cursor->format('Y-m-d'),
                $startTimestamp,
                $endTimestamp,
                max(
                    0,
                    intdiv($endTimestamp - $startTimestamp, 60) - $entry['break_minutes'],
                ),
            ];
            $cursor = $segmentEnd;
        }

        return $segments;
    }

    /**
     * @param list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}> $worked
     */
    private function hasAmbiguousCalendarDayBreak(array $worked, string $periodStart): bool
    {
        $utc = new \DateTimeZone('UTC');
        foreach ($worked as $entry) {
            if ($entry['break_minutes'] <= 0) {
                continue;
            }
            $zone = new \DateTimeZone($entry['timezone_name']);
            $start = (new \DateTimeImmutable($entry['starts_at_utc'], $utc))->setTimezone($zone);
            $end = (new \DateTimeImmutable($entry['ends_at_utc'], $utc))->setTimezone($zone);
            if ($end <= $start
                || $start->format('Y-m-d') === $end->modify('-1 second')->format('Y-m-d')
            ) {
                continue;
            }
            $monthStart = new \DateTimeImmutable(substr($periodStart, 0, 7) . '-01', $zone);
            $monthEnd = $monthStart->modify('first day of next month');
            if ($start < $monthEnd && $end > $monthStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zúžení na kalendářní měsíc podle MÍSTNÍHO začátku intervalu.
     *
     * Rozhoduje místní zóna uložená u záznamu, ne UTC — „směna" i „kalendářní den"
     * jsou v zákoně místní pojmy a noční směna z 31. na 1. patří do měsíce, ve
     * kterém začala.
     *
     * @param list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}> $rows
     * @return list<array{starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}>
     */
    private function inPeriod(array $rows, string $periodStart): array
    {
        $period = substr($periodStart, 0, 7);

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => substr($this->localDay($row), 0, 7) === $period,
        ));
    }

    /** @param array{starts_at_utc:string, timezone_name:string} $row */
    private function localDay(array $row): string
    {
        return (new \DateTimeImmutable($row['starts_at_utc'], new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($row['timezone_name']))
            ->format('Y-m-d');
    }

    /**
     * @param array{starts_at_utc:string, ends_at_utc:string} $row
     * @return array{0:int,1:int}
     */
    private function bounds(array $row): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            (new \DateTimeImmutable($row['starts_at_utc'], $utc))->getTimestamp(),
            (new \DateTimeImmutable($row['ends_at_utc'], $utc))->getTimestamp(),
        ];
    }

    /** @param list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string}> $trips */
    private function overlapsTrip(int $start, int $end, array $trips): bool
    {
        foreach ($trips as $trip) {
            [$tripStart, $tripEnd] = $this->bounds($trip);
            if ($this->overlapMinutes($start, $end, $tripStart, $tripEnd) > 0) {
                return true;
            }
        }

        return false;
    }

    private function overlapMinutes(int $start, int $end, int $otherStart, int $otherEnd): int
    {
        $from = max($start, $otherStart);
        $to = min($end, $otherEnd);

        return $to <= $from ? 0 : intdiv($to - $from, 60);
    }

    private function minutes(string $periodStart, string $key): int
    {
        $value = $this->rulesets
            ->forCalculation(PayrollRulesetDomain::IncomeTax, $periodStart)
            ->parameter($key)
            ->value;
        if (!is_int($value) || $value <= 0) {
            throw new PayrollRulesetException("Parametr {$key} není počet minut.");
        }

        return $value;
    }
}
