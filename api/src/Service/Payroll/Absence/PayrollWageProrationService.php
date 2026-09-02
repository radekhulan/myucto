<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\MonthlyWageProration;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollMonthlyFundService;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;

/**
 * Podklad pro krácení měsíční mzdy za absence jednoho vztahu v jednom měsíci.
 *
 * Aritmetiku dělá {@see MonthlyWageProration}; tahle služba jen sesbírá dvě
 * čísla, ze kterých se počítá — fond pracovní doby měsíce a minuty, které
 * z něj vypadly.
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 *
 * Nenavrhne se nic, dokud si aplikace není jistá:
 *
 *  - vztah nemá pracovní kalendář → z čeho krátit se neví
 *    ({@see PayrollMonthlyFundService::minutes()} vrací `null`, ne nulu),
 *  - v měsíci leží absence, o které se ještě nerozhodlo → částka by se po
 *    schválení změnila,
 *  - nemoc nemá zmrazený výpočet náhrady → okno § 192 by se hádalo znovu,
 *  - nahrazené minuty přesahují fond → evidence si odporuje.
 *
 * Vrátit v takové chvíli celou sjednanou mzdu je horší než nenavrhnout nic:
 * číslo vypadá hotově a nikdo ho už nezkontroluje.
 */
final class PayrollWageProrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollMonthlyFundService $fund,
        private readonly CzechHolidayCalendar $holidays = new CzechHolidayCalendar(),
    ) {}

    /**
     * @return array{
     *   supported:bool,
     *   reason:?string,
     *   fund_minutes:?int,
     *   replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,
     *   amount_minor:?int,
     *   trace:?array<string,mixed>
     * }
     */
    public function forMonth(
        int $supplierId,
        int $employmentId,
        string $period,
        int $monthlyGrossMinor,
    ): array {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $periodStart = $start->format('Y-m-d');
        $periodEnd = $start->modify('last day of this month')->format('Y-m-d');

        $rows = $this->absencesInMonth($supplierId, $employmentId, $periodStart, $periodEnd);
        if ($rows === []) {
            return self::none();
        }
        foreach ($rows as $row) {
            if (PayrollTimeValue::string($row['status'] ?? null, 'status') !== 'approved') {
                return self::unsupported('absence_pending_decision');
            }
            if ((int) ($row['correction_pending'] ?? 0) === 1) {
                return self::unsupported('absence_correction_pending');
            }
        }

        $fundMinutes = $this->fund->minutes($supplierId, $employmentId, $period);
        if ($fundMinutes === null) {
            return self::unsupported('missing_work_calendar');
        }

        $byTitle = [];
        $holidayMinutes = 0;
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(
            $this->holidays,
            $periodStart,
            $periodEnd,
        );
        foreach ($rows as $row) {
            $type = PayrollTimeValue::string($row['absence_type'] ?? null, 'absence_type');
            if ($type === 'dpn' || $type === 'quarantine') {
                $firstDayFullyWorked = $this->firstDayFullyWorked($supplierId, $row);
                if ($firstDayFullyWorked === null) {
                    return self::unsupported('sickness_calculation_missing');
                }
                $window = $this->absences->publishedShiftSegments(
                    $row,
                    $firstDayFullyWorked,
                    AbsenceHolidayTreatment::CompensateSickness,
                );
                $inWindow = self::minutesInMonth($window, $periodStart, $periodEnd);
                $byTitle[PayrollWageReplacementTitle::SicknessCompensation->value]
                    = ($byTitle[PayrollWageReplacementTitle::SicknessCompensation->value] ?? 0)
                    + $inWindow;
                $byTitle[PayrollWageReplacementTitle::StateBenefit->value]
                    = ($byTitle[PayrollWageReplacementTitle::StateBenefit->value] ?? 0)
                    + self::minutesInMonth(
                        $this->absences->publishedShiftSegmentsBeyondSicknessWindow(
                            $row,
                            $firstDayFullyWorked,
                        ),
                        $periodStart,
                        $periodEnd,
                    );
                // Svátek v okně § 192 se proplácí náhradou, takže tatáž doba
                // nesmí zůstat i v základní mzdě. Fond ji ale nezná — svátku
                // ukládá nula plánovaných minut — a bez tohohle dopočtu by
                // svátek zůstal zaplacený dvakrát.
                $holidayMinutes += self::minutesOnDates($window, $periodStart, $periodEnd, $holidays);
                continue;
            }

            $title = PayrollWageReplacementTitle::forAbsenceType($type);
            if ($title === null) {
                return self::unsupported('absence_type_unsupported');
            }
            $segments = $this->absences->publishedShiftSegments(
                $row,
                false,
                PayrollWageReplacementTitle::holidayTreatment($type),
            );
            // Ze základní mzdy vypadne svátek JEN tehdy, když ho nějaký titul
            // opravdu zaplatí — a to umí pouze náhrada při DPN (§ 192 odst. 1).
            // U dovolené se svátek nečerpá (§ 219 odst. 1) a u ostatních absencí
            // za něj nikdo nic neposkytuje, takže mzda za něj zůstává nekrácená
            // (§ 115 odst. 3). Publikovaná směna na svátek by jinak srazila mzdu
            // za dobu, kterou nic nenahrazuje.
            $byTitle[$title->value] = ($byTitle[$title->value] ?? 0)
                + self::minutesInMonth($segments, $periodStart, $periodEnd)
                - self::minutesOnDates($segments, $periodStart, $periodEnd, $holidays);
        }

        $byTitle = array_filter($byTitle, static fn (int $minutes): bool => $minutes > 0);
        if ($byTitle === []) {
            return self::none();
        }
        if ($fundMinutes <= 0) {
            return self::unsupported('empty_work_fund');
        }
        // Fond svátky nezná, ale mezi nahrazenými minutami jsou — o tolik smí
        // absence fond přesáhnout. Cokoli nad to znamená, že si evidence
        // odporuje (třeba směna publikovaná mimo rozvrh) a číslo by lhalo.
        if (array_sum($byTitle) - $holidayMinutes > $fundMinutes) {
            return self::unsupported('absence_exceeds_work_fund');
        }

        $result = MonthlyWageProration::calculate($monthlyGrossMinor, $fundMinutes, $byTitle);

        return [
            'supported' => true,
            'reason' => null,
            'fund_minutes' => $result->fundMinutes,
            'replaced_minutes' => $result->replacedMinutes,
            'replaced_minutes_by_title' => $result->replacedMinutesByTitle,
            'amount_minor' => $result->amountMinor,
            'trace' => $result->trace(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function absencesInMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?
                AND status IN ("requested", "approved")
                AND date_from <= ? AND date_to >= ?
              ORDER BY date_from, id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);

        return PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_absences');
    }

    /**
     * Byl první den nemoci odpracován celý? Odpověď je zmrazená ve výpočtu
     * náhrady; hádat ji znovu by posunulo okno § 192 o den.
     *
     * @param array<string,mixed> $absence
     */
    private function firstDayFullyWorked(int $supplierId, array $absence): ?bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT first_day_fully_worked
               FROM payroll_sickness_events
              WHERE supplier_id = ? AND absence_id = ?'
        );
        $stmt->execute([$supplierId, PayrollTimeValue::int($absence['id'] ?? null, 'absence_id')]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value === 1;
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     */
    private static function minutesInMonth(array $segments, string $from, string $to): int
    {
        $minutes = 0;
        foreach ($segments as $segment) {
            $date = (string) $segment['local_date'];
            if ($date >= $from && $date <= $to) {
                $minutes += (int) $segment['eligible_minutes'];
            }
        }

        return $minutes;
    }

    /**
     * @param list<array{shift_id:?int,local_date:string,planned_minutes:int,eligible_minutes:int}> $segments
     * @param array<string,mixed> $dates
     */
    private static function minutesOnDates(
        array $segments,
        string $from,
        string $to,
        array $dates,
    ): int {
        $minutes = 0;
        foreach ($segments as $segment) {
            $date = (string) $segment['local_date'];
            if ($date >= $from && $date <= $to && array_key_exists($date, $dates)) {
                $minutes += (int) $segment['eligible_minutes'];
            }
        }

        return $minutes;
    }

    /**
     * @return array{
     *   supported:bool,reason:?string,fund_minutes:?int,replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,amount_minor:?int,trace:?array<string,mixed>
     * }
     */
    private static function none(): array
    {
        return [
            'supported' => true,
            'reason' => null,
            'fund_minutes' => null,
            'replaced_minutes' => 0,
            'replaced_minutes_by_title' => [],
            'amount_minor' => null,
            'trace' => null,
        ];
    }

    /**
     * @return array{
     *   supported:bool,reason:?string,fund_minutes:?int,replaced_minutes:int,
     *   replaced_minutes_by_title:array<string,int>,amount_minor:?int,trace:?array<string,mixed>
     * }
     */
    private static function unsupported(string $reason): array
    {
        return [
            'supported' => false,
            'reason' => $reason,
            'fund_minutes' => null,
            'replaced_minutes' => 0,
            'replaced_minutes_by_title' => [],
            'amount_minor' => null,
            'trace' => null,
        ];
    }
}
