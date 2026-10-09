<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

/**
 * Pracovní kalendář vztahu podle sjednaného úvazku, když žádný nemá.
 *
 * Import docházky nese měsíční součty, fond pracovní doby ale počítá mzda
 * i hlášení z kalendáře. Bez něj by vztah s importovanou docházkou neměl fond
 * vůbec. Kalendář se zakládá jen z toho, co vztah skutečně sjednal: týdenní
 * doba z podmínek rozvržená rovnoměrně na pondělí až pátek. Svátky se do
 * kalendáře neukládají, dopočítá je {@see PayrollCalendarFundService} stejně
 * jako u ručně založeného kalendáře.
 *
 * Dohody kalendář nedostávají: pro ně má výpočet fondu vlastní náhradu
 * z odpracované doby ({@see PayrollJmhzWorkMonthSummaryBuilder}), a rozvrh
 * „40 h týdně" by jim fond vymyslel. Stejně tak vztahy, u kterých se fond
 * nevykazuje (společník, statutární orgán).
 */
final class PayrollEmploymentCalendarProvisioner
{
    public const ISSUE_WEEKLY_HOURS_MISSING = 'weekly_hours_missing';
    public const ISSUE_NOT_IN_PERIOD = 'employment_not_in_period';
    public const ISSUE_MONTH_APPROVED = 'time_month_approved';
    public const ISSUE_FUND_MISMATCH = 'import_fund_mismatch';

    /** Shodné s PayrollJmhzWorkMonthSummaryBuilder::isAgreement(). */
    private const AGREEMENTS = ['dpp', 'dpc'];

    /** Shodné s PayrollJmhzWorkMonthSummaryBuilder::requiresShiftCalendar(). */
    private const WITHOUT_CALENDAR = ['partner_dependent', 'statutory_body'];

    private const WORKDAYS = [1, 2, 3, 4, 5];

    public function __construct(
        private readonly PayrollTimeRepository $repository,
        private readonly PayrollMonthlyFundService $monthlyFund,
    ) {}

    /**
     * @return array{created:bool,issue:?string,skipped:?string,calendar_id:?int,fund_minutes:?int}
     */
    public function ensureForPeriod(int $supplierId, int $employmentId, string $periodStart, ?int $userId): array
    {
        $period = self::period($periodStart);
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('first day of next month')->format('Y-m-d');
        $facts = $this->repository->calendarProvisioningFacts($supplierId, $employmentId, $periodStart, $periodEnd)
            ?? throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');

        $relationType = $facts['relation_type'];
        if (in_array($relationType, self::AGREEMENTS, true)) {
            return self::result(false, null, 'agreement', null, null);
        }
        if (in_array($relationType, self::WITHOUT_CALENDAR, true)) {
            return self::result(false, null, 'relation_without_calendar', null, null);
        }
        $firstDay = $facts['first_day'];
        if ($firstDay === null) {
            return self::result(false, self::ISSUE_NOT_IN_PERIOD, null, null, null);
        }

        // Verze od začátku měsíce dál, i v pozdějších měsících: převod staršího roku až
        // po novějším (PAMICA po letech v libovolném pořadí) najde kalendář založený
        // od novějšího roku a nový musí skončit den před ním. Dřív se hledalo jen
        // v měsíci, nový kalendář bez konce narazil na překryv a starší měsíce zůstaly
        // bez fondu (neschválené), na rozdíl od převodu v pořadí let.
        $versions = $this->repository->calendars($supplierId, $employmentId, $periodStart, '9999-12-31');
        $validTo = null;
        foreach ($versions as $version) {
            $from = PayrollTimeValue::string($version['valid_from'] ?? null, 'valid_from');
            $to = $version['valid_to'] === null ? null : PayrollTimeValue::string($version['valid_to'], 'valid_to');
            if ($from <= $firstDay && ($to === null || $to >= $firstDay)) {
                return self::result(
                    false,
                    null,
                    null,
                    PayrollTimeValue::int($version['id'] ?? null, 'id'),
                    $this->monthlyFund->minutes($supplierId, $employmentId, $period),
                );
            }
            if ($from > $firstDay && $validTo === null) {
                // Pozdější verze: nový kalendář končí den před ní.
                $validTo = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            }
        }

        $weeklyMinutes = PayrollAbsenceRepository::weeklyMinutesFromHours($facts['weekly_hours']);
        if ($weeklyMinutes === null) {
            return self::result(false, self::ISSUE_WEEKLY_HOURS_MISSING, null, null, null);
        }

        $month = $this->repository->monthState($supplierId, $employmentId, $periodStart);
        if ($month !== null && ($month['status'] ?? null) !== 'open') {
            return self::result(false, self::ISSUE_MONTH_APPROVED, null, null, null);
        }

        try {
            $calendar = $this->repository->createCalendarVersion(
                $supplierId,
                $employmentId,
                sprintf('Rozvrh podle úvazku %s h týdně', self::hours($weeklyMinutes)),
                'Europe/Prague',
                'regular',
                self::weekPattern($weeklyMinutes),
                $weeklyMinutes,
                $firstDay,
                $validTo,
                0,
                $month === null ? 0 : PayrollTimeValue::int($month['row_version'] ?? null, 'row_version'),
                [],
                $userId,
            );
        } catch (PayrollTimeLockedException) {
            return self::result(false, self::ISSUE_MONTH_APPROVED, null, null, null);
        }

        return self::result(
            true,
            null,
            null,
            PayrollTimeValue::int($calendar['id'] ?? null, 'id'),
            $this->monthlyFund->minutes($supplierId, $employmentId, $period),
        );
    }

