<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use PDO;

/**
 * Roční rozsah dohod o provedení práce u téhož zaměstnavatele (§ 75 ZP).
 *
 * „Rozsah práce, na který se dohoda o provedení práce uzavírá, nesmí být vyšší
 * než 300 hodin v kalendářním roce. Do rozsahu práce se započítává také doba
 * práce konané zaměstnancem pro zaměstnavatele v témže kalendářním roce na
 * základě jiné dohody o provedení práce." (§ 75 zákona č. 262/2006 Sb.)
 *
 * Limit se dosud nehlídal vůbec. U firmy, která přešla na MyÚčto v průběhu
 * roku, navíc část odpracovaných hodin leží jen v převzatých mzdách
 * (`payroll_migration_reference_totals.worked_minutes`) — u dohod je to podle
 * migrace 1851 jediná měřitelná veličina. Počítá se proto:
 *
 *  - vlastní evidence MyÚčta: potvrzené odpracované hodiny pracovního souhrnu
 *    měsíce (táž hodnota, která odchází do měsíčního hlášení),
 *  - převzaté hodiny za měsíce před začátkem vedení mezd (hranici vykládá
 *    {@see PayrollHistoricalPeriodService::precedesStart()}).
 *
 * Součet je za OSOBU přes všechny její dohody o provedení práce u firmy.
 * Překročení je varování, ne závora: odpracovanou práci je potřeba zaplatit,
 * jen smluvní vztah přestal odpovídat zákonu a musí se upravit.
 *
 * Čistý SQL pomocník nad spojením snapshotu — stejně jako dávkový loader se
 * nedává do konstruktoru buildera, aby nepřibyl volitelný parametr, který by
 * DI tiše nevyplnilo.
 */
final class PayrollAgreementAnnualHours
{
    /** 300 hodin v minutách. */
    public const LIMIT_MINUTES = 300 * 60;

    /**
     * Podíl ročního limitu, od kterého se hlásí, že se osoba k limitu blíží.
     * Varování předem dává účetní čas upravit smluvní vztah dřív, než brigádník
     * limit překročí a práce nad něj už na DPP nepůjde.
     */
    public const WARNING_PERCENT = 80;

    /** 240 hodin v minutách — práh varování „blíží se limitu". */
    public const WARNING_MINUTES = self::LIMIT_MINUTES * self::WARNING_PERCENT / 100;

    public const STATUS_OK = 'ok';
    public const STATUS_APPROACHING = 'approaching';
    public const STATUS_EXCEEDED = 'exceeded';

    /** Stavy, které dostane klient (PayrollAgreementAnnualHoursStatus). */
    public const STATUSES = [self::STATUS_OK, self::STATUS_APPROACHING, self::STATUS_EXCEEDED];

    public function __construct(private readonly Connection $db) {}

    /**
     * Zařazení ročního součtu: přesně 300 h je ještě v limitu, varování předem
     * platí od prahu včetně.
     */
    public static function status(int $totalMinutes): string
    {
        return match (true) {
            $totalMinutes > self::LIMIT_MINUTES => self::STATUS_EXCEEDED,
            $totalMinutes >= self::WARNING_MINUTES => self::STATUS_APPROACHING,
            default => self::STATUS_OK,
        };
    }

    /**
     * @param array{own_minutes:int,takeover_minutes:int,total_minutes:int} $hours
     * @return array{year:int,total_minutes:int,takeover_minutes:int,limit_minutes:int,warning_minutes:int,remaining_minutes:int,status:string}
     */
    public static function summary(int $year, array $hours): array
    {
        $total = $hours['total_minutes'];

        return [
            'year' => $year,
            'total_minutes' => $total,
            'takeover_minutes' => $hours['takeover_minutes'],
            'limit_minutes' => self::LIMIT_MINUTES,
            'warning_minutes' => self::WARNING_MINUTES,
            'remaining_minutes' => max(0, self::LIMIT_MINUTES - $total),
            'status' => self::status($total),
        ];
    }

