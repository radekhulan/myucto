<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceConflictException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEffectiveMonth;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Hromadný zápis podepsaných prohlášení poplatníka (§ 38k ZDP).
 *
 * Na začátku sezóny přinese firma balík podepsaných prohlášení a jediná cesta
 * byla karta osoby, člověk po člověku. Tahle služba zapíše „Podepsáno" vybraným
 * osobám:
 *
 *  - od měsíce, který plyne z § 38k ZDP pro den podpisu a nástup dané osoby
 *    ({@see TaxDeclarationEffectiveMonth}: do 30 dnů po nástupu od měsíce
 *    nástupu, do 15. 2. od ledna, jinak od měsíce po podpisu);
 *  - nikdy do období uzavřeného schválenou mzdou; slevu za takové měsíce
 *    dorovná roční zúčtování (`annual_settlement`);
 *  - osobu, která má na daný rok od toho měsíce podepsané prohlášení, přeskočí
 *    a její větu nepřepíše.
 *
 * Základní sleva na poplatníka vzniká podpisem sama
 * ({@see \MyInvoice\Service\Payroll\IncomeTax\TaxpayerCreditEntitlement}), ostatní
 * slevy a zvýhodnění se hromadně nezapisují.
 *
 * Zápis jde VŽDY přes PayrollPersonStatutoryEvidenceRepository::save(), tedy přes
 * tentýž validátor, zmrazení období, souvislost řady i activity log jako karta.
 */
final class PayrollTaxDeclarationBulkSign
{
    public const SECTION = 'tax_declarations';

    public const EVIDENCE_REFERENCE = 'declaration:38k-signed';

    /** Stav osoby v náhledu a změna evidence, jak je dostane klient. */
    public const STATES = ['ready', 'already_signed', 'period_frozen', 'after_year_end', 'timeline_gap', 'employee_not_found'];
    public const CHANGES = ['add', 'close_previous', 'replace'];

    private const MAX_EMPLOYEES = 2000;

    private const CHUNK_SIZE = 500;

    private const SAVEPOINT = 'payroll_tax_declaration_bulk_sign_person';

    private const NOTE = 'Prohlášení poplatníka podepsáno %s; zapsáno hromadně účetní %s';

    /** Rozpracované běhy, kterým zápis změní podklad — je třeba je přepočítat. */
    private const RECALCULATE_RUN_STATUSES = [
        'inputs_locked',
        'calculated',
        'reviewed',
        'correction_pending',
        'reopened',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
        private readonly PayrollApprovedPeriodFreeze $freeze,
        private readonly ActivityLogger $activityLogger,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Co by se u koho zapsalo a proč ne u ostatních. Nic nezapisuje.
     *
     * @param list<int|string>|null $employeeIds null = osoby s pracovním
     *     vztahem, který v daném roce trvá
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, mixed $year, mixed $signedOn, ?array $employeeIds): array
    {
        [$taxYear, $signed] = $this->normalizeInput($year, $signedOn);
        $frozenThrough = $this->freeze->frozenThrough($supplierId);
        // Souhrn platí pro stávající zaměstnance; nově nastoupivší mají první
        // měsíc podle svého nástupu v řádku osoby.
        $start = TaxDeclarationEffectiveMonth::resolve($taxYear, $signed, [], $frozenThrough);
        $requested = $employeeIds === null
            ? $this->yearEmployeeIds($supplierId, $taxYear)
            : $this->normalizeIds($employeeIds);
        $assessments = $this->assess($supplierId, $requested, $taxYear, $signed, $frozenThrough);

        $summary = [
            'people' => 0,
            'ready' => 0,
            'already_signed' => 0,
            'period_frozen' => 0,
            'after_year_end' => 0,
            'timeline_gap' => 0,
            'employee_not_found' => 0,
        ];
        $people = [];
        $ready = [];
        $earliest = null;
        foreach ($requested as $employeeId) {
            $row = $assessments[$employeeId] ?? [
                'employee_id' => $employeeId,
                'full_name' => null,
                'state' => 'employee_not_found',
                'change' => null,
                'current_status' => null,
                'effective_from' => null,
                'effective_from_basis' => null,
                'annual_settlement' => false,
                'effective_to' => null,
            ];
            $people[] = $row;
            $summary['people']++;
            $summary[$row['state']]++;
            if ($row['state'] === 'ready') {
                $ready[] = $employeeId;
                $earliest = $earliest === null ? $row['effective_from'] : min($earliest, $row['effective_from']);
            }
        }
        $recalculateFrom = $earliest ?? $start['from'];

        return [
            'year' => $taxYear,
            'signed_on' => $signed,
            'effective_from' => $start['from'],
            'effective_from_basis' => $start['basis'],
            'annual_settlement' => $start['annual_settlement'],
            'frozen_through' => $frozenThrough,
            'summary' => $summary,
            'ready_employee_ids' => $ready,
            'runs_to_recalculate' => $recalculateFrom === null
                ? []
                : $this->runsToRecalculate($supplierId, $recalculateFrom, $taxYear),
            'people' => $people,
        ];
    }

