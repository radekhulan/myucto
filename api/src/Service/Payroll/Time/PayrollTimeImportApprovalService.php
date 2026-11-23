<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;

/**
 * Hromadné schválení pracovních měsíců z dávky importu docházky.
 *
 * U stovky vztahů je schválení měsíce po jednom stovka stejných dialogů,
 * ve kterých účetní jen odklepne návrh. Tady se každý vztah dávky projde
 * stejnou cestou jako ruční schválení ({@see PayrollTimeRepository::approveMonth()}
 * s náhledem a potvrzením souhrnu JMHZ), jen s návrhy náhledu převzatými beze
 * změny a s proveniencí {@see PayrollJmhzWorkMonthSummaryBuilder::IMPORT_BULK_CONFIRMATION}.
 *
 * Schvaluje se JEN čistý měsíc. Vztah, u kterého by schválení něco tvrdilo bez
 * podkladu, se vrací jako výjimka k ručnímu zpracování:
 *
 * - hodiny nemoci, ošetřovného, otcovské, neplaceného či náhradního volna nebo
 *   neomluvené absence bez dat od–do (náhrady mzdy ani evidenční list se
 *   z měsíčního součtu hodin spočítat nedají),
 * - chybějící týdenní pracovní doba (bez ní není fond ani 10261),
 * - přesčas nad odpracované hodiny a jiné nálezy náhledu souhrnu,
 * - měsíc schválený z jiného zdroje a časové záznamy jako druhý zdroj.
 *
 * Výjimku z podkladů řeší účetní opravou v docházkovém systému a novým importem:
 * opravná dávka nahradí souhrn otevřeného měsíce novou revizí
 * ({@see PayrollTimeRepository::saveImportSummary()}) a čistý měsíc se schválí
 * při jejím zápisu. Schválený měsíc opravná dávka nemění.
 *
 * Rozdíl fondu z podkladů proti kalendáři a neuvedené odpracované dny jsou
 * jen varování: souhrn je pravdivý, jen neúplný tam, kde to hlášení dovoluje.
 *
 * Každý vztah jde ve vlastní transakci (repozitář si ji založí sám, uvnitř
 * cizí transakce savepoint), takže výjimka jednoho vztahu ostatní nezastaví.
 */
final class PayrollTimeImportApprovalService
{
    private const DATED_HOURS_LABELS = [
        'sick_hours' => 'nemoc',
        'care_hours' => 'ošetřovné',
        'paternity_hours' => 'otcovská',
        'unpaid_leave_hours' => 'neplacené volno',
        'unexcused_hours' => 'neomluvená absence',
        'compensatory_time_off_hours' => 'náhradní volno',
    ];

    private const CONDITIONAL_FIELDS = [
        'unworked_total_hours',
        'unworked_paid_hours',
        'dpn_without_employer_compensation_hours',
        'dpn_with_employer_compensation_hours',
        'vacation_hours',
        'care_hours',
        'employee_obstacle_paid_hours',
        'employer_obstacle_hours',
        'maternity_hours',
        'paternity_hours',
        'parental_hours',
        'unpaid_leave_hours',
        'unexcused_hours',
        'compensatory_time_off_hours',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAttendanceImportRepository $imports,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollTimeImportSummaryWriter $summaries,
        private readonly PayrollJmhzWorkMonthSummaryBuilder $workSummary,
    ) {}

    /**
     * Zapíše souhrny dávky (idempotentně) a čisté měsíce schválí.
     *
     * @return array{
     *   approved:int,
     *   already_approved:int,
     *   written:int,
     *   replayed:int,
     *   exceptions:list<array{employment_id:int,name:string,employment_code:string,code:string,message:string}>,
     *   warnings:list<array{employment_id:int,name:string,employment_code:string,code:string,message:string}>
     * }
     */
    public function applyBatch(int $supplierId, int $importId, bool $approveClean, ?int $userId): array
    {
        $this->batch($supplierId, $importId);

        return $this->approveWritten(
            $supplierId,
            $importId,
            $this->summaries->writeFromBatch($supplierId, $importId, $userId),
            $approveClean,
            $userId,
        );
    }