    /**
     * Přehled za celý kalendářní rok po osobách (seznam lidí a karta osoby).
     * Dva dávkové dotazy bez ohledu na počet osob.
     *
     * @param list<int> $employeeIds
     * @return array<int,array{year:int,total_minutes:int,takeover_minutes:int,limit_minutes:int,warning_minutes:int,remaining_minutes:int,status:string}>
     */
    public function yearSummaries(int $supplierId, array $employeeIds, int $year): array
    {
        $result = [];
        foreach ($this->yearToDate($supplierId, $employeeIds, sprintf('%04d-12-01', $year)) as $employeeId => $hours) {
            $result[$employeeId] = self::summary($year, $hours);
        }

        return $result;
    }

    /**
     * Osoby s dohodou o provedení práce, které v roce dosáhly prahu varování
     * nebo limit překročily. Součet se bere z {@see self::yearToDate()}, aby
     * filtr seznamu nemohl tvrdit něco jiného než značka u osoby.
     *
     * @return list<int>
     */
    public function employeesNearLimit(int $supplierId, int $year): array
    {
        // Jen dohody, které v roce trvaly; archiv starších let do součtu nic nepřinese.
        $statement = $this->db->pdo()->prepare(
            "SELECT DISTINCT employee_id
               FROM payroll_employments
              WHERE supplier_id = ?
                AND relation_type = 'dpp'
                AND COALESCE(actual_start_date, start_date) <= ?
                AND (end_date IS NULL OR end_date >= ?)",
        );
        $statement->execute([$supplierId, sprintf('%04d-12-31', $year), sprintf('%04d-01-01', $year)]);
        $employeeIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $result = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            foreach ($this->yearSummaries($supplierId, $chunk, $year) as $employeeId => $summary) {
                if ($summary['status'] !== self::STATUS_OK) {
                    $result[] = $employeeId;
                }
            }
        }