    /**
     * Zapíše podepsané prohlášení osobu po osobě.
     *
     * Každá osoba má vlastní transakci (uvnitř cizí transakce savepoint) a zámek
     * na řádku osoby; stav se posuzuje znovu pod zámkem, takže souběžná úprava
     * karty se nepřepíše. Chyba jedné osoby ostatní nezastaví.
     *
     * @param list<int|string> $employeeIds
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        mixed $year,
        mixed $signedOn,
        array $employeeIds,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        [$taxYear, $signed] = $this->normalizeInput($year, $signedOn);
        $ids = $this->normalizeIds($employeeIds);
        if ($ids === []) {
            throw new InvalidArgumentException('Vyberte aspoň jednu osobu.');
        }
        $today = (new DateTimeImmutable(PayrollSubmissionCalendar::today($this->clock->now())))->format('j. n. Y');
        $note = sprintf(
            self::NOTE,
            (new DateTimeImmutable($signed))->format('j. n. Y'),
            $today,
        );

        $applied = [];
        $skipped = [];
        $failed = [];
        foreach ($ids as $employeeId) {
            $outcome = $this->applyPerson($supplierId, $employeeId, $taxYear, $signed, $note, $userId, $ip, $userAgent);
            $item = ['employee_id' => $employeeId] + $outcome['detail'];
            if ($outcome['status'] === 'applied') {
                $applied[] = $item;
            } elseif ($outcome['status'] === 'skipped') {
                $skipped[] = $item;
            } else {
                $failed[] = $item;
            }
        }

        $this->activityLogger->log(
            'payroll.person_statutory_evidence.bulk_declaration_signed',
            $userId,
            null,
            null,
            [
                'year' => $taxYear,
                'signed_on' => $signed,
                'applied_employee_ids' => array_column($applied, 'employee_id'),
                'skipped_employee_ids' => array_column($skipped, 'employee_id'),
                'failed_employee_ids' => array_column($failed, 'employee_id'),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'year' => $taxYear,
            'signed_on' => $signed,
            'counts' => [
                'applied' => count($applied),
                'skipped' => count($skipped),
                'failed' => count($failed),
            ],
            'applied' => $applied,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{status:'applied'|'skipped'|'failed',detail:array<string,mixed>}
     */
    private function applyPerson(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $signedOn,
        string $note,
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
            if (!$this->lockEmployee($supplierId, $employeeId)) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => ['reasons' => ['employee_not_found']]];
            }
            $assessment = $this->assess(
                $supplierId,
                [$employeeId],
                $taxYear,
                $signedOn,
                $this->freeze->frozenThrough($supplierId),
            )[$employeeId] ?? null;
            if ($assessment === null) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => ['reasons' => ['employee_not_found']]];
            }
            $from = $assessment['effective_from'];
            if ($assessment['state'] !== 'ready' || $from === null) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => ['reasons' => [$assessment['state']]]];
            }

            $view = $this->evidence->editorView($supplierId, $employeeId, $from)
                ?? throw new PayrollPersonNotFoundException();
            /** @var array<string,list<array<string,mixed>>> $sections */
            $sections = $view['sections'];
            $sections[self::SECTION] = $this->plannedRows(
                $sections[self::SECTION],
                $from,
                $this->yearEnd($taxYear),
                $note,
                $signedOn,
            )['rows'];
            $this->evidence->save(
                $supplierId,
                $employeeId,
                ['sections' => $sections],
                $from,
                $userId,
                $ip,
                $userAgent,
            );
            $this->commit($owns);

            return ['status' => 'applied', 'detail' => [
                'change' => $assessment['change'],
                'effective_from' => $from,
                'effective_from_basis' => $assessment['effective_from_basis'],
                'annual_settlement' => $assessment['annual_settlement'],
                'effective_to' => $assessment['effective_to'],
            ]];
        } catch (\Throwable $exception) {
            // Odvolaná transakce (1020/1213) savepoint nemá. Vlastní transakce osoby
            // je tím vrácená, cizí musí dostat její vlastník.
            if (!TransactionFailure::rollbackIfAborted($this->db->pdo(), $exception)) {
                $this->rollback($owns);
            } elseif (!$owns) {
                throw $exception;
            }

            return ['status' => 'failed', 'detail' => [
                'message' => $this->failureMessage($exception),
            ]];
        }
    }

    /**
     * Posouzení osob množinově: dva dotazy nad celou množinou. Stejná funkce
     * slouží náhledu i zápisu (tam pro jednu osobu pod zámkem), takže náhled
     * nemůže slibovat něco jiného, než zápis udělá.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>> klíčováno ID; cizí nebo neexistující osoby chybí
     */
    private function assess(
        int $supplierId,
        array $employeeIds,
        int $taxYear,
        string $signedOn,
        ?string $frozenThrough,
    ): array {
        if ($employeeIds === []) {
            return [];
        }
        $employees = [];
        $declarations = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->rows(
                "SELECT id, full_name
                   FROM payroll_employees
                  WHERE supplier_id = ? AND id IN ({$in})",
                [$supplierId, ...$chunk],
            ) as $row) {
                $employees[(int) $row['id']] = (string) $row['full_name'];
            }
            foreach ($this->rows(
                sprintf(
                    "SELECT id, employee_id, status, effective_from, effective_to
                       FROM %s
                      WHERE supplier_id = ? AND employee_id IN ({$in})
                      ORDER BY employee_id, effective_from, id",
                    PayrollPersonStatutoryEvidenceRepository::sectionTable(self::SECTION),
                ),
                [$supplierId, ...$chunk],
            ) as $row) {
                $declarations[(int) $row['employee_id']][] = $row;
            }
        }
        $starts = $this->evidence->employmentStarts($supplierId, array_keys($employees));

        $result = [];
        foreach ($employees as $employeeId => $fullName) {
            $resolved = TaxDeclarationEffectiveMonth::resolve(
                $taxYear,
                $signedOn,
                $starts[$employeeId] ?? [],
                $frozenThrough,
            );
            $from = $resolved['from'];
            $base = [
                'employee_id' => $employeeId,
                'full_name' => $fullName,
                'change' => null,
                'current_status' => null,
                'effective_from' => null,
                'effective_from_basis' => null,
                'annual_settlement' => false,
                'effective_to' => null,
            ];
            if ($from === null) {
                $state = $resolved['legal_from'] === null ? 'after_year_end' : 'period_frozen';
                $result[$employeeId] = ['state' => $state] + $base;
                continue;
            }
            $plan = $this->plannedRows($declarations[$employeeId] ?? [], $from, $this->yearEnd($taxYear), '', $signedOn);
            $ready = $plan['state'] === 'ready';
            $result[$employeeId] = [
                'state' => $plan['state'],
                'change' => $ready ? $plan['change'] : null,
                'current_status' => $plan['current_status'],
                'effective_from' => $ready ? $from : null,
                'effective_from_basis' => $ready ? $resolved['basis'] : null,
                'annual_settlement' => $ready && $resolved['annual_settlement'],
                'effective_to' => $ready ? $plan['effective_to'] : null,
            ] + $base;
        }

        return $result;
    }

    /**
     * Cílová řada prohlášení: od `$from` podepsáno.
     *
     * - Věta, která k `$from` platí a začala dřív, se ukončí dnem před ním.
     * - Nepodepsané nebo neověřené věty, které začínají od `$from` do konce
     *   roku, nahradí podpis: první se přepíše (zůstane její historie ve
     *   verzi řádku), další se odstraní.
     * - Nová věta končí tam, kde začíná první věta po konci roku, jinak tam,
     *   kde končila poslední nahrazená; jinak je otevřená.
     *
     * Podepsaná věta kdekoli od `$from` do konce roku = osoba na rok podepsáno
     * má a přeskočí se. Mezera před `$from` (předchozí věta skončila dřív) by
     * porušila souvislost řady; tu musí rozhodnout člověk na kartě.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{state:string,change:?string,current_status:?string,effective_to:?string,rows:list<array<string,mixed>>}
     */
    private function plannedRows(array $rows, string $from, string $yearEnd, string $note, string $signedOn): array
    {
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                [(string) $left['effective_from'], (int) $left['id']]
                <=> [(string) $right['effective_from'], (int) $right['id']],
        );
        $dayBefore = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        $covering = null;
        $previous = null;
        $replaced = [];
        $after = null;
        foreach ($rows as $index => $row) {
            $rowFrom = (string) $row['effective_from'];
            $rowTo = $row['effective_to'] === null ? null : (string) $row['effective_to'];
            if ($rowFrom < $from) {
                if ($rowTo === null || $rowTo >= $from) {
                    $covering = $index;
                } else {
                    $previous = $index;
                }
                continue;
            }
            if ($rowFrom <= $yearEnd) {
                $replaced[] = $index;
                continue;
            }
            $after ??= $index;
        }

        $currentIndex = $covering ?? ($replaced !== [] && (string) $rows[$replaced[0]]['effective_from'] === $from
            ? $replaced[0]
            : null);
        $currentStatus = $currentIndex === null ? null : (string) $rows[$currentIndex]['status'];
        $outcome = [
            'state' => 'ready',
            'change' => 'add',
            'current_status' => $currentStatus,
            'effective_to' => null,
            'rows' => $rows,
        ];
        foreach ([$covering, ...$replaced] as $index) {
            if ($index !== null && (string) $rows[$index]['status'] === TaxDeclarationStatus::Signed->value) {
                return ['state' => 'already_signed'] + $outcome;
            }
        }
        if ($covering === null && $previous !== null
            && (string) $rows[$previous]['effective_to'] !== $dayBefore
        ) {
            return ['state' => 'timeline_gap'] + $outcome;
        }

        $lastReplaced = $replaced === [] ? $covering : $replaced[array_key_last($replaced)];
        $to = match (true) {
            $after !== null => (new DateTimeImmutable((string) $rows[$after]['effective_from']))
                ->modify('-1 day')
                ->format('Y-m-d'),
            $lastReplaced !== null => $rows[$lastReplaced]['effective_to'] === null
                ? null
                : (string) $rows[$lastReplaced]['effective_to'],
            default => null,
        };
        $signed = [
            'status' => TaxDeclarationStatus::Signed->value,
            'evidence_reference' => self::EVIDENCE_REFERENCE,
            'effective_from' => $from,
            'effective_to' => $to,
            'evidence_note' => $note,
            // Den podpisu nese i hromadný zápis jako karta: od 01/2027 ho
            // hlásí JMHZ (10575, IN16) a editor podle něj ověřuje první
            // měsíc věty (§ 38k ZDP, TaxDeclarationEffectiveMonth::check).
            'signed_on' => $signedOn,
        ];

        $planned = [];
        $change = $covering === null ? 'add' : 'close_previous';
        foreach ($rows as $index => $row) {
            if ($index === $covering) {
                $row['effective_to'] = $dayBefore;
            }
            if (in_array($index, $replaced, true)) {
                if ($index === $replaced[0]) {
                    $planned[] = array_merge($row, $signed);
                    $change = $covering === null ? 'replace' : $change;
                }
                continue;
            }
            $planned[] = $row;
        }
        if ($replaced === []) {
            $planned[] = $signed;
        }

        return [
            'state' => 'ready',
            'change' => $change,
            'current_status' => $currentStatus,
            'effective_to' => $to,
            'rows' => $planned,
        ];
    }

    private function yearEnd(int $taxYear): string
    {
        return sprintf('%04d-12-31', $taxYear);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function normalizeInput(mixed $year, mixed $signedOn): array
    {
        $taxYear = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if (!is_int($taxYear)) {
            throw new InvalidArgumentException('Zdaňovací rok musí být celé číslo (např. 2026).');
        }
        if (!is_string($signedOn) || !IsoDate::isValid($signedOn)) {
            throw new InvalidArgumentException('Datum podpisu musí být platné datum RRRR-MM-DD.');
        }
        if ($signedOn > PayrollSubmissionCalendar::today($this->clock->now())) {
            throw new InvalidArgumentException('Datum podpisu nesmí být v budoucnosti.');
        }
        $signedYear = (int) substr($signedOn, 0, 4);
        // Kdo nastoupil v posledních 30 dnech roku, podepisuje prohlášení na
        // ten rok ještě v lednu dalšího roku a platí mu od měsíce nástupu
        // (§ 38k odst. 1 věta poslední a odst. 4 ZDP: „do 30 dnů po vstupu
        // do zaměstnání“). Stávajícím zaměstnancům takový podpis do roku už
        // nepatří; to rozliší TaxDeclarationEffectiveMonth (`after_year_end`).
        if ($signedYear > $taxYear + 1) {
            throw new InvalidArgumentException(sprintf(
                'Prohlášení na rok %d nelze podepsat až po jeho skončení.',
                $taxYear,
            ));
        }
        if ($signedYear < $taxYear - 1) {
            throw new InvalidArgumentException(sprintf(
                'Prohlášení na rok %d se podepisuje nejdříve v předchozím roce.',
                $taxYear,
            ));
        }

        return [$taxYear, $signedOn];
    }

    /**
     * Osoby s pracovním vztahem, který v roce aspoň jeden den trvá.
     *
     * @return list<int>
     */
    private function yearEmployeeIds(int $supplierId, int $taxYear): array
    {
        $ids = [];
        foreach ($this->rows(
            "SELECT DISTINCT employment.employee_id
               FROM payroll_employments employment
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN ('archived', 'no_show')
                AND COALESCE(employment.actual_start_date, employment.start_date, '1900-01-01') <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employment.employee_id",
            [$supplierId, $this->yearEnd($taxYear), sprintf('%04d-01-01', $taxYear)],
        ) as $row) {
            $ids[] = (int) $row['employee_id'];
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw new InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d osob.',
                self::MAX_EMPLOYEES,
            ));
        }

        return $ids;
    }

    /**
     * Neschválené běhy od prvního zapisovaného měsíce do konce roku. Jejich
     * výpočet stojí na evidenci před podpisem, proto je náhled vyjmenuje.
     *
     * @return list<array{id:int,period_start:string,status:string}>
     */
    private function runsToRecalculate(int $supplierId, string $from, int $taxYear): array
    {
        $statuses = implode(', ', array_fill(0, count(self::RECALCULATE_RUN_STATUSES), '?'));
        $runs = [];
        foreach ($this->rows(
            "SELECT id, period_start, status
               FROM payroll_runs
              WHERE supplier_id = ?
                AND period_start >= ? AND period_start <= ?
                AND status IN ({$statuses})
              ORDER BY period_start, id",
            [$supplierId, $from, $this->yearEnd($taxYear), ...self::RECALCULATE_RUN_STATUSES],
        ) as $row) {
            $runs[] = [
                'id' => (int) $row['id'],
                'period_start' => (string) $row['period_start'],
                'status' => (string) $row['status'],
            ];
        }

        return $runs;
    }

    /**
     * @param list<mixed> $employeeIds
     * @return list<int>
     */
    private function normalizeIds(array $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new InvalidArgumentException('ID osoby musí být kladné celé číslo.');
            }
            $ids[$id] = true;
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw new InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d osob.',
                self::MAX_EMPLOYEES,
            ));
        }

        return array_keys($ids);
    }

    private function lockEmployee(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    private function commit(bool $owns): void
    {
        $pdo = $this->db->pdo();
        if ($owns) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    private function rollback(bool $owns): void
    {
        $pdo = $this->db->pdo();
        if ($owns) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    private function failureMessage(\Exception $exception): string
    {
        return match (true) {
            $exception instanceof PayrollPersonNotFoundException => 'Zaměstnanec nenalezen.',
            $exception instanceof PayrollPersonStatutoryEvidenceConflictException,
            $exception instanceof InvalidArgumentException => $exception->getMessage(),
            default => 'Prohlášení poplatníka se nepodařilo uložit.',
        };
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string,mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
