<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Podklad pro nárok na osvobozený příspěvek na stravování — SUROVÁ DATA.
 *
 * Čte se z evidence, kterou modul už má (MZ-06): publikované směny, schválený
 * odpracovaný čas, stav uzavření docházky a pracovní cesty. Druhý zdroj pravdy
 * o tom, kolik kdo odpracoval směn, se ZÁMĚRNĚ nezakládá — jinak by výplatní
 * páska a docházka mohly říkat každá něco jiného.
 *
 * Intervaly se vracejí tak, jak jsou uložené: instant v UTC plus původní IANA
 * timezone. Zařazení do kalendářního měsíce a do dne se dělá až v místní zóně
 * ({@see \MyInvoice\Service\Payroll\Component\PayrollMealShiftEvidenceService}),
 * protože „směna" i „kalendářní den" jsou v zákoně místní pojmy.
 */
final class PayrollMealShiftEvidenceRepository
{
    /** Kolik dní kolem měsíce se načítá, aby se posun zóny nemohl useknout. */
    private const WINDOW_DAYS = 2;

    public function __construct(private readonly Connection $db) {}

    /**
     * Pracovní vztahy osoby u zaměstnavatele a stav uzavření jejich docházky.
     *
     * `month_status` je NULL, když měsíc docházky vůbec nevznikl — to není totéž
     * jako otevřený měsíc, ale pro doloženost nároku je následek stejný.
     *
     * @return list<array{employment_id:int, meal_entitlement_basis:string, month_status:?string}>
     */
    public function employments(int $supplierId, int $employeeId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.meal_entitlement_basis,
                    month.status AS month_status
               FROM payroll_employments employment
               LEFT JOIN payroll_time_months month
                 ON month.supplier_id = employment.supplier_id
                AND month.employment_id = employment.id
                AND month.period_start = ?
              WHERE employment.supplier_id = ?
                AND employment.employee_id = ?
                AND employment.status IN ("active", "suspended", "ended")
                AND COALESCE(employment.actual_start_date, employment.start_date, ?) < DATE_ADD(?, INTERVAL 1 MONTH)
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employment.id'
        );
        $stmt->execute([
            $periodStart,
            $supplierId,
            $employeeId,
            $periodStart,
            $periodStart,
            $periodStart,
        ]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'meal_entitlement_basis' => (string) $row['meal_entitlement_basis'],
                'month_status' => $row['month_status'] === null
                    ? null
                    : (string) $row['month_status'],
            ];
        }

        return $rows;
    }

    /**
     * Publikované směny okolo období.
     *
     * `superseded` ani `draft` se nepočítají: nárok stojí na tom, co bylo
     * zaměstnanci opravdu rozvrženo.
     *
     * @param list<int> $employmentIds
     * @return list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}>
     */
    public function shifts(int $supplierId, array $employmentIds, string $periodStart): array
    {
        return $this->intervals(
            'SELECT employment_id, starts_at_utc, ends_at_utc, timezone_name, break_minutes
               FROM payroll_shifts
              WHERE supplier_id = ?
                AND status = "published"
                AND employment_id IN (%s)
                AND starts_at_utc >= ? AND starts_at_utc < ?',
            $supplierId,
            $employmentIds,
            $periodStart,
        );
    }

    /**
     * Schválený odpracovaný čas okolo období.
     *
     * Koncept se nepočítá: § 6 odst. 9 písm. b) ZDP mluví o tom, že zaměstnanec
     * práci VYKONÁVAL, a neschválená evidence to netvrdí.
     *
     * @param list<int> $employmentIds
     * @return list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}>
     */
    public function workedIntervals(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        return $this->intervals(
            'SELECT employment_id, starts_at_utc, ends_at_utc, timezone_name, break_minutes
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND status = "approved"
                AND employment_id IN (%s)
                AND starts_at_utc >= ? AND starts_at_utc < ?',
            $supplierId,
            $employmentIds,
            $periodStart,
        );
    }

    /**
     * Pracovní cesty s nárokem na stravné okolo období.
     *
     * Bere se jen cesta se SPOČÍTANÝM nárokem (`entitlement_total_minor > 0`)
     * a ve stavu, který už není koncept: zákon vylučuje směnu, ve které nárok na
     * stravné VZNIKL, ne tu, u které si někdo cestu rozepsal.
     *
     * Cesta nese od migrace 1518 pravý UTC instant plus svou IANA zónu, stejně
     * jako směna — do vyloučení směny jde tedy TÝŽ druh hodnoty na obou stranách.
     * Dřív se tu vracel holý místní čas označený za UTC a vyloučení se trefovalo
     * o 1 (SEČ) až 2 (SELČ) hodiny vedle.
     *
     * @param list<int> $employmentIds
     * @return list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string}>
     */
    public function mealAllowanceTrips(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        [$from, $to] = $this->window($periodStart);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_id, departure_at_utc, arrival_at_utc, timezone_name
               FROM payroll_business_trips
              WHERE supplier_id = ?
                AND status IN ("approved", "settled")
                AND entitlement_total_minor > 0
                AND employment_id IN (' . $placeholders . ')
                AND arrival_at_utc >= ? AND departure_at_utc < ?'
        );
        $stmt->execute([$supplierId, ...$employmentIds, $from, $to]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'starts_at_utc' => (string) $row['departure_at_utc'],
                'ends_at_utc' => (string) $row['arrival_at_utc'],
                'timezone_name' => (string) $row['timezone_name'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $employmentIds
     * @return list<array{employment_id:int, starts_at_utc:string, ends_at_utc:string, timezone_name:string, break_minutes:int}>
     */
    private function intervals(
        string $sql,
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        [$from, $to] = $this->window($periodStart);
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(sprintf($sql, $placeholders));
        $stmt->execute([$supplierId, ...$employmentIds, $from, $to]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'starts_at_utc' => (string) $row['starts_at_utc'],
                'ends_at_utc' => (string) $row['ends_at_utc'],
                'timezone_name' => (string) $row['timezone_name'],
                'break_minutes' => (int) $row['break_minutes'],
            ];
        }

        return $rows;
    }

    /** @return array{0:string,1:string} */
    private function window(string $periodStart): array
    {
        $start = new \DateTimeImmutable($periodStart . ' 00:00:00', new \DateTimeZone('UTC'));

        return [
            $start->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d H:i:s'),
            $start->modify('+1 month')
                ->modify('+' . self::WINDOW_DAYS . ' days')
                ->format('Y-m-d H:i:s'),
        ];
    }
}