    /**
     * Totéž nad už provedeným zápisem souhrnů (import docházky ho dělá sám).
     *
     * @param array{written:int,replayed:int,calendars_created?:int,exceptions:list<array{employment_id:int,message:string}>,warnings:list<array{employment_id:int,code:string,message:string}>} $writeResult
     * @return array{
     *   approved:int,
     *   already_approved:int,
     *   written:int,
     *   replayed:int,
     *   exceptions:list<array{employment_id:int,name:string,employment_code:string,code:string,message:string}>,
     *   warnings:list<array{employment_id:int,name:string,employment_code:string,code:string,message:string}>
     * }
     */
    public function approveWritten(
        int $supplierId,
        int $importId,
        array $writeResult,
        bool $approveClean,
        ?int $userId,
    ): array {
        $batch = $this->batch($supplierId, $importId);
        $periodStart = $batch['period'] . '-01';
        $people = [];
        foreach ($this->imports->batchRows($supplierId, $importId) as $row) {
            if (!in_array((string) $row['meaning'], AttendanceMeaning::HOURS, true)
                || $row['quantity_millihours'] === null
            ) {
                continue;
            }
            $people[(int) $row['employment_id']] = [
                'name' => (string) ($row['employee_name'] ?? ''),
                'employment_code' => (string) ($row['employment_code'] ?? ''),
            ];
        }
        ksort($people);
        $writerExceptions = [];
        foreach ($writeResult['exceptions'] as $exception) {
            $writerExceptions[$exception['employment_id']] = $exception['message'];
        }

        $result = [
            'approved' => 0,
            'already_approved' => 0,
            'written' => $writeResult['written'],
            'replayed' => $writeResult['replayed'],
            'exceptions' => [],
            'warnings' => [],
        ];
        $item = static fn (int $employmentId, string $code, string $message): array => [
            'employment_id' => $employmentId,
            'name' => $people[$employmentId]['name'] ?? '',
            'employment_code' => $people[$employmentId]['employment_code'] ?? '',
            'code' => $code,
            'message' => $message,
        ];
        foreach ($writeResult['warnings'] as $warning) {
            // Chybějící úvazek je u schválení výjimka, ne varování — hlásí se níž.
            if ($warning['code'] !== PayrollEmploymentCalendarProvisioner::ISSUE_WEEKLY_HOURS_MISSING) {
                $result['warnings'][] = $item($warning['employment_id'], $warning['code'], $warning['message']);
            }
        }

        foreach (array_keys($people) as $employmentId) {
            [$code, $message] = $this->refusal(
                $supplierId,
                $employmentId,
                $periodStart,
                $importId,
                $writerExceptions[$employmentId] ?? null,
            );
            if ($code === 'already_approved') {
                ++$result['already_approved'];
                continue;
            }
            if ($code !== null) {
                $result['exceptions'][] = $item($employmentId, $code, (string) $message);
                continue;
            }
            $preview = $this->workSummary->preview($supplierId, $employmentId, $periodStart);
            [$code, $message] = self::previewRefusal($preview);
            if ($code !== null) {
                $result['exceptions'][] = $item($employmentId, $code, (string) $message);
                continue;
            }
            if (!$approveClean) {
                continue;
            }
            $month = $this->time->monthState($supplierId, $employmentId, $periodStart);
            try {
                $this->time->approveMonth(
                    $supplierId,
                    $employmentId,
                    $periodStart,
                    (int) ($month['row_version'] ?? 0),
                    self::confirmationInput($preview, $importId),
                    $userId,
                    PayrollJmhzWorkMonthSummaryBuilder::IMPORT_BULK_CONFIRMATION,
                );
            } catch (PayrollTimeLockedException|PayrollTimeConflictException|PayrollJmhzWorkSummaryConflictException $e) {
                $result['exceptions'][] = $item(
                    $employmentId,
                    'approval_conflict',
                    'Pracovní měsíc se mezitím změnil nebo ho někdo schválil. Načtěte stav znovu. '
                        . $e->getMessage(),
                );
                continue;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $result['exceptions'][] = $item($employmentId, 'approval_failed', $e->getMessage());
                continue;
            }
            ++$result['approved'];
            if (($preview['suggestions']['worked_days'] ?? null) === null) {
                $result['warnings'][] = $item(
                    $employmentId,
                    'worked_days_not_provided',
                    'Podklady docházky neuvádějí počet odpracovaných dnů; v měsíčním hlášení zůstane '
                        . 'nepovinný údaj 10267 nevyplněný.',
                );
            }
        }

        return $result;
    }

