<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Hromadné ukončení skončených dohod a archivace dávno ukončených vztahů.
 *
 * Brigádníci na DPP/DPČ mají většinou krátké dohody a ruční ukončení po
 * jednom nikdo nestíhá, takže se seznam vztahů plní lidmi, kteří už dávno
 * nepracují. Náhled nabízí tři skupiny:
 *
 * - `expired`: aktivní (nebo přerušený) vztah, jehož sjednaný konec
 *   (`payroll_employments.end_date`, při založení převzatý z
 *   `fixed_term_end_on` podmínek) už uplynul → ukončit k tomu dni;
 * - `inactive`: aktivní DPP/DPČ bez sjednaného konce, u které za posledních
 *   N měsíců (včetně běžného) není nenulový mzdový vstup ani docházka →
 *   ukončit k poslednímu dni měsíce poslední aktivity;
 * - `archive`: ukončený vztah s koncem starším než X měsíců → archivovat.
 *
 * Aktivitou je nezrušený mzdový vstup s nenulovou částkou nebo množstvím
 * a nepřepsaný záznam docházky. Řádek mzdového běhu aktivitou NENÍ: trvající
 * dohoda bez práce dostává v běhu nulový řádek (nulové hlášení JMHZ), takže
 * by každou dohodu držel věčně „aktivní".
 *
 * Zápis jde po položkách přes {@see PayrollEmploymentRepository::transition()}
 * — tutéž cestu jako ruční ukončení na kartě vztahu, s kontrolou povoleného
 * přechodu, data proti nástupu, verze řádku, kontrolním seznamem odchodu
 * (odhláška, výstupní doklady) a auditní stopou. Navíc se odmítne ukončení,
 * po jehož datu má vztah ještě mzdový vstup, docházku nebo zúčtovaný běh:
 * takový vztah zjevně trval dál a datum konce musí posoudit člověk.
 */
final class PayrollEmploymentBulkClosure
{
    public const MAX_ITEMS = 500;

    /** Skupiny kandidátů a akce, jak je dostane klient. */
    public const GROUPS = ['expired', 'inactive', 'archive'];
    public const ACTIONS = ['end', 'archive'];

    private const SETTLED_RUN_STATUSES = "'posted', 'payment_ready', 'paid', 'closed'";

