<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBody;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReportedEmploymentPeriods;
use PDO;

/**
 * „Nastavit pravděpodobný výdělek vybraným" v hromadných průměrech za čtvrtletí.
 *
 * Pravděpodobný výdělek (§ 355 odst. 2 ZP) stanoví účetní; aplikace ho vymyslet
 * nesmí. U brigádníků na dohodu, kteří nemají sjednanou hodinovou sazbu
 * v podmínkách (sazby jsou jen v importovaných vstupech), končil návrh na
 * hodinové minimální mzdě. Účetní pak musela otevřít kartu každého vztahu
 * zvlášť. Tahle služba vezme jednu částku a odůvodnění a pro každý vybraný
 * vztah:
 *
 *  1. opraví platnou verzi podmínek stejnou cestou jako karta vztahu
 *     ({@see PayrollEmploymentRepository::correctTerms()} přes validátor
 *     podmínek), takže číslo zůstane i pro další návrhy,
 *  2. založí a schválí novou revizi průměru za čtvrtletí
 *     ({@see AverageEarningBatchService::approveProbableEarning()}), která
 *     odsune dosavadní schválenou revizi s jinou částkou.
 *
 * Schválený průměr, ze kterého už počítal uzavřený mzdový běh čtvrtletí
 * (nebo z něj vzniklo hlášení JMHZ), se tiše nemění: řádek skončí chybou
 * s dalším krokem (otevřít běh k opravě, opravné hlášení). Každý vztah je
 * samostatná transakce; chyba jednoho ostatní nezastaví.
 *
 * Když podmínky opravit nejde (zúčtované období, ukončený vztah), průměr se
 * nastaví i tak a řádek nese upozornění. Průměr je pro náhrady a JMHZ 10345
 * rozhodující záznam, podmínky jen zdroj dalších návrhů.
 */
final class ProbableEarningBulkService
{
    public const MAX_EMPLOYMENTS = 500;
    /** Horní mez proti překlepu v řádu (100 000 Kč za hodinu). */
    private const MAX_HOURLY_MINOR = 10_000_000;
    private const SAVEPOINT = 'payroll_probable_bulk_row';

