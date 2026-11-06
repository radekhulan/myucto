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
 *    měsíce; u zvoleného ručního zdroje přesné jednotky schválené aktuální revize
 *    bez závislosti na zaokrouhlené projekci JMHZ,
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
    public const STATUS_MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    /** Stavy, které dostane klient (PayrollAgreementAnnualHoursStatus). */
    public const STATUSES = [self::STATUS_OK, self::STATUS_APPROACHING, self::STATUS_EXCEEDED, self::STATUS_MANUAL_REVIEW_REQUIRED];

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
     * @param array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,manual_review_required?:bool,total_duration_units?:?int} $hours
     * @return array<string,mixed>
     */
    public static function summary(int $year, array $hours): array
    {
        $total = $hours['total_minutes'];
        $manualReview = $hours['manual_review_required'] ?? false;
        $hasExact = array_key_exists('total_duration_units', $hours);
        $units = $hasExact ? $hours['total_duration_units'] : null;

        return [
            'year' => $year,
            'total_minutes' => $manualReview ? null : $total,
            'takeover_minutes' => $hours['takeover_minutes'],
            'limit_minutes' => self::LIMIT_MINUTES,
            'warning_minutes' => self::WARNING_MINUTES,
            'remaining_minutes' => $manualReview ? null : ($hasExact ? PayrollDuration::exactMinutes(max(0, PayrollDuration::fromMinutes(self::LIMIT_MINUTES) - $units)) : max(0, self::LIMIT_MINUTES - $total)),
            'status' => $manualReview ? self::STATUS_MANUAL_REVIEW_REQUIRED : ($hasExact ? self::statusUnits($units) : self::status($total)),
            ...($hasExact ? [
                'total_duration_units' => $manualReview ? null : $units,
                'remaining_duration_units' => $manualReview ? null : max(0, PayrollDuration::fromMinutes(self::LIMIT_MINUTES) - $units),
            ] : []),
        ];
    }

    /**
     * Přehled za celý kalendářní rok po osobách (seznam lidí a karta osoby).
     * Dávkové dotazy bez ohledu na počet osob.
     *
     * @param list<int> $employeeIds
     * @return array<int,array{year:int,total_minutes:?int,takeover_minutes:int,limit_minutes:int,warning_minutes:int,remaining_minutes:?int,status:string}>
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
     * @return array<int,array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,own_duration_units?:?int,total_duration_units?:?int,manual_review_required?:bool}>
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
     * @return array<int,array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,own_duration_units?:?int,total_duration_units?:?int,manual_review_required?:bool}>
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
     * @return array<int,array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,own_duration_units?:?int,total_duration_units?:?int,manual_review_required?:bool}>
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
                AND month_row.work_source <> 'manual_summary'
              GROUP BY employment.employee_id, month_row.period_start",
        );
        $own->execute([$supplierId, ...$employeeIds, $yearStart, $periodStart, ...$exclusionParams]);

        $result = self::combine($employeeIds, $takeoverRows, $own->fetchAll(PDO::FETCH_ASSOC));
        return self::withManualMonths(
            $result,
            (new PayrollAgreementManualHours($this->db))->forEmployees($supplierId, $employeeIds, $yearStart, $periodStart, $excludedMonth, $excludedEmploymentIds),
            $takeoverRows,
        );
    }

    /**
     * Převzaté a vlastní hodiny po osobách. Převod z jiného programu zakládá
     * pracovní souhrn i za převzaté měsíce; měsíc s převzatými hodinami se proto
     * z vlastní evidence nepočítá podruhé.
     *
     * @param list<int> $employeeIds
     * @param list<array<string,mixed>> $takeoverRows `employee_id`, `period_start`, `minutes`
     * @param list<array<string,mixed>> $ownRows `employee_id`, `period_start`, `millihours`
     * @return array<int,array{own_minutes:?int,takeover_minutes:int,total_minutes:?int,own_duration_units?:?int,total_duration_units?:?int,manual_review_required?:bool}>
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

    /** Přesné porovnání, i když roční součet překročí limit jen o tisícinu hodiny. */
    public static function statusUnits(int $units): string
    {
        return match (true) {
            $units > PayrollDuration::fromMinutes(self::LIMIT_MINUTES) => self::STATUS_EXCEEDED,
            $units >= PayrollDuration::fromMinutes(self::WARNING_MINUTES) => self::STATUS_APPROACHING,
            default => self::STATUS_OK,
        };
    }

    /** @param array<int,array<string,mixed>> $prior @param list<array<string,mixed>> $manual @param list<array<string,mixed>> $takeoverRows @return array<int,array<string,mixed>> */
    public static function withManualMonths(array $prior, array $manual, array $takeoverRows): array
    {
        $takenOver = [];
        foreach ($takeoverRows as $row) $takenOver[(int) $row['employee_id']][(string) $row['period_start']] = true;
        $own = [];
        $unknown = [];
        foreach ($manual as $month) {
            $id = (int) $month['employee_id'];
            if (isset($takenOver[$id][(string) $month['period_start']])) continue;
            $own[$id] ??= PayrollDuration::fromMinutes($prior[$id]['own_minutes']);
            if ($month['worked_duration_units'] === null) { $unknown[$id] = true; continue; }
            $own[$id] = PayrollDuration::add($own[$id], $month['worked_duration_units']);
        }
        foreach ($own as $id => $units) {
            $total = PayrollDuration::add($units, PayrollDuration::fromMinutes($prior[$id]['takeover_minutes']));
            $prior[$id]['own_duration_units'] = isset($unknown[$id]) ? null : $units;
            $prior[$id]['total_duration_units'] = isset($unknown[$id]) ? null : $total;
            $prior[$id]['own_minutes'] = isset($unknown[$id]) ? null : PayrollDuration::exactMinutes($units);
            $prior[$id]['total_minutes'] = isset($unknown[$id]) ? null : PayrollDuration::exactMinutes($total);
            if (isset($unknown[$id])) $prior[$id]['manual_review_required'] = true;
        }
        return $prior;
    }

    /** Import přidává celé minuty, ale nesmí zahodit již doložený zlomek jiné části roku. */
    public static function addOwnMinutes(array $hours, int $minutes): array
    {
        if (array_key_exists('total_duration_units', $hours)) {
            if ($hours['manual_review_required'] ?? false) return $hours;
            $extra = PayrollDuration::fromMinutes($minutes);
            $hours['own_duration_units'] = PayrollDuration::add($hours['own_duration_units'], $extra);
            $hours['total_duration_units'] = PayrollDuration::add($hours['total_duration_units'], $extra);
            $hours['own_minutes'] = PayrollDuration::exactMinutes($hours['own_duration_units']);
            $hours['total_minutes'] = PayrollDuration::exactMinutes($hours['total_duration_units']);
        } else {
            $hours['own_minutes'] += $minutes;
            $hours['total_minutes'] += $minutes;
        }
        return $hours;
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
            $status = self::summary((int) substr($periodStart, 0, 4), $hours[$employeeId])['status'];
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
                    $hours[$employeeId]['total_duration_units'] ?? null,
                ),
                $status === self::STATUS_MANUAL_REVIEW_REQUIRED
                    ? '/payroll/quick-inputs?period=' . substr($periodStart, 0, 7) . '&employment=' . $employmentId . '&hours=1'
                    : "/payroll/people?person={$employeeId}&employment={$employmentId}&panel=employment_terms",
            );
        }

        return $validations;
    }

    public static function validationCode(string $status): string
    {
        if ($status === self::STATUS_MANUAL_REVIEW_REQUIRED) return 'dpp_annual_hours_manual_review_required';
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
        ?int $totalDurationUnits = null,
    ): string {
        return match ($status) {
            self::STATUS_MANUAL_REVIEW_REQUIRED => 'Roční limit DPP nelze vyhodnotit: ruční podklad je neúplný nebo neschválený. Ověřte odpracované hodiny podle podkladů.',
            self::STATUS_EXCEEDED => self::message($name, $year, $totalMinutes, $takeoverMinutes, $totalDurationUnits),
            self::STATUS_APPROACHING => self::approachingMessage($name, $year, $totalMinutes, $takeoverMinutes, $totalDurationUnits),
            default => '',
        };
    }

    public static function approachingMessage(string $name, int $year, int $totalMinutes, int $takeoverMinutes, ?int $totalDurationUnits = null): string
    {
        return sprintf(
            '%s: na dohodách o provedení práce má v roce %d odpracováno %s h%s z 300 h, které § 75 zákoníku '
                . 'práce u téhož zaměstnavatele dovoluje (práh upozornění %d %%). Zbývá %s h. Naplánujte '
                . 'další práci tak, aby limit nepřekročil(a), nebo včas upravte smluvní vztah v kartě zaměstnance.',
            $name === '' ? 'Zaměstnanec' : $name,
            $year,
            $totalDurationUnits === null ? self::hours($totalMinutes) : PayrollAgreementManualHours::hours($totalDurationUnits),
            $takeoverMinutes > 0
                ? sprintf(' (z toho %s h převzatých z předchozího programu)', self::hours($takeoverMinutes))
                : '',
            self::WARNING_PERCENT,
            $totalDurationUnits === null ? self::hours(max(0, self::LIMIT_MINUTES - $totalMinutes)) : PayrollAgreementManualHours::hours(max(0, PayrollDuration::fromMinutes(self::LIMIT_MINUTES) - $totalDurationUnits)),
        );
    }

    public static function message(string $name, int $year, int $totalMinutes, int $takeoverMinutes, ?int $totalDurationUnits = null): string
    {
        return sprintf(
            '%s: na dohodách o provedení práce odpracoval(a) v roce %d celkem %s h%s — víc než 300 h, '
                . 'které § 75 zákoníku práce u téhož zaměstnavatele dovoluje. Práce nad limit už nejde '
                . 'vykonávat na DPP; upravte smluvní vztah (dohoda o pracovní činnosti nebo pracovní poměr) '
                . 'v kartě zaměstnance.',
            $name === '' ? 'Zaměstnanec' : $name,
            $year,
            $totalDurationUnits === null ? self::hours($totalMinutes) : PayrollAgreementManualHours::hours($totalDurationUnits),
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