    private const NOTE_END = 'Hromadné ukončení skončených dohod.';
    private const NOTE_ARCHIVE = 'Hromadná archivace ukončených vztahů.';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Kandidáti na ukončení a archivaci. Nic nezapisuje.
     *
     * @return array<string,mixed>
     */
    public function preview(
        int $supplierId,
        int $inactivityMonths,
        int $archiveAfterMonths,
        ?string $today = null,
    ): array {
        $today = $this->today($today);
        if ($inactivityMonths < 1 || $inactivityMonths > 24) {
            throw new \InvalidArgumentException('Počet měsíců bez aktivity musí být 1 až 24.');
        }
        if ($archiveAfterMonths < 1 || $archiveAfterMonths > 120) {
            throw new \InvalidArgumentException('Počet měsíců před archivací musí být 1 až 120.');
        }
        $todayDate = new \DateTimeImmutable($today);
        // Běžný měsíc je jedním z N sledovaných: při N = 3 a dnešku v říjnu
        // se hledá aktivita od 1. srpna.
        $activitySince = $todayDate->modify('first day of this month')
            ->modify('-' . ($inactivityMonths - 1) . ' months')
            ->format('Y-m-d');
        $archiveBefore = $todayDate->modify('-' . $archiveAfterMonths . ' months')->format('Y-m-d');

        $stmt = $this->db->pdo()->prepare(
            "SELECT candidate.*
               FROM (
                    SELECT employment.id, employment.employee_id, employment.code,
                           employment.relation_type, employment.status, employment.row_version,
                           COALESCE(employment.actual_start_date, employment.start_date) AS start_on,
                           employment.end_date, employee.full_name,
                           CASE
                               WHEN employment.status IN ('active', 'suspended')
                                AND employment.end_date IS NOT NULL
                                AND employment.end_date < ?
                               THEN 'expired'
                               WHEN employment.status IN ('active')
                                AND employment.end_date IS NULL
                                AND employment.relation_type IN ('dpp', 'dpc')
                                AND COALESCE(employment.actual_start_date, employment.start_date) < ?
                               THEN 'inactive'
                               WHEN employment.status IN ('ended')
                                AND employment.end_date IS NOT NULL
                                AND employment.end_date < ?
                               THEN 'archive'
                           END AS candidate_group,
                           " . $this->activityColumns('employment') . "
                      FROM payroll_employments employment
                      JOIN payroll_employees employee
                        ON employee.supplier_id = employment.supplier_id
                       AND employee.id = employment.employee_id
                     WHERE employment.supplier_id = ?
                       AND employment.status IN ('active', 'suspended', 'ended')
               ) candidate
              WHERE candidate.candidate_group IS NOT NULL
                AND (
                    candidate.candidate_group <> 'inactive'
                    OR (
                        COALESCE(candidate.last_input_period, '1000-01-01') < ?
                        AND COALESCE(candidate.last_time_entry_at, '1000-01-01 00:00:00') < ?
                    )
                )
              ORDER BY candidate.full_name, candidate.code, candidate.id",
        );
        $stmt->execute([
            $today,
            $activitySince,
            $archiveBefore,
            $supplierId,
            $activitySince,
            $activitySince . ' 00:00:00',
        ]);

        $summary = ['expired' => 0, 'inactive' => 0, 'archive' => 0, 'blocked' => 0];
        $items = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $group = (string) $row['candidate_group'];
            $lastActivity = $this->lastActivityMonth($row);
            $lastSettled = is_string($row['last_settled_period'] ?? null)
                ? substr($row['last_settled_period'], 0, 7)
                : null;
            $proposedOn = match ($group) {
                'expired' => (string) $row['end_date'],
                'inactive' => min($today, $this->inactiveEndDate(
                    $lastActivity,
                    $lastSettled,
                    (string) $row['start_on'],
                )),
                default => $today,
            };
            $blocked = $group === 'archive' ? null : $this->blocker($row, $proposedOn);
            $summary[$group]++;
            if ($blocked !== null) {
                $summary['blocked']++;
            }
            $items[] = [
                'employment_id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'employment_code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'status' => (string) $row['status'],
                'row_version' => (int) $row['row_version'],
                'group' => $group,
                'action' => $group === 'archive' ? 'archive' : 'end',
                'start_on' => $row['start_on'] === null ? null : (string) $row['start_on'],
                'agreed_end_on' => $row['end_date'] === null ? null : (string) $row['end_date'],
                'last_activity_period' => $lastActivity,
                'last_settled_period' => $lastSettled,
                'proposed_on' => $proposedOn,
                'blocked_reason' => $blocked['reason'] ?? null,
                'blocked_period' => $blocked['period'] ?? null,
            ];
        }

