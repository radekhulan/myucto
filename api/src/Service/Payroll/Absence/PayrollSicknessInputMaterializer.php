<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

/**
 * Převádí výhradně již schválený výpočet DPN na schválené mzdové vstupy.
 *
 * Výpočet DPN zůstává neměnným důkazem. Jeho pozdější zrušení proto nikdy
 * nemění ani nemaže původní vstup: vytvoří samostatný záporný korekční vstup
 * se zmrazenou klasifikací původní složky.
 */
final class PayrollSicknessInputMaterializer
{
    public const COMPONENT_CODE = 'NAHRADA_MZDY_DPN';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /** @return array<string,mixed> */
    public function materialize(int $supplierId, int $sicknessEventId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $event = $this->event($supplierId, $sicknessEventId);
            $this->assertApprovedEvidence($event);
            $this->components->ensureDefaults($supplierId);

            $created = [];
            $replayed = [];
            foreach ($this->amountsByPeriod($supplierId, $sicknessEventId) as $periodStart => $amount) {
                $result = $this->materializeOriginal(
                    $supplierId,
                    $event,
                    $periodStart,
                    $amount,
                    $userId,
                );
                if ($result['created']) {
                    $created[] = $result['item'];
                } else {
                    $replayed[] = $result['item'];
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $exception;
        }

        return [
            'sickness_event_id' => $sicknessEventId,
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'created' => $created,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string,mixed> */
    public function reverseForAbsence(int $supplierId, int $absenceId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $originals = $this->originalMaterializations($supplierId, $absenceId);
            $created = [];
            $replayed = [];
            foreach ($originals as $original) {
                $result = $this->materializeReversal($supplierId, $original, $userId);
                if ($result['created']) {
                    $created[] = $result['item'];
                } else {
                    $replayed[] = $result['item'];
                }
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $exception;
        }

        return [
            'absence_id' => $absenceId,
            'created_count' => count($created),
            'replayed_count' => count($replayed),
            'created' => $created,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string,mixed> */
    private function materializeOriginal(
        int $supplierId,
        array $event,
        string $periodStart,
        int $amountMinor,
        ?int $userId,
    ): array {
        $eventId = PayrollTimeValue::int($event['id'] ?? null, 'sickness_event_id');
        $existing = $this->materialization($supplierId, $eventId, $periodStart, 'original');
        if ($existing !== null) {
            return ['created' => false, 'item' => $this->item($existing)];
        }

        $componentId = $this->componentId($supplierId, $periodStart);
        $externalId = "sickness:{$eventId}:{$periodStart}:original";
        $input = $this->createOrFindDraftInput(
            $supplierId,
            PayrollTimeValue::int($event['employee_id'] ?? null, 'employee_id'),
            PayrollTimeValue::int($event['employment_id'] ?? null, 'employment_id'),
            $componentId,
            $periodStart,
            $amountMinor,
            'absence',
            $externalId,
            $userId,
        );
        if ($input['status'] === 'draft') {
            $input = $this->inputs->approve(
                $supplierId,
                PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
                PayrollTimeValue::int($input['row_version'] ?? null, 'input_row_version'),
                $userId,
            ) ?? throw new \RuntimeException('Schválený vstup náhrady DPN nebyl nalezen.');
        }
        if ($input['status'] !== 'approved') {
            throw new \DomainException('Vstup náhrady DPN není schválený.');
        }

        $materialization = $this->insertMaterialization(
            $supplierId,
            $eventId,
            $periodStart,
            'original',
            PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
            null,
            $this->originalSourceSnapshot($event, $periodStart, $amountMinor),
            $userId,
        );

        return ['created' => true, 'item' => $this->item($materialization)];
    }

    /** @param array<string,mixed> $original @return array<string,mixed> */
    private function materializeReversal(int $supplierId, array $original, ?int $userId): array
    {
        $eventId = PayrollTimeValue::int($original['sickness_event_id'] ?? null, 'sickness_event_id');
        $periodStart = PayrollTimeValue::string($original['period_start'] ?? null, 'period_start');
        $existing = $this->materialization($supplierId, $eventId, $periodStart, 'reversal');
        if ($existing !== null) {
            return ['created' => false, 'item' => $this->item($existing)];
        }
        $input = $this->originalInput($supplierId, PayrollTimeValue::int(
            $original['input_id'] ?? null,
            'input_id',
        ));
        $amount = -PayrollTimeValue::int($input['amount_minor'] ?? null, 'amount_minor');
        if ($amount >= 0) {
            throw new \DomainException('Původní vstup náhrady DPN musí být kladný.');
        }
        $eventId = PayrollTimeValue::int($original['sickness_event_id'] ?? null, 'sickness_event_id');
        $externalId = "sickness:{$eventId}:{$periodStart}:reversal";
        $reversalInputId = $this->insertReversalInput(
            $supplierId,
            $input,
            $periodStart,
            $amount,
            $externalId,
            $userId,
        );
        $materialization = $this->insertMaterialization(
            $supplierId,
            $eventId,
            $periodStart,
            'reversal',
            $reversalInputId,
            PayrollTimeValue::int($original['id'] ?? null, 'materialization_id'),
            [
                'kind' => 'sickness_compensation_reversal.v1',
                'sickness_event_id' => $eventId,
                'period_start' => $periodStart,
                'reverses_materialization_id' => PayrollTimeValue::int(
                    $original['id'] ?? null,
                    'materialization_id',
                ),
                'reverses_input_id' => PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
                'amount_minor' => $amount,
            ],
            $userId,
        );

        return ['created' => true, 'item' => $this->item($materialization)];
    }

    /** @return array<string,mixed> */
    private function event(int $supplierId, int $eventId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT sickness.*, absence.status AS absence_status,
                    absence.employment_id, employment.employee_id
               FROM payroll_sickness_events sickness
               JOIN payroll_absences absence
                 ON absence.supplier_id = sickness.supplier_id
                AND absence.id = sickness.absence_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = absence.supplier_id
                AND employment.id = absence.employment_id
              WHERE sickness.supplier_id = ? AND sickness.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \OutOfBoundsException('Výpočet DPN nebyl nalezen.');
        }

        return $row;
    }

    /** @param array<string,mixed> $event */
    private function assertApprovedEvidence(array $event): void
    {
        if (($event['absence_status'] ?? null) !== 'approved') {
            throw new \DomainException('Do mzdy lze promítnout jen schválenou DPN.');
        }
        if ((int) ($event['insurance_eligibility_confirmed'] ?? 0) !== 1
            || (int) ($event['conflicting_benefit_excluded'] ?? 0) !== 1
        ) {
            throw new \DomainException(
                'DPN nemá doloženou účast na pojištění a vyloučení souběžné dávky.',
            );
        }
    }

    /** @return array<string,int> */
    private function amountsByPeriod(int $supplierId, int $eventId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(local_date, "%Y-%m-01") AS period_start,
                    SUM(compensation_minor) AS amount_minor
               FROM payroll_sickness_compensation_segments
              WHERE supplier_id = ? AND sickness_event_id = ?
              GROUP BY DATE_FORMAT(local_date, "%Y-%m-01")
              ORDER BY period_start
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $eventId]);
        $amounts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor');
            if ($amount <= 0) {
                throw new \DomainException('Náhrada DPN musí být kladná.');
            }
            $amounts[PayrollTimeValue::string($row['period_start'] ?? null, 'period_start')] = $amount;
        }
        if ($amounts === []) {
            throw new \DomainException('DPN nemá segmenty náhrady k promítnutí do mzdy.');
        }

        return $amounts;
    }

    /** @return array<string,mixed>|null */
    private function materialization(
        int $supplierId,
        int $eventId,
        string $periodStart,
        string $kind,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_input_materializations
              WHERE supplier_id = ? AND sickness_event_id = ?
                AND period_start = ? AND materialization_kind = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $eventId, $periodStart, $kind]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function originalMaterializations(int $supplierId, int $absenceId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT materialization.*
               FROM payroll_sickness_input_materializations materialization
               JOIN payroll_sickness_events sickness
                 ON sickness.supplier_id = materialization.supplier_id
                AND sickness.id = materialization.sickness_event_id
              WHERE materialization.supplier_id = ?
                AND sickness.absence_id = ?
                AND materialization.materialization_kind = "original"
              ORDER BY materialization.id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $absenceId]);

