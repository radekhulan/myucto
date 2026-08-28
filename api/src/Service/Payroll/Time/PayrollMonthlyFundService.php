<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use PDO;

/**
 * Fond pracovní doby jednoho vztahu za jeden měsíc, v minutách.
 *
 * Táž skládačka, kterou dělá {@see PayrollTimeService::month()} při stavbě
 * přehledu docházky: verze pracovního kalendáře se v měsíci mohou střídat,
 * takže se fond neskládá z jedné verze, ale den po dni z té verze, která je
 * ten den účinná; výjimky konkrétního dne (`payroll_calendar_days`) přebíjejí
 * týdenní vzor.
 *
 * Vlastní služba proto, že fond nepotřebuje jen přehled docházky. Potřebuje ho
 * i dosažená mzda za práci přesčas podle § 114 odst. 1
 * ({@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollAchievedWage}), a to
 * z místa, které si celý měsíční přehled dovolit nemůže — rychlé zadání
 * ukládá desítky vztahů najednou. Kdyby si každý volající fond skládal sám,
 * rozešly by se dvě čísla, která se z definice rozejít nesmějí.
 */
final class PayrollMonthlyFundService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollCalendarFundService $fund,
    ) {}

    /**
     * `null` = vztah nemá pro období pracovní kalendář.
     *
     * `null` se ZÁMĚRNĚ neplete s nulou: nula znamená „kalendář existuje a
     * neplánuje ani minutu" (celoměsíční neplacené volno), kdežto `null`
     * znamená „nevím". Volající, který z fondu dělí, musí obě rozlišit —
     * z „nevím" se nesmí stát číslo.
     */
    public function minutes(int $supplierId, int $employmentId, string $period): ?int
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if ($start === false || $start->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('period musí být ve formátu YYYY-MM.');
        }
        $periodStart = $start->format('Y-m-d');
        $periodEnd = $start->modify('first day of next month')->format('Y-m-d');

        $versions = $this->calendars($supplierId, $employmentId, $periodStart, $periodEnd);
        if ($versions === []) {
            return null;
        }

        $combined = [];
        foreach ($versions as $version) {
            $calendarId = PayrollTimeValue::int($version['id'] ?? null, 'calendar_id');
            $month = $this->fund->month(
                $period,
                $version['week_pattern'],
                $this->calendarDays($supplierId, $calendarId, $periodStart, $periodEnd),
            );
            $validFrom = PayrollTimeValue::string($version['valid_from'] ?? null, 'valid_from');
            $validTo = $version['valid_to'] === null
                ? null
                : PayrollTimeValue::string($version['valid_to'], 'valid_to');
            foreach ($month['days'] as $day) {
                $date = PayrollTimeValue::string($day['date'] ?? null, 'date');
                if ($date < $validFrom || ($validTo !== null && $date > $validTo)) {
                    continue;
                }
                $combined[$date] = PayrollTimeValue::int(
                    $day['planned_minutes'] ?? null,
                    'planned_minutes',
                );
            }
        }

        return array_sum($combined);
    }

    /** @return list<array{id:int,valid_from:string,valid_to:?string,week_pattern:array<int,int>}> */
    private function calendars(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to, week_pattern
               FROM payroll_work_calendars
              WHERE supplier_id = ?
                AND employment_id = ?
                AND valid_from < ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from, id'
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => PayrollTimeValue::int($row['id'] ?? null, 'calendar_id'),
                'valid_from' => PayrollTimeValue::string($row['valid_from'] ?? null, 'valid_from'),
                'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
                'week_pattern' => self::weekPattern($row['week_pattern'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function calendarDays(
        int $supplierId,
        int $calendarId,
        string $periodStart,
        string $periodEnd,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_calendar_days
              WHERE supplier_id = ? AND calendar_id = ?
                AND day_date >= ? AND day_date < ?
              ORDER BY day_date'
        );
        $stmt->execute([$supplierId, $calendarId, $periodStart, $periodEnd]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_calendar_days',
        );
    }

    /** @return array<int,int> */
    private static function weekPattern(mixed $raw): array
    {
        if (!is_string($raw)) {
            throw new \UnexpectedValueException('week_pattern musí být JSON.');
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('week_pattern musí být objekt.');
        }
        $pattern = [];
        foreach ($decoded as $day => $minutes) {
            $pattern[(int) $day] = (int) $minutes;
        }

        return $pattern;
    }
}