        return [
            'as_of' => $today,
            'inactivity_months' => $inactivityMonths,
            'archive_after_months' => $archiveAfterMonths,
            'activity_since' => $activitySince,
            'archive_before' => $archiveBefore,
            'max_items' => self::MAX_ITEMS,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Ukončí nebo archivuje vybrané vztahy, každý vlastním přechodem stavu.
     * Chyba jedné položky ostatní nezastaví.
     *
     * @param list<mixed> $items
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        array $items,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        ?string $today = null,
        int $inactivityMonths = 3,
        int $archiveAfterMonths = 12,
    ): array {
        $today = $this->today($today);
        $commands = $this->commands($items, $today);
        // Pravidla náhledu platí i pro zápis: vztah, který kandidátem není (nebo má
        // jinou akci), se hromadně neukončí, ani kdyby ho klient poslal.
        $candidates = [];
        foreach ($this->preview($supplierId, $inactivityMonths, $archiveAfterMonths, $today)['items'] as $candidate) {
            $candidates[(int) $candidate['employment_id']] = (string) $candidate['action'];
        }
        $activity = $this->activityFor(
            $supplierId,
            array_values(array_map(
                static fn (array $command): int => $command['employment_id'],
                array_filter($commands, static fn (array $command): bool => $command['action'] === 'end'),
            )),
        );

        $applied = [];
        $failed = [];
        foreach ($commands as $command) {
            $employmentId = $command['employment_id'];
            if (($candidates[$employmentId] ?? null) !== $command['action']) {
                $failed[] = [
                    'employment_id' => $employmentId,
                    'code' => 'not_candidate',
                    'message' => 'Vztah už mezi navrženými k hromadnému ukončení není. Načtěte náhled znovu.',
                ];
                continue;
            }
            if ($command['action'] === 'end' && isset($activity[$employmentId])) {
                $blocked = $this->blocker($activity[$employmentId], $command['effective_on']);
                if ($blocked !== null) {
                    $failed[] = [
                        'employment_id' => $employmentId,
                        'code' => $blocked['reason'],
                        'message' => $this->blockerMessage($blocked['reason'], $blocked['period']),
                    ];
                    continue;
                }
            }
            try {
                $employment = $this->employments->transition(
                    $supplierId,
                    $employmentId,
                    $command['action'] === 'end' ? 'ended' : 'archived',
                    $command['row_version'],
                    $command['effective_on'],
                    $command['action'] === 'end' ? self::NOTE_END : self::NOTE_ARCHIVE,
                    $userId,
                    $ip,
                    $userAgent,
                );
                $applied[] = [
                    'employment_id' => $employmentId,
                    'status' => (string) $employment['status'],
                    'effective_on' => $command['effective_on'],
                ];
            } catch (PayrollEmploymentConflictException $exception) {
                $failed[] = [
                    'employment_id' => $employmentId,
                    'code' => 'row_version_conflict',
                    'message' => 'Vztah mezitím změnil někdo jiný. Načtěte náhled znovu.',
                ];
            } catch (PayrollEmploymentNotFoundException $exception) {
                $failed[] = ['employment_id' => $employmentId, 'code' => 'not_found', 'message' => $exception->getMessage()];
            } catch (\DomainException $exception) {
                $failed[] = ['employment_id' => $employmentId, 'code' => 'invalid_transition', 'message' => $exception->getMessage()];
            } catch (\InvalidArgumentException $exception) {
                $failed[] = ['employment_id' => $employmentId, 'code' => 'validation_failed', 'message' => $exception->getMessage()];
            } catch (\PDOException|\RuntimeException $exception) {
                // Přechod běží ve vlastní transakci a při chybě ji sám vrátí. Uvnitř cizí
                // transakce chybu nespolkneme, opakování řeší její vlastník.
                if ($this->db->pdo()->inTransaction()) {
                    throw $exception;
                }
                $failed[] = [
                    'employment_id' => $employmentId,
                    'code' => 'transition_failed',
                    'message' => 'Vztah se nepodařilo ukončit, zkuste to znovu.',
                ];
            }
        }

        $this->activityLogger->log(
            'payroll.employment.bulk_closure',
            $userId,
            null,
            null,
            [
                'ended_employment_ids' => array_values(array_map(
                    static fn (array $item): int => $item['employment_id'],
                    array_filter($applied, static fn (array $item): bool => $item['status'] === 'ended'),
                )),
                'archived_employment_ids' => array_values(array_map(
                    static fn (array $item): int => $item['employment_id'],
                    array_filter($applied, static fn (array $item): bool => $item['status'] === 'archived'),
                )),
                'failed_employment_ids' => array_column($failed, 'employment_id'),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'counts' => ['applied' => count($applied), 'failed' => count($failed)],
            'applied' => $applied,
            'failed' => $failed,
        ];
    }

    /**
     * Sloupce poslední aktivity a posledního zúčtovaného běhu vztahu.
     * Každý poddotaz jde po indexu (supplier_id, employment_id, …); u vstupů
     * je index vynucený, jinak optimalizátor u malé tabulky sáhne po
     * (supplier_id, id) a s růstem by procházel všechny vstupy firmy.
     */
    private function activityColumns(string $alias): string
    {
        return "(SELECT MAX(input.period_start)
                   FROM payroll_inputs input FORCE INDEX (uq_payroll_input_external)
                  WHERE input.supplier_id = {$alias}.supplier_id
                    AND input.employment_id = {$alias}.id
                    AND input.status <> 'cancelled'
                    AND (input.amount_minor <> 0 OR COALESCE(input.quantity_milliunits, 0) <> 0)
                ) AS last_input_period,
                (SELECT MAX(entry.starts_at_utc)
                   FROM payroll_time_entries entry
                  WHERE entry.supplier_id = {$alias}.supplier_id
                    AND entry.employment_id = {$alias}.id
                    AND entry.status <> 'superseded'
                ) AS last_time_entry_at,
                (SELECT MAX(run_employment.period_start)
                   FROM payroll_run_employments run_employment
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = run_employment.supplier_id
                    AND revision.id = run_employment.revision_id
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE run_employment.supplier_id = {$alias}.supplier_id
                    AND run_employment.employment_id = {$alias}.id
                    AND run.status IN (" . self::SETTLED_RUN_STATUSES . ")
                ) AS last_settled_period";
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array<string,mixed>>
     */
    private function activityFor(int $supplierId, array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, ' . $this->activityColumns('employment') . '
               FROM payroll_employments employment
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . implode(', ', array_fill(0, count($employmentIds), '?')) . ')',
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $result = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    /**
     * Co brání ukončení k danému dni: práce nebo zúčtovaná mzda po něm.
     *
     * @param array<string,mixed> $row
     * @return array{reason:string,period:string}|null
     */
    private function blocker(array $row, string $endOn): ?array
    {
        $settled = $row['last_settled_period'] ?? null;
        if (is_string($settled) && $settled > $endOn) {
            return ['reason' => 'settled_after_end', 'period' => substr($settled, 0, 7)];
        }
        $input = $row['last_input_period'] ?? null;
        if (is_string($input) && $input > $endOn) {
            return ['reason' => 'activity_after_end', 'period' => substr($input, 0, 7)];
        }
        $entry = $row['last_time_entry_at'] ?? null;
        if (is_string($entry) && substr($entry, 0, 10) > $endOn) {
            return ['reason' => 'activity_after_end', 'period' => substr($entry, 0, 7)];
        }

        return null;
    }

    private function blockerMessage(string $reason, string $period): string
    {
        return $reason === 'settled_after_end'
            ? "Vztah má zúčtovanou nebo vyplacenou mzdu za {$period}, tedy po datu konce. Ukončete ho ručně na kartě vztahu k datu, kdy skutečně skončil."
            : "Vztah má mzdový vstup nebo docházku za {$period}, tedy po datu konce. Ukončete ho ručně na kartě vztahu k datu, kdy skutečně skončil.";
    }

    /** @param array<string,mixed> $row */
    private function lastActivityMonth(array $row): ?string
    {
        $months = [];
        if (is_string($row['last_input_period'] ?? null)) {
            $months[] = substr($row['last_input_period'], 0, 7);
        }
        if (is_string($row['last_time_entry_at'] ?? null)) {
            $months[] = substr($row['last_time_entry_at'], 0, 7);
        }

        return $months === [] ? null : max($months);
    }

    /**
     * Poslední den měsíce poslední aktivity; bez jakékoli aktivity poslední
     * den měsíce nástupu. Nikdy dřív než nástup.
     *
     * Je-li už zúčtovaný pozdější měsíc (dohoda v něm měla nulový řádek
     * a odešlo za ni nulové hlášení JMHZ), konec se posune na jeho konec:
     * ukončení před vykázaným měsícem by odporovalo tomu, co už odešlo ven.
     */
    private function inactiveEndDate(?string $lastActivityMonth, ?string $lastSettledMonth, string $startOn): string
    {
        $months = array_filter([$lastActivityMonth, $lastSettledMonth], static fn (?string $month): bool => $month !== null);
        $month = $months === [] ? substr($startOn, 0, 7) : max($months);
        $end = (new \DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');

        return max($end, $startOn);
    }

    /**
     * @param list<mixed> $items
     * @return list<array{employment_id:int,action:'end'|'archive',effective_on:string,row_version:int}>
     */
    private function commands(array $items, string $today): array
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden pracovní vztah.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d pracovních vztahů.',
                self::MAX_ITEMS,
            ));
        }
        $commands = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Položka musí být objekt.');
            }
            $id = filter_var($item['employment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new \InvalidArgumentException('ID pracovního vztahu musí být kladné celé číslo.');
            }
            if (isset($commands[$id])) {
                throw new \InvalidArgumentException('Pracovní vztah je ve výběru vícekrát.');
            }
            $action = $item['action'] ?? null;
            if ($action !== 'end' && $action !== 'archive') {
                throw new \InvalidArgumentException('Akce musí být end nebo archive.');
            }
            $version = filter_var($item['row_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($version)) {
                throw new \InvalidArgumentException('row_version musí být kladné celé číslo.');
            }
            $effectiveOn = $item['effective_on'] ?? null;
            if (!is_string($effectiveOn) || !IsoDate::isValid($effectiveOn)) {
                throw new \InvalidArgumentException('Datum musí být platné datum YYYY-MM-DD.');
            }
            if ($effectiveOn > $today) {
                throw new \InvalidArgumentException(
                    'Hromadně lze ukončit nebo archivovat jen k dnešku nebo ke dni v minulosti.',
                );
            }
            $commands[$id] = [
                'employment_id' => $id,
                'action' => $action,
                'effective_on' => $effectiveOn,
                'row_version' => $version,
            ];
        }

        return array_values($commands);
    }

    private function today(?string $today): string
    {
        if ($today === null) {
            return (new \DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (!IsoDate::isValid($today)) {
            throw new \InvalidArgumentException('Datum musí být platné datum YYYY-MM-DD.');
        }

        return $today;
    }
}