    /**
     * Znovu schválí měsíce dávky, jejichž pracovní souhrn po schválení zastaral: převod
     * zapisuje datované nepřítomnosti (nemoc, ošetřovné, neomluvená absence) až po
     * schválení docházky, a souhrn bez jejich hodin by zastavil evidenční list v měsíčním
     * hlášení. Měsíc se auditovaně otevře a schválí z aktuálního náhledu; měsíc, který
     * už drží mzdový běh nebo jehož náhled nejde čistě schválit, zůstane, jak je.
     *
     * @return array{refreshed:int,exceptions:list<array{employment_id:int,code:string,message:string}>}
     */
    public function refreshStale(int $supplierId, int $importId, ?int $userId): array
    {
        $batch = $this->batch($supplierId, $importId);
        $periodStart = $batch['period'] . '-01';
        $employments = [];
        foreach ($this->imports->batchRows($supplierId, $importId) as $row) {
            if (in_array((string) $row['meaning'], AttendanceMeaning::HOURS, true) && $row['employment_id'] !== null) {
                $employments[(int) $row['employment_id']] = true;
            }
        }
        $result = ['refreshed' => 0, 'exceptions' => []];
        foreach (array_keys($employments) as $employmentId) {
            $month = $this->time->monthState($supplierId, $employmentId, $periodStart);
            $summary = $this->time->importSummary($supplierId, $employmentId, $periodStart);
            if (($month['status'] ?? null) !== 'approved' || ($month['work_source'] ?? null) !== 'import_summary'
                || ($summary['attendance_import_id'] ?? null) !== $importId) {
                continue;
            }
            $preview = $this->workSummary->preview($supplierId, $employmentId, $periodStart);
            $approved = $this->time->approvedWorkSummarySha($supplierId, $employmentId, $periodStart);
            if ($approved === null || hash_equals($approved, (string) $preview['source_snapshot_sha256'])) {
                continue;
            }
            [$code, $message] = self::previewRefusal($preview);
            if ($code !== null) {
                $result['exceptions'][] = ['employment_id' => $employmentId, 'code' => $code, 'message' => (string) $message];
                continue;
            }
            // Otevření a nové schválení jako jeden krok: když schválení neprojde, měsíc
            // zůstane schválený s dosavadním souhrnem, ne otevřený.
            $pdo = $this->db->pdo();
            $outer = $pdo->inTransaction();
            $savepoint = 'work_summary_refresh_' . bin2hex(random_bytes(4));
            $outer ? $pdo->exec("SAVEPOINT {$savepoint}") : $pdo->beginTransaction();
            try {
                $this->time->reopenMonth($supplierId, $employmentId, $periodStart, (int) $month['row_version'],
                    'Převod doplnil nepřítomnosti, pracovní souhrn se schvaluje znovu.', $userId);
                // Nová revize otevřeného měsíce souhrn importu nemá; zápis dávky je idempotentní
                // a schválené měsíce ostatních vztahů nechá být.
                $this->summaries->writeFromBatch($supplierId, $importId, $userId);
                $month = $this->time->monthState($supplierId, $employmentId, $periodStart);
                $preview = $this->workSummary->preview($supplierId, $employmentId, $periodStart);
                $this->time->approveMonth($supplierId, $employmentId, $periodStart, (int) ($month['row_version'] ?? 0),
                    self::confirmationInput($preview, $importId), $userId, PayrollJmhzWorkMonthSummaryBuilder::IMPORT_BULK_CONFIRMATION);
                $outer ? $pdo->exec("RELEASE SAVEPOINT {$savepoint}") : $pdo->commit();
            } catch (PayrollTimeLockedException|PayrollTimeConflictException|PayrollJmhzWorkSummaryConflictException|\InvalidArgumentException|\DomainException $e) {
                $outer ? $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}") : $pdo->rollBack();
                $issues = array_column((array) ($preview['issues'] ?? []), 'message');
                $result['exceptions'][] = ['employment_id' => $employmentId, 'code' => 'refresh_failed',
                    'message' => trim($e->getMessage() . ' ' . implode(' ', $issues))];
                continue;
            }
            ++$result['refreshed'];
        }

