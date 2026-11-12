<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollInputConflictException;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\PayrollClosedRunGuard;
use MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use PDO;

/**
 * „Přepočítat náhrady z nového průměru" jako výslovná akce účetní.
 *
 * Náhrada mzdy (dovolená zadaná s daty, náhrada z importu hodin, překážka) nese
 * pevnou částku spočítanou z průměru, který byl schválený v okamžiku jejího
 * vzniku. Změna schváleného průměru ji sama nemění: měnila by peníze, o kterých
 * už účetní rozhodla. Tady se to děje jen na výslovný pokyn a jen tam, kde to
 * nesahá do schválené revize běhu:
 *
 * - dovolená s daty a náhrada z importu hodin se spočítá ze stejných minut
 *   a sazby jako původní vstup, jen z nového průměru; původní vstup se zruší
 *   a vznikne nový se stejným `external_id` (schválený, nebo koncept podle
 *   práva uživatele schvalovat),
 * - ostatní (překážky s dorovnáním, DPN, náhrada dorovnaná na minimum) se jen
 *   vypíšou k ručnímu dořešení,
 * - schválený běh (a vyžádaná oprava) akci odmítne s návodem Vyžádat / Otevřít
 *   opravu; otevřený běh se snímkem dostane po změně „Obnovit podklady".
 *
 * Idempotence: přepočtený vstup nese ve stopě nový průměr, takže ho další
 * spuštění už nenajde; `uq_payroll_input_external` drží jediný živý vstup.
 */
final class PayrollCompensationRecalculationService
{
    private const SAVEPOINT = 'payroll_compensation_recalculation';