        return $result;
    }

    /**
     * Odpracované minuty na DPP za rok do konce `$periodStart` včetně, podle osob.
     *
     * @param list<int> $employeeIds
     * @return array<int,array{own_minutes:int,takeover_minutes:int,total_minutes:int}>
     */
    public function yearToDate(int $supplierId, array $employeeIds, string $periodStart): array
    {
        return $this->workedMinutes($supplierId, $employeeIds, $periodStart, null, []);
    }

    /**
     * Odpracované minuty na DPP za CELÝ kalendářní rok měsíce `$periodStart`
     * bez uloženého pracovního souhrnu vztahů `$employmentIds` za tento měsíc.
     *
     * Pro náhled importu docházky: dávka souhrn těchto vztahů za měsíc teprve
     * zapíše nebo přepíše, takže se k výsledku přičtou importované hodiny.
     * Pozdější měsíce roku (import staršího měsíce) i souhrn jiné dohody téže
     * osoby za týž měsíc se počítají, jak je evidence vede.
     *
     * @param list<int> $employeeIds
     * @param list<int> $employmentIds
     * @return array<int,array{own_minutes:int,takeover_minutes:int,total_minutes:int}>
     */
    public function yearWithoutMonth(int $supplierId, array $employeeIds, string $periodStart, array $employmentIds): array
    {
        return $this->workedMinutes(
            $supplierId,
            $employeeIds,
            sprintf('%04d-12-01', (int) substr($periodStart, 0, 4)),
            $periodStart,
            $employmentIds,
        );
    }

    /**
     * @param list<int> $employeeIds
     * @param list<int> $excludedEmploymentIds
     * @return array<int,array{own_minutes:int,takeover_minutes:int,total_minutes:int}>
     */
    private function workedMinutes(
        int $supplierId,
        array $employeeIds,
        string $periodStart,
        ?string $excludedMonth,
        array $excludedEmploymentIds,
    ): array {
        if ($employeeIds === []) {
            return [];
        }
        $year = (int) substr($periodStart, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);
        $in = implode(', ', array_fill(0, count($employeeIds), '?'));
        $exclusion = '';
        $exclusionParams = [];
        if ($excludedMonth !== null && $excludedEmploymentIds !== []) {
            $exclusion = ' AND NOT (month_row.period_start = ? AND month_row.employment_id IN ('
                . implode(', ', array_fill(0, count($excludedEmploymentIds), '?')) . '))';
            $exclusionParams = [$excludedMonth, ...$excludedEmploymentIds];
        }

        // Převzaté hodiny za měsíce před začátkem vedení mezd v MyÚčtu.
        $startPeriod = $this->startPeriod($supplierId);
        $takeover = $this->db->pdo()->prepare(
            "SELECT totals.employee_id, totals.period_start, SUM(totals.worked_minutes) AS minutes
               FROM payroll_migration_reference_totals totals
               JOIN payroll_employments employment
                 ON employment.supplier_id = totals.supplier_id
                AND employment.id = totals.employment_id
              WHERE totals.supplier_id = ?
                AND employment.relation_type = 'dpp'
                AND totals.employee_id IN ({$in})
                AND totals.period_start >= ? AND totals.period_start <= ?
              GROUP BY totals.employee_id, totals.period_start",
        );
        $takeover->execute([$supplierId, ...$employeeIds, $yearStart, $periodStart]);
        $takeoverRows = array_values(array_filter(
            $takeover->fetchAll(PDO::FETCH_ASSOC),
            static fn (array $row): bool => PayrollHistoricalPeriodService::precedesStart(
                $startPeriod,
                (string) $row['period_start'],
            ),
        ));

        // Vlastní evidence: aktuální revize pracovního souhrnu každého měsíce.
        $own = $this->db->pdo()->prepare(
            "SELECT employment.employee_id, month_row.period_start,
                    SUM(summary.worked_millihours) AS millihours
               FROM payroll_time_months month_row
               JOIN payroll_jmhz_work_month_revisions summary
                 ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
               JOIN payroll_employments employment
                 ON employment.supplier_id = month_row.supplier_id
                AND employment.id = month_row.employment_id
              WHERE month_row.supplier_id = ?
                AND employment.relation_type = 'dpp'
                AND employment.employee_id IN ({$in})
                AND month_row.period_start >= ? AND month_row.period_start <= ?{$exclusion}
              GROUP BY employment.employee_id, month_row.period_start",
        );
        $own->execute([$supplierId, ...$employeeIds, $yearStart, $periodStart, ...$exclusionParams]);

        return self::combine($employeeIds, $takeoverRows, $own->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Převzaté a vlastní hodiny po osobách. Převod z jiného programu zakládá
     * pracovní souhrn i za převzaté měsíce; měsíc s převzatými hodinami se proto
     * z vlastní evidence nepočítá podruhé.
     *
     * @param list<int> $employeeIds
     * @param list<array<string,mixed>> $takeoverRows `employee_id`, `period_start`, `minutes`
     * @param list<array<string,mixed>> $ownRows `employee_id`, `period_start`, `millihours`
     * @return array<int,array{own_minutes:int,takeover_minutes:int,total_minutes:int}>
     */
    public static function combine(array $employeeIds, array $takeoverRows, array $ownRows): array
    {
        $result = [];
        foreach ($employeeIds as $employeeId) {
            $result[$employeeId] = ['own_minutes' => 0, 'takeover_minutes' => 0, 'total_minutes' => 0];
        }
        $takenOverMonths = [];
        foreach ($takeoverRows as $row) {
            $employeeId = (int) $row['employee_id'];
            $result[$employeeId]['takeover_minutes'] += (int) $row['minutes'];
            $takenOverMonths[$employeeId][substr((string) $row['period_start'], 0, 7)] = true;
        }
        foreach ($ownRows as $row) {
            $employeeId = (int) $row['employee_id'];
            if (isset($takenOverMonths[$employeeId][substr((string) $row['period_start'], 0, 7)])) {
                continue;
            }
            $result[$employeeId]['own_minutes'] += intdiv((int) $row['millihours'] * 60, 1000);
        }
        foreach ($result as $employeeId => $row) {
            $result[$employeeId]['total_minutes'] = $row['own_minutes'] + $row['takeover_minutes'];
        }

        return $result;
    }

    /**
     * Varování pro DPP vztahy běhu, jejichž osoba limit v roce překročila
     * (`dpp_annual_hours_exceeded`) nebo dosáhla prahu {@see self::WARNING_MINUTES}
     * (`dpp_annual_hours_approaching`). Ani jedno výpočet nezastaví.
     *
     * @param list<array<string,mixed>> $employments řádky běhu s `employment_id`,
     *        `employee_id`, `relation_type` a `full_name`
     * @return list<PayrollRunValidation>
     */
    public function validations(int $supplierId, array $employments, string $periodStart): array
    {
        $agreements = array_values(array_filter(
            $employments,
            static fn (array $row): bool => ($row['relation_type'] ?? null) === 'dpp',
        ));
        if ($agreements === []) {
            return [];
        }
        $employeeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $agreements,
        )));
        $hours = $this->yearToDate($supplierId, $employeeIds, $periodStart);
        $validations = [];
        foreach ($agreements as $row) {
            $employeeId = (int) $row['employee_id'];
            $employmentId = (int) $row['employment_id'];
            $total = $hours[$employeeId]['total_minutes'] ?? 0;
            $status = self::status($total);
            if ($status === self::STATUS_OK) {
                continue;
            }
            $validations[] = new PayrollRunValidation(
                'warning',
                self::validationCode($status),
                'employment',
                $employmentId,
                self::statusMessage(
                    $status,
                    (string) ($row['full_name'] ?? ''),
                    (int) substr($periodStart, 0, 4),
                    $total,
                    $hours[$employeeId]['takeover_minutes'] ?? 0,
                ),
                "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms",
            );
        }

        return $validations;
    }

    public static function validationCode(string $status): string
    {
        return $status === self::STATUS_EXCEEDED
            ? 'dpp_annual_hours_exceeded'
            : 'dpp_annual_hours_approaching';
    }

    /** Text nálezu podle zařazení; pro `ok` prázdný řetězec. */
    public static function statusMessage(
        string $status,
        string $name,
        int $year,
        int $totalMinutes,
        int $takeoverMinutes,
    ): string {
        return match ($status) {
            self::STATUS_EXCEEDED => self::message($name, $year, $totalMinutes, $takeoverMinutes),
            self::STATUS_APPROACHING => self::approachingMessage($name, $year, $totalMinutes, $takeoverMinutes),
            default => '',
        };
    }

    public static function approachingMessage(string $name, int $year, int $totalMinutes, int $takeoverMinutes): string
    {
        return sprintf(
            '%s: na dohodách o provedení práce má v roce %d odpracováno %s h%s z 300 h, které § 75 zákoníku '
                . 'práce u téhož zaměstnavatele dovoluje (práh upozornění %d %%). Zbývá %s h. Naplánujte '
                . 'další práci tak, aby limit nepřekročil(a), nebo včas upravte smluvní vztah v kartě zaměstnance.',
            $name === '' ? 'Zaměstnanec' : $name,
            $year,
            self::hours($totalMinutes),
            $takeoverMinutes > 0
                ? sprintf(' (z toho %s h převzatých z předchozího programu)', self::hours($takeoverMinutes))
                : '',
            self::WARNING_PERCENT,
            self::hours(max(0, self::LIMIT_MINUTES - $totalMinutes)),
        );
    }

    public static function message(string $name, int $year, int $totalMinutes, int $takeoverMinutes): string
    {
        return sprintf(
            '%s: na dohodách o provedení práce odpracoval(a) v roce %d celkem %s h%s — víc než 300 h, '
                . 'které § 75 zákoníku práce u téhož zaměstnavatele dovoluje. Práce nad limit už nejde '
                . 'vykonávat na DPP; upravte smluvní vztah (dohoda o pracovní činnosti nebo pracovní poměr) '
                . 'v kartě zaměstnance.',
            $name === '' ? 'Zaměstnanec' : $name,
            $year,
            self::hours($totalMinutes),
            $takeoverMinutes > 0
                ? sprintf(' (z toho %s h převzatých z předchozího programu)', self::hours($takeoverMinutes))
                : '',
        );
    }

    private static function hours(int $minutes): string
    {
        $whole = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? number_format($whole, 0, ',', ' ')
            : number_format($minutes / 60, 2, ',', ' ');
    }

    private function startPeriod(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
