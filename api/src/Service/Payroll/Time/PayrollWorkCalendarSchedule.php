<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Kolik by zaměstnanec podle rozvrhu v daný den odpracoval.
 *
 * Odpověď na tuhle otázku potřebují dvě různá místa (svátek v absenci podle
 * § 219 a § 192 ZP, svátek jako výkon práce pro nárok na dovolenou podle § 348
 * odst. 1 písm. d) ZP) a musí ji dostat stejnou — jinak by tentýž svátek jednou
 * dovolenou nespotřeboval o osm hodin a podruhé do nároku přidal o šest.
 *
 * Zdrojem je týdenní rozvrh kalendáře, ne `payroll_calendar_days`: fond pracovní
 * doby ukládá svátku plánovaných nula minut právě proto, že se ve svátek
 * nepracuje, takže délku odpadlé směny drží jen rozvrh. Kde rozvrh není, vrací
 * se pro daný den nula a volající den vynechá — vymyslet si směnu je horší než
 * ji přiznat až po doplnění kalendáře.
 */
final class PayrollWorkCalendarSchedule
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<string> $dates ISO data (Y-m-d)
     * @return array<string,int> datum => plánované minuty (jen kladné)
     */
    public function plannedMinutes(int $supplierId, int $employmentId, array $dates): array
    {
        if ($dates === [] || !$this->db->hasTable('payroll_work_calendars')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT week_pattern
               FROM payroll_work_calendars
              WHERE supplier_id = ? AND employment_id = ?
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 1'
        );
        $minutes = [];
        foreach ($dates as $date) {
            $stmt->execute([$supplierId, $employmentId, $date, $date]);
            $pattern = $stmt->fetchColumn();
            $stmt->closeCursor();
            if (!is_string($pattern)) {
                continue;
            }
            $decoded = json_decode($pattern, true);
            if (!is_array($decoded)) {
                continue;
            }
            $weekday = (int) (new \DateTimeImmutable($date))->format('N');
            $planned = $decoded[$weekday] ?? 0;
            if (!is_int($planned) || $planned <= 0) {
                continue;
            }
            $minutes[$date] = $planned;
        }

        return $minutes;
    }

    /**
     * Svátky v uzavřeném rozsahu dat.
     *
     * @return array<string,array{code:string,name:string}>
     */
    public static function holidaysBetween(
        CzechHolidayCalendar $calendar,
        string $from,
        string $to,
    ): array {
        if ($to < $from) {
            return [];
        }
        $holidays = [];
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach ($calendar->forYear($year) as $date => $holiday) {
                if ($date >= $from && $date <= $to) {
                    $holidays[$date] = $holiday;
                }
            }
        }
        ksort($holidays);

        return $holidays;
    }
}