        return PayrollTimeValue::rows(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'sickness_input_materializations',
        );
    }

    /** @return array<string,mixed> */
    private function createOrFindDraftInput(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        int $componentId,
        string $periodStart,
        int $amountMinor,
        string $sourceKind,
        string $externalId,
        ?int $userId,
    ): array {
        try {
            return $this->inputs->create($supplierId, [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $periodStart,
                'source_period_start' => null,
                'amount_minor' => $amountMinor,
                'quantity_milliunits' => null,
                'source_kind' => $sourceKind,
                'external_id' => $externalId,
            ], $userId);
        } catch (\InvalidArgumentException $exception) {
            $existing = $this->inputByExternalId(
                $supplierId,
                $employmentId,
                $periodStart,
                $sourceKind,
                $externalId,
            );
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /** @return array<string,mixed>|null */
    private function inputByExternalId(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $sourceKind,
        string $externalId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = ? AND external_id = ? AND status <> "cancelled"
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employmentId, $periodStart, $sourceKind, $externalId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function componentId(int $supplierId, string $periodStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',
        );
        $statement->execute([$supplierId, self::COMPONENT_CODE, $periodStart, $periodStart]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \DomainException('Pro náhradu DPN chybí účinná mzdová složka NAHRADA_MZDY_DPN.');
        }

        return PayrollTimeValue::int($id, 'component_id');
    }

    /** @return array<string,mixed> */
    private function originalInput(int $supplierId, int $inputId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND id = ? AND status IN ("approved", "locked")
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $inputId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Původní vstup náhrady DPN není dostupný k reverzi.');
        }
        if ($row['component_snapshot_json'] === null || $row['component_snapshot_hash'] === null) {
            throw new \DomainException('Původní vstup náhrady DPN nemá zmrazenou klasifikaci.');
        }

        return $row;
    }

    private function insertReversalInput(
        int $supplierId,
        array $originalInput,
        string $periodStart,
        int $amountMinor,
        string $externalId,
        ?int $userId,
    ): int {
        $existing = $this->inputByExternalId(
            $supplierId,
            PayrollTimeValue::int($originalInput['employment_id'] ?? null, 'employment_id'),
            $periodStart,
            'correction',
            $externalId,
        );
        if ($existing !== null) {
            if ($existing['status'] !== 'approved') {
                throw new \DomainException('Existující korekce DPN není schválená.');
            }
            return PayrollTimeValue::int($existing['id'] ?? null, 'input_id');
        }
        try {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id, period_start,
                     source_period_start, amount_minor, source_kind, external_id, status,
                     component_snapshot_json, component_snapshot_hash, created_by,
                     approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "correction", ?, "approved", ?, ?, ?, ?, NOW())',
            );
            $statement->execute([
                $supplierId,
                PayrollTimeValue::int($originalInput['employee_id'] ?? null, 'employee_id'),
                PayrollTimeValue::int($originalInput['employment_id'] ?? null, 'employment_id'),
                PayrollTimeValue::int($originalInput['component_id'] ?? null, 'component_id'),
                $periodStart,
                $periodStart,
                $amountMinor,
                $externalId,
                $originalInput['component_snapshot_json'],
                $originalInput['component_snapshot_hash'],
                $userId,
                $userId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->inputByExternalId(
                $supplierId,
                PayrollTimeValue::int($originalInput['employment_id'] ?? null, 'employment_id'),
                $periodStart,
                'correction',
                $externalId,
            );
            if ($existing === null || $existing['status'] !== 'approved') {
                throw $exception;
            }
            return PayrollTimeValue::int($existing['id'] ?? null, 'input_id');
        }

        return PayrollTimeValue::int($this->db->pdo()->lastInsertId(), 'input_id');
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function insertMaterialization(
        int $supplierId,
        int $eventId,
        string $periodStart,
        string $kind,
        int $inputId,
        ?int $reversesMaterializationId,
        array $snapshot,
        ?int $userId,
    ): array {
        $json = CanonicalJson::encode($snapshot);
        try {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_sickness_input_materializations
                    (supplier_id, sickness_event_id, period_start, materialization_kind,
                     input_id, reverses_materialization_id, source_snapshot_json,
                     source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $eventId,
                $periodStart,
                $kind,
                $inputId,
                $reversesMaterializationId,
                $json,
                hash('sha256', $json, true),
                $userId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        return $this->materialization($supplierId, $eventId, $periodStart, $kind)
            ?? throw new \RuntimeException('Materializaci náhrady DPN se nepodařilo uložit.');
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function originalSourceSnapshot(array $event, string $periodStart, int $amountMinor): array
    {
        return [
            'kind' => 'sickness_compensation.v1',
            'sickness_event_id' => PayrollTimeValue::int($event['id'] ?? null, 'sickness_event_id'),
            'absence_id' => PayrollTimeValue::int($event['absence_id'] ?? null, 'absence_id'),
            'period_start' => $periodStart,
            'amount_minor' => $amountMinor,
            'average_snapshot_id' => PayrollTimeValue::int(
                $event['average_snapshot_id'] ?? null,
                'average_snapshot_id',
            ),
            'compensation_window_from' => PayrollTimeValue::string(
                $event['compensation_window_from'] ?? null,
                'compensation_window_from',
            ),
            'compensation_window_to' => PayrollTimeValue::string(
                $event['compensation_window_to'] ?? null,
                'compensation_window_to',
            ),
            'reduced_hourly_minor' => PayrollTimeValue::int(
                $event['reduced_hourly_minor'] ?? null,
                'reduced_hourly_minor',
            ),
            'ruleset_id' => PayrollTimeValue::string($event['ruleset_id'] ?? null, 'ruleset_id'),
            'ruleset_hash' => PayrollTimeValue::string($event['ruleset_hash'] ?? null, 'ruleset_hash'),
            'calculation_trace' => json_decode(
                PayrollTimeValue::string($event['calculation_trace'] ?? null, 'calculation_trace'),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function item(array $row): array
    {
        return [
            'materialization_id' => PayrollTimeValue::int($row['id'] ?? null, 'materialization_id'),
            'input_id' => PayrollTimeValue::int($row['input_id'] ?? null, 'input_id'),
            'period_start' => PayrollTimeValue::string($row['period_start'] ?? null, 'period_start'),
            'kind' => PayrollTimeValue::string($row['materialization_kind'] ?? null, 'materialization_kind'),
        ];
    }

    private function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