    private const OPEN_STATUSES = ['inputs_locked', 'calculated', 'reviewed', 'reopened'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunCommandService $commands,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param list<string> $periods RRRR-MM
     * @param list<int>|null $employmentIds
     * @return array{periods:list<array<string,mixed>>}
     */
    public function preview(int $supplierId, array $periods, ?array $employmentIds = null): array
    {
        $result = [];
        foreach (self::normalizedPeriods($periods) as $period) {
            $result[] = $this->periodPreview($supplierId, $period, $employmentIds, false);
        }

        return ['periods' => $result];
    }

    /**
     * @param list<int>|null $employmentIds
     * @param list<int>|null $inputIds vstupy, které účetní viděla v náhledu; jiné se nepřepočítají
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        string $period,
        ?array $employmentIds,
        ?array $inputIds,
        int $actorUserId,
        bool $canApprove,
        bool $canRefresh,
        string $idempotencyKey,
    ): array {
        $period = self::normalizedPeriods([$period])[0];

        return $this->transactional(function () use (
            $supplierId,
            $period,
            $employmentIds,
            $inputIds,
            $actorUserId,
            $canApprove,
            $canRefresh,
            $idempotencyKey,
        ): array {
            $preview = $this->periodPreview($supplierId, $period, $employmentIds, true);
            $items = $inputIds === null
                ? $preview['items']
                : array_values(array_filter(
                    $preview['items'],
                    static fn (array $item): bool => in_array($item['input_id'], $inputIds, true),
                ));
            $blocked = array_values(array_filter($items, static fn (array $item): bool => $item['action'] === 'blocked'));
            if ($blocked !== []) {
                throw new PayrollCompensationRecalculationBlockedException(
                    (string) $blocked[0]['next_step'],
                    (string) $blocked[0]['reason'],
                    $blocked[0]['run_id'],
                );
            }
            $changes = array_values(array_filter(
                $items,
                static fn (array $item): bool => in_array($item['action'], ['recalculate', 'replace'], true),
            ));
            $refreshRuns = [];
            foreach ($changes as $item) {
                if ($item['run_id'] !== null && in_array($item['run_status'], self::OPEN_STATUSES, true)) {
                    $refreshRuns[$item['run_id']] = true;
                }
            }
            if ($refreshRuns !== [] && !$canRefresh) {
                throw new \DomainException(
                    'Náhrady jsou ve snímku otevřeného mzdového běhu a po přepočtu je potřeba obnovit podklady běhu. '
                    . 'To smí uživatel s právem „Spočítat mzdy“.',
                );
            }

            $rounding = PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
            $done = [];
            foreach ($changes as $item) {
                $done[] = $this->supersede($supplierId, $item, $actorUserId, $canApprove, $rounding);
            }

            $refreshed = [];
            foreach (array_keys($refreshRuns) as $runId) {
                $run = $this->runs->lock($supplierId, $runId) ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
                $revision = $this->runs->currentRevision($supplierId, $runId);
                $this->runs->insertEvent(
                    $supplierId,
                    $runId,
                    $revision === null ? null : (int) $revision['id'],
                    'compensation_recalculated',
                    (string) $run['status'],
                    (string) $run['status'],
                    $actorUserId,
                    'Náhrady přepočítané z nového průměru.',
                    ['inputs' => array_values(array_map(
                        static fn (array $row): array => [
                            'from_input_id' => $row['input_id'],
                            'to_input_id' => $row['new_input_id'],
                            'from_amount_minor' => $row['amount_minor'],
                            'to_amount_minor' => $row['new_amount_minor'],
                        ],
                        array_filter($done, static fn (array $row): bool => $row['run_id'] === $runId),
                    ))],
                );
                $command = $this->commands->refreshInputs(
                    $supplierId,
                    $runId,
                    (int) $run['row_version'],
                    'compensation-recalculation:' . $runId . ':' . hash('sha256', $idempotencyKey),
                    $actorUserId,
                );
                $refreshed[] = [
                    'id' => $runId,
                    'status' => (string) $command->run['status'],
                    'row_version' => (int) $command->run['row_version'],
                ];
            }

            $this->logger->log(
                'payroll.absence_compensation.recalculated',
                $actorUserId,
                null,
                null,
                [
                    'period' => $period,
                    'recalculated' => array_map(static fn (array $row): array => [
                        'employment_id' => $row['employment_id'],
                        'from_input_id' => $row['input_id'],
                        'to_input_id' => $row['new_input_id'],
                        'from_amount_minor' => $row['amount_minor'],
                        'to_amount_minor' => $row['new_amount_minor'],
                        'from_average_snapshot_id' => $row['old_average_snapshot_id'],
                        'to_average_snapshot_id' => $row['new_average_snapshot_id'],
                        'status' => $row['new_status'],
                    ], $done),
                    'manual_input_ids' => array_values(array_map(
                        static fn (array $item): int => $item['input_id'],
                        array_filter($items, static fn (array $item): bool => $item['action'] === 'manual'),
                    )),
                    'refreshed_run_ids' => array_keys($refreshRuns),
                ],
                null,
                null,
                $supplierId,
            );

            return [
                'period' => $period,
                'recalculated' => $done,
                'manual' => array_values(array_filter($items, static fn (array $item): bool => $item['action'] === 'manual')),
                'refreshed_runs' => $refreshed,
            ];
        });
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function supersede(int $supplierId, array $item, int $actorUserId, bool $canApprove, PayrollWageRounding $rounding): array
    {
        $input = $this->lockedInput($supplierId, $item['input_id']);
        if ($input === null || !in_array($input['status'], ['approved', 'locked'], true)) {
            throw new PayrollInputConflictException((int) ($input['row_version'] ?? 0));
        }
        $version = PayrollTimeValue::int($input['row_version'] ?? null, 'row_version');
        if ($input['status'] === 'approved') {
            $this->inputs->cancelApproved($supplierId, $item['input_id'], $version);
        } else {
            $cancel = $this->db->pdo()->prepare(
                'UPDATE payroll_inputs
                    SET status = "cancelled", row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ? AND status = "locked"',
            );
            $cancel->execute([$supplierId, $item['input_id'], $version]);
            if ($cancel->rowCount() !== 1) {
                throw new PayrollInputConflictException($version);
            }
        }

        $trace = json_decode((string) $input['source_snapshot_json'], true);
        $trace = [
            ...(is_array($trace) ? $trace : []),
            'average_hourly_minor' => $item['new_average_hourly_minor'],
            'average_snapshot_id' => $item['new_average_snapshot_id'],
            'amount_minor' => $item['new_amount_minor'],
            'rounding' => $rounding->traceLabel(),
            'rounding_basis' => $rounding->basisLabel(),
            'recalculated_from_input_id' => $item['input_id'],
        ];
        $snapshot = CanonicalJson::encode($trace);
        $created = $this->inputs->create($supplierId, [
            'employee_id' => PayrollTimeValue::int($input['employee_id'] ?? null, 'employee_id'),
            'employment_id' => PayrollTimeValue::int($input['employment_id'] ?? null, 'employment_id'),
            'component_id' => PayrollTimeValue::int($input['component_id'] ?? null, 'component_id'),
            'period_start' => (string) $input['period_start'],
            'source_period_start' => $input['source_period_start'],
            'amount_minor' => $item['new_amount_minor'],
            'quantity_milliunits' => $input['quantity_milliunits'] === null ? null : (int) $input['quantity_milliunits'],
            'source_kind' => 'absence',
            'external_id' => $input['external_id'],
            'source_snapshot_json' => $snapshot,
            'source_snapshot_hash' => hash('sha256', $snapshot, true),
        ], $actorUserId);
        $newId = PayrollTimeValue::int($created['id'] ?? null, 'input_id');
        $status = 'draft';
        if ($canApprove) {
            $approved = $this->inputs->approve(
                $supplierId,
                $newId,
                PayrollTimeValue::int($created['row_version'] ?? null, 'row_version'),
                $actorUserId,
            ) ?? throw new \RuntimeException('Přepočtený vstup náhrady nebyl nalezen.');
            $status = (string) $approved['status'];
        }

        return [
            'input_id' => $item['input_id'],
            'new_input_id' => $newId,
            'new_status' => $status,
            'employment_id' => $item['employment_id'],
            'employee_id' => $item['employee_id'],
            'full_name' => $item['full_name'],
            'component_name' => $item['component_name'],
            'amount_minor' => $item['amount_minor'],
            'new_amount_minor' => $item['new_amount_minor'],
            'old_average_snapshot_id' => $item['old_average_snapshot_id'],
            'new_average_snapshot_id' => $item['new_average_snapshot_id'],
            'run_id' => $item['run_id'],
        ];
    }

    /**
     * @param list<int>|null $employmentIds
     * @return array<string,mixed>
     */
    private function periodPreview(int $supplierId, string $period, ?array $employmentIds, bool $lock): array
    {
        $periodStart = $period . '-01';
        $runs = $this->periodRuns($supplierId, $periodStart, $lock);
        $inputs = $this->staleCandidates($supplierId, $periodStart, $employmentIds, $lock);
        $averages = $this->currentAverages($supplierId, $periodStart, array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employment_id'],
            $inputs,
        ))));
        $rounding = null;
        $floors = null;
        $items = [];
        foreach ($inputs as $input) {
            $employmentId = (int) $input['employment_id'];
            $trace = json_decode((string) $input['source_snapshot_json'], true);
            if (!is_array($trace) || !is_int($trace['average_snapshot_id'] ?? null)) {
                continue;
            }
            $average = $averages[$employmentId] ?? null;
            if ($average !== null && $trace['average_snapshot_id'] === $average['id']) {
                continue;
            }
            $run = $runs['by_employment'][$employmentId] ?? null;
            $item = [
                'input_id' => (int) $input['id'],
                'input_status' => (string) $input['status'],
                'period' => $period,
                'employee_id' => (int) $input['employee_id'],
                'employment_id' => $employmentId,
                'full_name' => (string) $input['full_name'],
                'component_code' => (string) $input['component_code'],
                'component_name' => (string) $input['component_name'],
                'kind' => self::kind($trace),
                'quantity_milliunits' => $input['quantity_milliunits'] === null ? null : (int) $input['quantity_milliunits'],
                'minutes' => is_int($trace['minutes'] ?? null) ? $trace['minutes'] : null,
                'amount_minor' => (int) $input['amount_minor'],
                'old_average_hourly_minor' => is_int($trace['average_hourly_minor'] ?? null) ? $trace['average_hourly_minor'] : null,
                'old_average_snapshot_id' => $trace['average_snapshot_id'],
                'new_average_hourly_minor' => $average['average_hourly_minor'] ?? null,
                'new_average_snapshot_id' => $average['id'] ?? null,
                'new_amount_minor' => null,
                'action' => 'manual',
                'reason' => null,
                'next_step' => null,
                'run_id' => $run === null ? null : $run['id'],
                'run_status' => $run === null ? null : $run['status'],
            ];
            $blocked = $run === null ? null : self::blockedStep($run, $period);
            if ($blocked !== null) {
                $rounding ??= PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
                $item['new_amount_minor'] = $this->expectedAmount($trace, $average, $rounding);
                $item['action'] = 'blocked';
                $item['next_step'] = $blocked['next_step'];
                $item['reason'] = $blocked['reason'];
                $items[] = $item;
                continue;
            }
            $manual = $this->manualReason($supplierId, $input, $trace, $average, $run);
            if ($manual === null) {
                $floors ??= $this->floors($supplierId, $periodStart, array_values(array_unique(array_map(
                    static fn (array $row): int => (int) $row['employment_id'],
                    $inputs,
                ))));
                if (self::floorApplies($floors[$employmentId] ?? [], $periodStart, (int) $average['average_hourly_minor'])) {
                    $manual = 'Nový průměr je pod zaručeným hodinovým minimem a náhrada se dorovnává. Dorovnání z měsíčního součtu přepočítat nejde, spočítejte náhradu ručně.';
                }
            }
            if ($manual !== null) {
                $item['reason'] = $manual;
                $items[] = $item;
                continue;
            }
            $rounding ??= PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId);
            $item['new_amount_minor'] = $this->expectedAmount($trace, $average, $rounding);
            $item['action'] = $item['kind'] === 'leave' ? 'recalculate' : 'replace';
            $items[] = $item;
        }

        $actions = array_column($items, 'action');
        $blockedItem = null;
        foreach ($items as $item) {
            if ($item['action'] === 'blocked') {
                $blockedItem = $item;
                break;
            }
        }

        return [
            'period' => $period,
            'availability' => $blockedItem === null ? 'ready' : (string) $blockedItem['next_step'],
            'reason' => $blockedItem['reason'] ?? null,
            'runs' => array_values($runs['runs']),
            'counts' => [
                'recalculate' => count(array_filter($actions, static fn (string $action): bool => in_array($action, ['recalculate', 'replace'], true))),
                'manual' => count(array_filter($actions, static fn (string $action): bool => $action === 'manual')),
                'blocked' => count(array_filter($actions, static fn (string $action): bool => $action === 'blocked')),
            ],
            'difference_minor' => array_sum(array_map(
                static fn (array $item): int => $item['new_amount_minor'] === null ? 0 : $item['new_amount_minor'] - $item['amount_minor'],
                array_filter($items, static fn (array $item): bool => $item['action'] !== 'manual'),
            )),
            'items' => $items,
        ];
    }

    /** @param array<string,mixed> $trace */
    private static function kind(array $trace): string
    {
        return match ($trace['kind'] ?? null) {
            'leave_compensation.v1' => 'leave',
            'import_absence_compensation.v1' => 'import',
            'obstacle_compensation.v1' => 'obstacle',
            default => 'other',
        };
    }

    /**
     * @param array{id:int,status:string,revision_kind:?string} $run
     * @return array{next_step:string,reason:string}|null
     */
    private static function blockedStep(array $run, string $period): ?array
    {
        $label = substr($period, 5, 2) . '/' . substr($period, 0, 4);
        if (in_array($run['status'], PayrollClosedRunGuard::FINISHED_STATUSES, true)) {
            return [
                'next_step' => 'request_correction',
                'reason' => sprintf(
                    'Mzdový běh č. %d za %s je schválený a náhrady v něm nejde měnit. V Mzdy → Mzdové běhy zvolte '
                    . '„Vyžádat opravu“ (právo „Zkontrolovat mzdový běh“) a potom „Otevřít opravu“ (právo „Odemknout '
                    . 'schválený mzdový běh“). V otevřené opravě náhrady přepočítejte znovu.',
                    $run['id'],
                    $label,
                ),
            ];
        }
        if ($run['status'] === 'correction_pending') {
            return [
                'next_step' => 'reopen',
                'reason' => sprintf(
                    'Mzdový běh č. %d za %s čeká na opravu. V Mzdy → Mzdové běhy zvolte „Otevřít opravu“ (právo '
                    . '„Odemknout schválený mzdový běh“) a náhrady potom přepočítejte znovu.',
                    $run['id'],
                    $label,
                ),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $trace
     * @param array{id:int,average_hourly_minor:?int,supported:bool}|null $average
     * @param array{id:int,status:string,revision_kind:?string}|null $run
     */
    private function manualReason(int $supplierId, array $input, array $trace, ?array $average, ?array $run): ?string
    {
        if ($average === null) {
            return 'Pro čtvrtletí chybí schválený průměrný výdělek. Schvalte ho a náhradu přepočítejte znovu.';
        }
        if (!$average['supported'] || !is_int($average['average_hourly_minor']) || $average['average_hourly_minor'] <= 0) {
            return 'Schválený průměr čtvrtletí nejde pro výpočet náhrady použít (ruční posouzení). Spočítejte náhradu ručně.';
        }
        $kind = self::kind($trace);
        if (!in_array($kind, ['leave', 'import'], true)) {
            return $kind === 'obstacle'
                ? 'Náhrada za překážku se může dorovnávat nebo krátit podle druhu překážky. Upravte ji ručně v Mzdových vstupech.'
                : 'Tuto náhradu z jejího podkladu přepočítat nejde. Upravte ji ručně v Mzdových vstupech.';
        }
        if (!is_int($trace['minutes'] ?? null) || $trace['minutes'] <= 0 || isset($trace['hourly_average_floor'])) {
            return 'Náhrada byla dorovnaná na zaručené minimum nebo nemá doložené hodiny. Spočítejte ji ručně.';
        }
        if ($kind === 'import') {
            $percent = $trace['rate_percent'] ?? null;
            if (!is_int($percent) || $percent <= 0 || $percent > 100) {
                return 'Náhrada z importu nemá doloženou sazbu. Spočítejte ji ručně.';
            }
        }
        if ($kind === 'leave') {
            $absenceId = $trace['absence_id'] ?? null;
            if (!is_int($absenceId) || !$this->isOriginalLeave($input, $absenceId)) {
                return 'Vstup dovolené nemá původní podobu (je opravný nebo převzatý). Upravte ho ručně.';
            }
            if ($this->hasActiveReversal($supplierId, $input, $absenceId)) {
                return 'Dovolená byla zrušena a náhrada je už stornovaná opravným vstupem. Přepočet tu nemá smysl.';
            }
        }
        if ($input['status'] === 'locked'
            && ($run === null || !in_array($run['status'], self::OPEN_STATUSES, true) || $run['revision_kind'] === 'correction')
        ) {
            return 'Vstup drží jiný mzdový běh (jiná účtárna). Upravte ho ručně v tom běhu.';
        }

        return null;
    }

    /** @param array<string,mixed> $input */
    private function isOriginalLeave(array $input, int $absenceId): bool
    {
        return preg_match('/^leave:' . $absenceId . ':\d{4}-\d{2}-\d{2}:original$/D', (string) $input['external_id']) === 1;
    }

    /** @param array<string,mixed> $input */
    private function hasActiveReversal(int $supplierId, array $input, int $absenceId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "correction" AND external_id = ? AND status <> "cancelled"
              LIMIT 1',
        );
        $statement->execute([
            $supplierId,
            (int) $input['employment_id'],
            (string) $input['period_start'],
            "leave:{$absenceId}:" . (string) $input['period_start'] . ':reversal',
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $trace
     * @param array{id:int,average_hourly_minor:?int,supported:bool}|null $average
     */
    private function expectedAmount(array $trace, ?array $average, PayrollWageRounding $rounding): ?int
    {
        $minutes = $trace['minutes'] ?? null;
        $hourly = $average['average_hourly_minor'] ?? null;
        if (!is_int($minutes) || $minutes <= 0 || !is_int($hourly) || $hourly <= 0) {
            return null;
        }
        $percent = match (self::kind($trace)) {
            'leave' => 100,
            'import' => is_int($trace['rate_percent'] ?? null) ? $trace['rate_percent'] : null,
            default => null,
        };
        if ($percent === null || $percent <= 0 || $percent > 100) {
            return null;
        }

        return LeaveCompensationCalculator::calculateMinutes($hourly, $minutes, $percent, $rounding);
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function floors(int $supplierId, string $periodStart, array $employmentIds): array
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        try {
            return (new PayrollHourlyAverageFloorResolver($this->db))->forMany($supplierId, $employmentIds, $periodStart, $periodEnd);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $intervals */
    private static function floorApplies(array $intervals, string $periodStart, int $averageMinor): bool
    {
        if ($intervals === []) {
            return false;
        }
        $day = new \DateTimeImmutable($periodStart);
        $end = $day->modify('last day of this month');
        for (; $day <= $end; $day = $day->modify('+1 day')) {
            try {
                if (PayrollHourlyAverageFloorResolver::apply($intervals, $day->format('Y-m-d'), $averageMinor)['hourly_proof'] !== null) {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Běhy období a vztahy zmrazené v jejich aktuální revizi.
     *
     * @return array{runs:array<int,array<string,mixed>>,by_employment:array<int,array{id:int,status:string,revision_kind:?string}>}
     */
    private function periodRuns(int $supplierId, string $periodStart, bool $lock): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.id, run.status, run.row_version, run.office_id, revision.revision_kind
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.period_start = ? AND run.status <> "cancelled"
              ORDER BY run.id' . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$supplierId, $periodStart]);
        $runs = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) $row['status'];
            $runs[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'status' => $status,
                'row_version' => (int) $row['row_version'],
                'office_id' => $row['office_id'] === null ? null : (int) $row['office_id'],
                'revision_kind' => $row['revision_kind'] === null ? null : (string) $row['revision_kind'],
                'availability' => in_array($status, PayrollClosedRunGuard::FINISHED_STATUSES, true)
                    ? 'request_correction'
                    : ($status === 'correction_pending' ? 'reopen' : 'ready'),
                'refresh' => in_array($status, self::OPEN_STATUSES, true),
            ];
        }
        $byEmployment = [];
        if ($runs !== []) {
            $in = implode(', ', array_fill(0, count($runs), '?'));
            $employments = $this->db->pdo()->prepare(
                "SELECT run.id AS run_id, run_employment.employment_id
                   FROM payroll_runs run
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = run.supplier_id
                    AND revision.run_id = run.id
                    AND revision.revision_no = run.current_revision_no
                   JOIN payroll_run_employments run_employment
                     ON run_employment.supplier_id = revision.supplier_id
                    AND run_employment.revision_id = revision.id
                  WHERE run.supplier_id = ? AND run.id IN ({$in})",
            );
            $employments->execute([$supplierId, ...array_keys($runs)]);
            foreach ($employments->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $run = $runs[(int) $row['run_id']];
                $byEmployment[(int) $row['employment_id']] = [
                    'id' => $run['id'],
                    'status' => $run['status'],
                    'revision_kind' => $run['revision_kind'],
                ];
            }
        }

        return ['runs' => $runs, 'by_employment' => $byEmployment];
    }

    /**
     * Schválené a uzamčené náhrady období se stopou průměru.
     *
     * @param list<int>|null $employmentIds
     * @return list<array<string,mixed>>
     */
    private function staleCandidates(int $supplierId, string $periodStart, ?array $employmentIds, bool $lock): array
    {
        $employmentSql = '';
        $params = [$supplierId, $periodStart];
        if ($employmentIds !== null) {
            $employmentIds = array_values(array_unique(array_filter($employmentIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
            if ($employmentIds === []) {
                return [];
            }
            $employmentSql = ' AND input.employment_id IN (' . implode(', ', array_fill(0, count($employmentIds), '?')) . ')';
            array_push($params, ...$employmentIds);
        }
        $statement = $this->db->pdo()->prepare(
            "SELECT input.id, input.status, input.employee_id, input.employment_id, input.component_id,
                    input.period_start, input.amount_minor, input.quantity_milliunits, input.external_id,
                    input.source_snapshot_json, input.row_version,
                    component.code AS component_code, component.name AS component_name, employee.full_name
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = input.supplier_id AND employee.id = input.employee_id
              WHERE input.supplier_id = ? AND input.period_start = ?
                AND input.status IN ('approved', 'locked')
                AND input.source_kind = 'absence'
                AND input.source_snapshot_json IS NOT NULL
                {$employmentSql}
              ORDER BY employee.full_name, input.employment_id, input.id" . ($lock ? ' FOR UPDATE' : ''),
        );
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dnes schválený průměr čtvrtletí období (poslední revize).
     *
     * @param list<int> $employmentIds
     * @return array<int,array{id:int,average_hourly_minor:?int,supported:bool}>
     */
    private function currentAverages(int $supplierId, string $periodStart, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $year = (int) substr($periodStart, 0, 4);
        $quarter = intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1;
        $in = implode(', ', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT id, employment_id, average_hourly_minor, support_status
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND applicable_year = ? AND applicable_quarter = ?
                AND status = 'approved' AND employment_id IN ({$in})
              ORDER BY employment_id, revision_no DESC, id DESC",
        );
        $statement->execute([$supplierId, $year, $quarter, ...$employmentIds]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            if (isset($result[$employmentId])) {
                continue;
            }
            $result[$employmentId] = [
                'id' => (int) $row['id'],
                'average_hourly_minor' => $row['average_hourly_minor'] === null ? null : (int) $row['average_hourly_minor'],
                'supported' => ($row['support_status'] ?? null) === 'supported',
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function lockedInput(int $supplierId, int $id): ?array
    {
        $statement = $this->db->pdo()->prepare('SELECT * FROM payroll_inputs WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $statement->execute([$supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $periods
     * @return list<string>
     */
    private static function normalizedPeriods(array $periods): array
    {
        $result = [];
        foreach ($periods as $period) {
            if (!is_string($period) || preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
                throw new \InvalidArgumentException('Období musí mít formát RRRR-MM.');
            }
            $result[$period] = true;
        }
        if ($result === [] || count($result) > 12) {
            throw new \InvalidArgumentException('Zadejte 1 až 12 období.');
        }
        $result = array_keys($result);
        sort($result);

        return $result;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $e;
        }
    }
}