        return $result;
    }

    /**
     * Důvod, proč vztah nejde schválit ještě před náhledem souhrnu.
     *
     * @return array{?string,?string}
     */
    private function refusal(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $importId,
        ?string $writerException,
    ): array {
        $month = $this->time->monthState($supplierId, $employmentId, $periodStart);
        $summary = $this->time->importSummary($supplierId, $employmentId, $periodStart);
        if (($month['status'] ?? null) === 'approved') {
            if (($month['work_source'] ?? null) === 'import_summary'
                && ($summary['attendance_import_id'] ?? null) === $importId
            ) {
                return ['already_approved', null];
            }

            return [
                'month_approved_other_source',
                'Pracovní měsíc je už schválený z jiného zdroje docházky. Dávka ho nepřepíše; '
                    . 'platí-li podklady z importu, měsíc nejdřív auditovaně znovu otevřete.',
            ];
        }
        if ($this->time->hasEntriesInPeriod($supplierId, $employmentId, $periodStart)) {
            return [
                'time_entries_present',
                'Pracovní měsíc má zapsané časové záznamy docházky. Souhrn z importu by byl druhým '
                    . 'zdrojem téhož měsíce, proto se neschválil.',
            ];
        }
        if ($writerException !== null) {
            return ['summary_not_written', $writerException];
        }
        if ($summary === null || $summary['attendance_import_id'] !== $importId) {
            return [
                'summary_from_other_import',
                'Aktuální revize pracovního měsíce nemá souhrn z této dávky importu.',
            ];
        }
        $dated = PayrollJmhzWorkMonthSummaryBuilder::importHoursRequiringDates($summary['values']);
        if ($dated !== []) {
            return [
                'absence_hours_without_dates',
                sprintf(
                    'Podklady uvádějí %s jen jako součet hodin, bez dat od–do. Náhradu mzdy ani evidenční '
                        . 'list z toho spočítat nejde. Buď podklady opravte v docházkovém systému a importujte '
                        . 'znovu (opravná dávka souhrn otevřeného měsíce nahradí a čistý měsíc se schválí), '
                        . 'nebo zapište nepřítomnost v Mzdy → Absence a dovolená a měsíc schvalte ručně.',
                    implode(', ', array_map(
                        static fn (string $meaning): string => self::DATED_HOURS_LABELS[$meaning] ?? $meaning,
                        $dated,
                    )),
                ),
            ];
        }

        return [null, null];
    }

    /**
     * @param array<string,mixed> $preview
     * @return array{?string,?string}
     */
    private static function previewRefusal(array $preview): array
    {
        if (($preview['derivation_version'] ?? null)
            !== PayrollJmhzWorkMonthSummaryBuilder::IMPORT_SUMMARY_DERIVATION_VERSION
        ) {
            return [
                'not_import_summary',
                'Pracovní měsíc nebere docházku ze souhrnu importu.',
            ];
        }
        $suggestions = is_array($preview['suggestions'] ?? null) ? $preview['suggestions'] : [];
        if (($suggestions['weekly_work_hours'] ?? null) === null) {
            return [
                PayrollEmploymentCalendarProvisioner::ISSUE_WEEKLY_HOURS_MISSING,
                'Pracovní vztah nemá v podmínkách týdenní pracovní dobu, takže chybí fond i stanovená '
                    . 'týdenní doba pro hlášení. Doplňte úvazek na kartě vztahu a měsíc schvalte.',
            ];
        }
        $issues = is_array($preview['issues'] ?? null) ? $preview['issues'] : [];
        if ($issues !== []) {
            $first = $issues[0];

            return [
                is_string($first['code'] ?? null) ? $first['code'] : 'work_summary_issues',
                implode(' ', array_unique(array_map(
                    static fn (array $issue): string => (string) ($issue['message'] ?? ''),
                    $issues,
                ))),
            ];
        }
        foreach (['standard_fund_hours', 'agreed_fund_hours', 'worked_hours'] as $field) {
            if (($suggestions[$field] ?? null) === null) {
                return [
                    'work_summary_not_derivable',
                    'Návrh pracovního souhrnu nejde vyjádřit v celých tisícinách hodiny; měsíc schvalte ručně.',
                ];
            }
        }
        if (!is_bool($suggestions['unworked_hours_occurred'] ?? null)
            || !is_bool($suggestions['work_obstacles_occurred'] ?? null)
        ) {
            return [
                'work_summary_not_derivable',
                'Neodpracované hodiny z podkladů nejde bez posouzení zařadit; měsíc schvalte ručně.',
            ];
        }

        return [null, null];
    }

    /**
     * Vstup potvrzení souhrnu = návrhy náhledu beze změny.
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private static function confirmationInput(array $preview, int $importId): array
    {
        $suggestions = $preview['suggestions'];
        $input = [
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'standard_fund_hours' => $suggestions['standard_fund_hours'],
            'agreed_fund_hours' => $suggestions['agreed_fund_hours'],
            'weekly_work_hours' => $suggestions['weekly_work_hours'],
            'worked_hours' => $suggestions['worked_hours'],
            'unworked_hours_occurred' => $suggestions['unworked_hours_occurred'],
            'work_obstacles_occurred' => $suggestions['work_obstacles_occurred'],
            'confirmation_note' => "Schváleno hromadně z dávky importu #{$importId}",
        ];
        foreach (self::CONDITIONAL_FIELDS as $field) {
            $input[$field] = $suggestions[$field] ?? null;
        }

        return $input;
    }

    /** @return array<string,mixed> */
    private function batch(int $supplierId, int $importId): array
    {
        $batch = $this->imports->batch($supplierId, $importId);
        if ($batch === null || $batch['source_system'] !== AttendanceMeaning::SOURCE_SYSTEM) {
            throw new \OutOfBoundsException('Dávka importu docházky nebyla nalezena.');
        }

        return $batch;
    }
}