    public function __construct(
        private readonly Connection $db,
        private readonly AverageEarningBatchService $batch,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @param list<mixed> $employmentIds
     * @return array{counts:array{applied:int,unchanged:int,failed:int},applied:list<array<string,mixed>>,unchanged:list<array<string,mixed>>,failed:list<array<string,mixed>>}
     */
    public function apply(
        int $supplierId,
        int $year,
        int $quarter,
        int $hourlyMinor,
        string $rationale,
        array $employmentIds,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Rok použití průměru není platný.');
        }
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        if ($hourlyMinor <= 0 || $hourlyMinor > self::MAX_HOURLY_MINOR) {
            throw new \InvalidArgumentException('Pravděpodobný hodinový výdělek musí být kladná částka do 100 000 Kč.');
        }
        $rationale = trim($rationale);
        if ($rationale === '') {
            throw new \InvalidArgumentException(
                'Uveďte odůvodnění pravděpodobného výdělku (§ 355 odst. 2 zákoníku práce), například z čeho sazba vychází.',
            );
        }
        if (mb_strlen($rationale) > 1000) {
            throw new \InvalidArgumentException('Odůvodnění může mít nejvýše 1000 znaků.');
        }
        $ids = $this->normalizeIds($employmentIds);
        $months = $this->quarterMonths($year, $quarter);
        $quarterStart = $months[0];
        $quarterEnd = (new \DateTimeImmutable($months[2]))->modify('last day of this month')->format('Y-m-d');

        $rows = $this->rows($supplierId, $ids);
        $usage = $this->usage($supplierId, $ids, $months);

        $applied = [];
        $unchanged = [];
        $failed = [];
        foreach ($ids as $employmentId) {
            $row = $rows[$employmentId] ?? null;
            if ($row === null) {
                $failed[] = $this->failure($employmentId, null, 'employment_not_found', 'Pracovní vztah nebyl nalezen.');
                continue;
            }
            $start = (string) ($row['actual_start_date'] ?? $row['start_date'] ?? '');
            if (($start !== '' && $start > $quarterEnd)
                || ($row['end_date'] !== null && (string) $row['end_date'] < $quarterStart)
            ) {
                $failed[] = $this->failure(
                    $employmentId,
                    $row,
                    'employment_not_in_quarter',
                    $this->averages->quarterMismatch($supplierId, $employmentId, $year, $quarter)
                        ?? 'Pracovní vztah ve zvoleném čtvrtletí netrval.',
                );
                continue;
            }
            try {
                $outcome = $this->applyOne(
                    $supplierId,
                    $employmentId,
                    $row,
                    $year,
                    $quarter,
                    $hourlyMinor,
                    $rationale,
                    $usage[$employmentId] ?? null,
                    $userId,
                    $ip,
                    $userAgent,
                );
            } catch (\Throwable $exception) {
                if (TransactionFailure::aborted($exception)) {
                    throw $exception;
                }
                if (!$exception instanceof \InvalidArgumentException
                    && !$exception instanceof \DomainException
                    && !$exception instanceof \MyInvoice\Repository\Payroll\PayrollAbsenceConflictException
                    && !$exception instanceof PayrollEmploymentConflictException
                ) {
                    throw $exception;
                }
                $code = $exception instanceof PayrollEmploymentConflictException
                    || $exception instanceof \MyInvoice\Repository\Payroll\PayrollAbsenceConflictException
                    ? 'row_version_conflict'
                    : 'invalid';
                $message = $code === 'row_version_conflict'
                    ? 'Vztah nebo jeho průměr mezitím upravil někdo jiný. Načtěte přehled znovu a akci zopakujte.'
                    : $exception->getMessage();
                $failed[] = $this->failure($employmentId, $row, $code, $message);
                continue;
            }
            if ($outcome['state'] === 'failed') {
                $failed[] = $this->failure($employmentId, $row, $outcome['code'], $outcome['message'], $outcome['params']);
            } elseif ($outcome['state'] === 'unchanged') {
                $unchanged[] = $this->person($employmentId, $row) + ['warnings' => $outcome['warnings']];
            } else {
                $applied[] = $this->person($employmentId, $row) + [
                    'snapshot_id' => $outcome['snapshot_id'],
                    'superseded_average_hourly_minor' => $outcome['superseded'],
                    'warnings' => $outcome['warnings'],
                ];
            }
        }

        $this->activityLogger->log(
            'payroll.average_earning.probable_bulk_set',
            $userId,
            null,
            null,
            [
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'probable_hourly_minor' => $hourlyMinor,
                'rationale' => $rationale,
                'applied_employment_ids' => array_column($applied, 'employment_id'),
                'applied_snapshot_ids' => array_column($applied, 'snapshot_id'),
                'unchanged_employment_ids' => array_column($unchanged, 'employment_id'),
                'failed' => array_map(
                    static fn (array $item): array => ['employment_id' => $item['employment_id'], 'code' => $item['code']],
                    $failed,
                ),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'counts' => ['applied' => count($applied), 'unchanged' => count($unchanged), 'failed' => count($failed)],
            'applied' => $applied,
            'unchanged' => $unchanged,
            'failed' => $failed,
        ];
    }

    /**
     * Jeden vztah v jedné transakci (uvnitř cizí transakce jako savepoint).
     *
     * @param array<string,mixed> $row
     * @param array{closed:?array{period:string,status:string},jmhz:?string}|null $usage
     * @return array{state:string,code?:string,message?:string,params?:array<string,mixed>,warnings?:list<array<string,mixed>>,snapshot_id?:int,superseded?:?int}
     */
    private function applyOne(
        int $supplierId,
        int $employmentId,
        array $row,
        int $year,
        int $quarter,
        int $hourlyMinor,
        string $rationale,
        ?array $usage,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $version = $this->lockEmployment($supplierId, $employmentId);
            $outcome = $this->decide($supplierId, $employmentId, $year, $quarter, $hourlyMinor, $usage);
            if ($outcome['state'] !== 'failed') {
                $warnings = $outcome['warnings'];
                $termsWarning = $this->correctTerms($supplierId, $employmentId, $version, $hourlyMinor, $rationale, $userId, $ip, $userAgent);
                if ($termsWarning !== null) {
                    $warnings[] = $termsWarning;
                }
                $outcome['warnings'] = $warnings;
                if ($outcome['state'] === 'apply') {
                    $snapshot = $this->batch->approveProbableEarning(
                        $supplierId,
                        $employmentId,
                        $year,
                        $quarter,
                        $hourlyMinor,
                        $rationale,
                        $userId,
                    );
                    $outcome['state'] = 'applied';
                    $outcome['snapshot_id'] = (int) $snapshot['id'];
                    $floorWarning = self::minimumWageWarning($hourlyMinor, (int) ($snapshot['average_hourly_minor'] ?? $hourlyMinor));
                    if ($floorWarning !== null) {
                        $outcome['warnings'][] = $floorWarning;
                    }
                }
            }
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $outcome;
        } catch (\Throwable $exception) {
            if (TransactionFailure::rollbackIfAborted($pdo, $exception)) {
                throw $exception;
            }
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $exception;
        }
    }