    /**
     * Fond z podkladů proti fondu kalendáře. Jen upozornění: rozdíl bývá
     * nástup nebo výstup v průběhu měsíce, zkrácený úvazek nebo jiný rozvrh,
     * a o tom, co platí, rozhoduje člověk, ne import.
     *
     * @return array{code:string,message:string,imported_millihours:int,calendar_minutes:int}|null
     */
    public function fundCheck(int $supplierId, int $employmentId, string $periodStart, int $importedMillihours): ?array
    {
        $calendarMinutes = $this->monthlyFund->minutes($supplierId, $employmentId, self::period($periodStart));
        if ($calendarMinutes === null) {
            return null;
        }
        // Porovnání v šedesátitisícinách hodiny, ať se nic nezaokrouhluje.
        if (abs($importedMillihours * 60 - $calendarMinutes * 1000) < 1000) {
            return null;
        }
        // Podklady jiných programů (PAMICA, docházkové systémy) vedou fond i se svátky
        // v jinak pracovní dny, stejně jako fond měsíčního hlášení (10259/10260). Takový
        // fond je týž rozvrh, ne jiný úvazek.
        $holidayMinutes = $this->monthlyFund->holidayMinutes($supplierId, $employmentId, self::period($periodStart)) ?? 0;
        if ($holidayMinutes > 0 && abs($importedMillihours * 60 - ($calendarMinutes + $holidayMinutes) * 1000) < 1000) {
            return null;
        }

        return [
            'code' => self::ISSUE_FUND_MISMATCH,
            'message' => sprintf(
                'Fond pracovní doby z podkladů (%s h) se liší od fondu podle pracovního kalendáře (%s h). '
                . 'Zkontrolujte úvazek, nástup nebo skončení v průběhu měsíce a rozvrh kalendáře.',
                number_format($importedMillihours / 1000, 2, '.', ''),
                self::hours($calendarMinutes),
            ),
            'imported_millihours' => $importedMillihours,
            'calendar_minutes' => $calendarMinutes,
        ];
    }

    /**
     * Týdenní doba rovnoměrně na pondělí až pátek. Minuty, které pětkou
     * beze zbytku dělit nejdou, připadnou po jedné prvním dnům týdne — součet
     * rozvrhu tak vždy sedí se sjednanou týdenní dobou.
     *
     * @return array<int,int>
     */
    private static function weekPattern(int $weeklyMinutes): array
    {
        $daily = intdiv($weeklyMinutes, count(self::WORKDAYS));
        $remainder = $weeklyMinutes % count(self::WORKDAYS);
        $pattern = [];
        for ($day = 1; $day <= 7; ++$day) {
            $pattern[$day] = in_array($day, self::WORKDAYS, true)
                ? $daily + ($day <= $remainder ? 1 : 0)
                : 0;
        }

        return $pattern;
    }

    private static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }

    private static function period(string $periodStart): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($date === false || $date->format('Y-m-d') !== $periodStart || $date->format('d') !== '01') {
            throw new \InvalidArgumentException('Období musí začínat prvním dnem měsíce (RRRR-MM-01).');
        }

        return $date->format('Y-m');
    }

    /** @return array{created:bool,issue:?string,skipped:?string,calendar_id:?int,fund_minutes:?int} */
    private static function result(bool $created, ?string $issue, ?string $skipped, ?int $calendarId, ?int $fund): array
    {
        return [
            'created' => $created,
            'issue' => $issue,
            'skipped' => $skipped,
            'calendar_id' => $calendarId,
            'fund_minutes' => $fund,
        ];
    }
}