    /**
     * Smí se průměr čtvrtletí nastavit? Čte pod zámkem vztahu.
     *
     * @param array{closed:?array{period:string,status:string},jmhz:?string}|null $usage
     * @return array{state:string,code?:string,message?:string,params?:array<string,mixed>,warnings:list<array<string,mixed>>,superseded:?int}
     */
    private function decide(int $supplierId, int $employmentId, int $year, int $quarter, int $hourlyMinor, ?array $usage): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT status, source_kind, average_hourly_minor, input_trace
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status IN ('manual_review', 'approved')
              ORDER BY revision_no DESC",
        );
        $stmt->execute([$supplierId, $employmentId, $year, $quarter]);
        $approved = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $snapshot) {
            if ($snapshot['status'] === 'manual_review') {
                return [
                    'state' => 'failed',
                    'code' => 'average_pending_review',
                    'message' => sprintf(
                        'Za %d. čtvrtletí %d čeká ke schválení jiná revize průměru. Schvalte ji, nebo ji smažte '
                        . 'v detailu vztahu (Průměrné výdělky), a nastavení zopakujte.',
                        $quarter,
                        $year,
                    ),
                    'params' => [],
                    'warnings' => [],
                    'superseded' => null,
                ];
            }
            $approved ??= $snapshot;
        }
        $label = sprintf('%d/%d', $quarter, $year);
        /*
         * Zadaný výdělek pod minimální mzdou se při výpočtu zvýší na ni
         * (§ 357 odst. 1 zákoníku práce), takže uložený průměr se od zadané
         * částky liší. Beze změny je proto i průměr, který vznikl z TÉŽE
         * zadané částky; jinak každé opakované odeslání založilo novou revizi.
         */
        $approvedTrace = $approved === null ? [] : json_decode((string) ($approved['input_trace'] ?? ''), true);
        if ($approved !== null
            && $approved['source_kind'] === 'probable'
            && ((int) $approved['average_hourly_minor'] === $hourlyMinor
                || (is_array($approvedTrace) && ($approvedTrace['probable_hourly_minor'] ?? null) === $hourlyMinor))
        ) {
            $floorWarning = self::minimumWageWarning($hourlyMinor, (int) $approved['average_hourly_minor']);

            return ['state' => 'unchanged', 'warnings' => $floorWarning === null ? [] : [$floorWarning], 'superseded' => null];
        }
        $warnings = [];
        if ($approved !== null && $usage !== null) {
            $closed = $usage['closed'];
            if ($closed !== null) {
                $period = PayrollClosedRunGuard::periodLabel($closed['period']);
                $jmhz = $usage['jmhz'] !== null;

                return [
                    'state' => 'failed',
                    'code' => $jmhz ? 'average_reported_in_jmhz' : 'average_used_by_closed_run',
                    'message' => $jmhz
                        ? sprintf(
                            'Schválený průměr za Q%s už použil %s mzdový běh za %s a z něj vzniklo hlášení JMHZ. '
                            . 'Nemění se tiše: otevřete běh za %s k opravě, nastavení zopakujte a po schválení '
                            . 'opravy podejte opravné hlášení JMHZ.',
                            $label,
                            PayrollClosedRunGuard::statusLabel($closed['status']),
                            $period,
                            $period,
                        )
                        : sprintf(
                            'Schválený průměr za Q%s už použil %s mzdový běh za %s. Nemění se tiše: otevřete běh '
                            . 'za %s k opravě a nastavení zopakujte.',
                            $label,
                            PayrollClosedRunGuard::statusLabel($closed['status']),
                            $period,
                            $period,
                        ),
                    'params' => [
                        'quarter' => $label,
                        'period' => $period,
                        'status' => $closed['status'],
                    ],
                    'warnings' => [],
                    'superseded' => null,
                ];
            }
            if ($usage['jmhz'] !== null) {
                // Běh je otevřený k opravě: průměr se změní, ale hlášení,
                // které už odešlo, se samo neopraví.
                $period = PayrollClosedRunGuard::periodLabel($usage['jmhz']);
                $warnings[] = [
                    'code' => 'corrective_report_needed',
                    'params' => ['period' => $period],
                    'message' => sprintf(
                        'Za %s už bylo podané hlášení JMHZ s původním průměrem. Po schválení opravy běhu podejte opravné hlášení.',
                        $period,
                    ),
                ];
            }
        }

        return [
            'state' => 'apply',
            'warnings' => $warnings,
            'superseded' => $approved === null ? null : (int) $approved['average_hourly_minor'],
        ];
    }

    /**
     * Oprava platné verze podmínek stejnou cestou jako karta vztahu
     * a hromadné doplnění pracoviště. Neprojde-li (zúčtované období,
     * ukončený vztah), vrátí upozornění; konflikt verze vyhodí.
     *
     * @return array<string,mixed>|null upozornění
     */
    private function correctTerms(
        int $supplierId,
        int $employmentId,
        int $version,
        int $hourlyMinor,
        string $rationale,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): ?array {
        $current = $this->employments->currentTerms($supplierId, $employmentId);
        if ($current === null) {
            return [
                'code' => 'terms_not_updated',
                'params' => [],
                'message' => 'Vztah nemá žádnou verzi podmínek, pravděpodobný výdělek se uložil jen do průměru.',
            ];
        }
        if ((int) ($current['probable_hourly_earning_minor'] ?? 0) === $hourlyMinor
            && trim((string) ($current['probable_earning_rationale'] ?? '')) === $rationale
        ) {
            return null;
        }
        try {
            $body = PayrollEmploymentTermsBody::fromCurrent(
                $current,
                'Hromadně nastavený pravděpodobný výdělek (§ 355 odst. 2 ZP).',
            );
            $body['effective_from'] = (string) $current['effective_from'];
            $body['probable_hourly_earning_minor'] = $hourlyMinor;
            $body['probable_earning_rationale'] = $rationale;
            $this->employments->correctTerms(
                $supplierId,
                $employmentId,
                $this->validator->terms(
                    $body,
                    $this->employments->currentCzIscoCode($supplierId, $employmentId),
                    $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                    $this->employments->currentRelationType($supplierId, $employmentId),
                    // Mění se jen pravděpodobný výdělek; ostatní uložená pole
                    // nesmí zápis zablokovat novější číselníkovou kontrolou.
                    $current,
                ),
                $version,
                $userId,
                $ip,
                $userAgent,
            );
        } catch (PayrollEmploymentConflictException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException|\DomainException $exception) {
            if (TransactionFailure::aborted($exception)) {
                throw $exception;
            }

            return [
                'code' => 'terms_not_updated',
                'params' => [],
                'message' => 'Podmínky vztahu se neopravily (' . $exception->getMessage() . '); '
                    . 'pravděpodobný výdělek je uložený jen v průměru. Pro další čtvrtletí ho zadejte na kartě vztahu.',
            ];
        }

        return null;
    }

    /**
     * Uzavřené běhy a hlášení JMHZ ve třech měsících čtvrtletí, jedním dotazem
     * pro celý výběr.
     *
     * Uzavřený = vztah je v aktuální revizi běhu ve stavu
     * {@see PayrollClosedRunGuard::FINISHED_STATUSES}. Hlášení = zmrazená
     * příprava nebo odeslané podání JMHZ z kterékoli revize běhu, ve které
     * vztah byl, i když je běh mezitím otevřený k opravě.
     *
     * @param list<int> $employmentIds
     * @param list<string> $months
     * @return array<int,array{closed:?array{period:string,status:string},jmhz:?string}>
     */
    private function usage(int $supplierId, array $employmentIds, array $months): array
    {
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT run_employment.employment_id, run.period_start, run.status,
                    revision.revision_no = run.current_revision_no AS is_current,
                    ' . JmhzReportedEmploymentPeriods::revisionReportedSql('revision') . ' AS reported_in_jmhz
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
               JOIN payroll_run_employments run_employment
                 ON run_employment.supplier_id = revision.supplier_id
                AND run_employment.revision_id = revision.id
              WHERE run.supplier_id = ?
                AND run.period_start IN (?, ?, ?)
                AND run_employment.employment_id IN (' . $in . ')
              ORDER BY run.period_start, run.id, revision.revision_no',
        );
        $stmt->execute([
            ...JmhzReportedEmploymentPeriods::revisionReportedParams(),
            $supplierId,
            ...$months,
            ...$employmentIds,
        ]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            $result[$employmentId] ??= ['closed' => null, 'jmhz' => null];
            $period = substr((string) $row['period_start'], 0, 7);
            if ((int) $row['is_current'] === 1
                && in_array((string) $row['status'], PayrollClosedRunGuard::FINISHED_STATUSES, true)
            ) {
                $result[$employmentId]['closed'] ??= ['period' => $period, 'status' => (string) $row['status']];
            }
            if ((int) $row['reported_in_jmhz'] === 1) {
                $result[$employmentId]['jmhz'] ??= $period;
            }
        }

        return $result;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array<string,mixed>>
     */
    private function rows(int $supplierId, array $employmentIds): array
    {
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code, employment.start_date,
                    employment.actual_start_date, employment.end_date, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id IN ({$in})
                AND employment.status NOT IN ('archived', 'no_show')",
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    private function lockEmployment(int $supplierId, int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId, $employmentId]);
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }

        return (int) $version;
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{employment_id:int,employee_id:?int,employee_name:?string,employment_code:?string}
     */
    /**
     * Upozornění, že průměr je vyšší než zadaný výdělek, protože ho zákon
     * zvedl na minimální mzdu (§ 357 odst. 1 zákoníku práce). Bez něj
     * účetní viděla „nastaveno" a myslela si, že platí zadaná částka.
     *
     * @return array{code:string,params:array<string,string>,message:string}|null
     */
    private static function minimumWageWarning(int $requestedMinor, int $appliedMinor): ?array
    {
        if ($appliedMinor <= $requestedMinor) {
            return null;
        }
        $format = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ');

        return [
            'code' => 'raised_to_minimum_wage',
            'params' => ['requested' => $format($requestedMinor), 'applied' => $format($appliedMinor)],
            'message' => sprintf(
                'Zadaný výdělek %s Kč/h je nižší než minimální mzda. Průměr se podle § 357 odst. 1 '
                . 'zákoníku práce zvýšil na %s Kč/h a s touto částkou počítají náhrady i hlášení JMHZ.',
                $format($requestedMinor),
                $format($appliedMinor),
            ),
        ];
    }

    private function person(int $employmentId, ?array $row): array
    {
        return [
            'employment_id' => $employmentId,
            'employee_id' => $row === null ? null : (int) $row['employee_id'],
            'employee_name' => $row === null ? null : (string) $row['full_name'],
            'employment_code' => $row === null ? null : (string) $row['code'],
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function failure(int $employmentId, ?array $row, string $code, string $message, array $params = []): array
    {
        return $this->person($employmentId, $row) + ['code' => $code, 'message' => $message, 'params' => $params];
    }

    /**
     * @param list<mixed> $employmentIds
     * @return list<int>
     */
    private function normalizeIds(array $employmentIds): array
    {
        $ids = [];
        foreach ($employmentIds as $value) {
            if (!is_int($value) || $value <= 0) {
                throw new \InvalidArgumentException('ID pracovního vztahu musí být kladné celé číslo.');
            }
            $ids[$value] = true;
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden pracovní vztah.');
        }
        if (count($ids) > self::MAX_EMPLOYMENTS) {
            throw new \InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d pracovních vztahů.',
                self::MAX_EMPLOYMENTS,
            ));
        }

        return array_keys($ids);
    }

    /** @return list<string> první dny tří měsíců čtvrtletí */
    private function quarterMonths(int $year, int $quarter): array
    {
        $first = (($quarter - 1) * 3) + 1;

        return [
            sprintf('%04d-%02d-01', $year, $first),
            sprintf('%04d-%02d-01', $year, $first + 1),
            sprintf('%04d-%02d-01', $year, $first + 2),
        ];
    }
}
