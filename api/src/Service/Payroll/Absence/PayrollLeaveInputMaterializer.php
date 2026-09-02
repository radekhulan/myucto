<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

/**
 * Převádí schválené čerpání dovolené na mzdový vstup náhrady (§ 222 odst. 1 ZP).
 *
 * Kniha dovolené (`payroll_leave_ledger`) zůstává evidencí NÁROKU a ČERPÁNÍ,
 * tedy hodin. Peníze do ní nepatří a nikdy nepatřily — proto se na pásce
 * neobjevoval řádek s náhradou: nebylo z čeho ho vzít. Tenhle materializátor
 * je druhou, peněžní půlkou téhož schválení.
 *
 * Sourozenec {@see PayrollSicknessInputMaterializer} a záměrně stejný tvar:
 * zrušení dovolené původní vstup nikdy nemění ani nemaže, jen k němu přidá
 * záporný korekční vstup ve stejném období. Oprava nároku patří TOMU MĚSÍCI,
 * ve kterém nárok vznikl; přesunout ji dopředu by zkreslilo oba měsíce a
 * neodpovídalo by ani opravnému měsíčnímu hlášení.
 *
 * Idempotenci drží `external_id` (`leave:{absence}:{období}:{druh}`) nad
 * unikátním klíčem `uq_payroll_input_external`; vlastní tabulka materializací
 * tu proto není. U nemoci ji vynutil samostatný výpočetní doklad
 * (`payroll_sickness_events`), který dovolená nemá — celý její podklad se
 * vejde do stopy vstupu.
 */
final class PayrollLeaveInputMaterializer
{
    public const COMPONENT_CODE = 'NAHRADA_MZDY_DOVOLENA';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
    ) {}

    /**
     * @param array<string,mixed> $absence schválená absence druhu `vacation`
     * @return array<string,mixed>
     */
    public function materialize(int $supplierId, array $absence, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->materializeInTransaction($supplierId, $absence, $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $absence
     * @return array<string,mixed>
     */
    private function materializeInTransaction(int $supplierId, array $absence, ?int $userId): array
    {
        if (($absence['absence_type'] ?? null) !== 'vacation') {
            throw new \DomainException('Náhradu za dovolenou lze promítnout jen z dovolené.');
        }
        $averageHourly = PayrollTimeValue::int(
            $absence['average_hourly_minor'] ?? null,
            'average_hourly_minor',
        );
        $absenceId = PayrollTimeValue::int($absence['id'] ?? null, 'absence_id');
        $this->components->ensureDefaults($supplierId);

        // § 219 odst. 1 ZP — svátek uvnitř dovolené se nečerpá, takže se za něj
        // ani náhrada neposkytuje. Tytéž segmenty, ze kterých kniha dovolené
        // odečetla hodiny; jiný podklad by rozešel hodiny s penězi.
        $segments = $this->absences->publishedShiftSegments(
            $absence,
            false,
            AbsenceHolidayTreatment::ExcludeFromLeave,
        );
        if ($segments === []) {
            return ['absence_id' => $absenceId, 'created' => [], 'replayed' => []];
        }
        $result = LeaveCompensationCalculator::calculate($averageHourly, $segments);

        $created = [];
        $replayed = [];
        foreach ($result->amountsByPeriod as $period => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $externalId = "leave:{$absenceId}:{$period}:original";
            $existing = $this->inputByExternalId(
                $supplierId,
                PayrollTimeValue::int($absence['employment_id'] ?? null, 'employment_id'),
                $period,
                'absence',
                $externalId,
            );
            if ($existing !== null) {
                $replayed[] = ['period_start' => $period, 'input_id' => (int) $existing['id']];
                continue;
            }
            $snapshot = CanonicalJson::encode($result->trace(
                $period,
                $absenceId,
                PayrollTimeValue::int($absence['average_snapshot_id'] ?? null, 'average_snapshot_id'),
            ));
            $input = $this->inputs->create($supplierId, [
                'employee_id' => $this->employeeId($supplierId, $absence),
                'employment_id' => PayrollTimeValue::int(
                    $absence['employment_id'] ?? null,
                    'employment_id',
                ),
                'component_id' => $this->componentId($supplierId, $period),
                'period_start' => $period,
                'source_period_start' => null,
                'amount_minor' => $amount,
                'quantity_milliunits' => intdiv(
                    $result->minutesByPeriod[$period] * 1000,
                    60,
                ),
                'source_kind' => 'absence',
                'external_id' => $externalId,
                'source_snapshot_json' => $snapshot,
                'source_snapshot_hash' => hash('sha256', $snapshot, true),
            ], $userId);
            $approved = $this->inputs->approve(
                $supplierId,
                PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
                PayrollTimeValue::int($input['row_version'] ?? null, 'input_row_version'),
                $userId,
            ) ?? throw new \RuntimeException('Schválený vstup náhrady za dovolenou nebyl nalezen.');
            if ($approved['status'] !== 'approved') {
                throw new \DomainException('Vstup náhrady za dovolenou není schválený.');
            }
            $created[] = [
                'period_start' => $period,
                'input_id' => PayrollTimeValue::int($approved['id'] ?? null, 'input_id'),
                'amount_minor' => $amount,
            ];
        }

        return [
            'absence_id' => $absenceId,
            'average_hourly_minor' => $averageHourly,
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
            $result = $this->reverseInTransaction($supplierId, $absenceId, $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function reverseInTransaction(int $supplierId, int $absenceId, ?int $userId): array
    {
        $created = [];
        $replayed = [];
        foreach ($this->originalInputs($supplierId, $absenceId) as $original) {
            $period = PayrollTimeValue::string($original['period_start'] ?? null, 'period_start');
            $externalId = "leave:{$absenceId}:{$period}:reversal";
            $employmentId = PayrollTimeValue::int(
                $original['employment_id'] ?? null,
                'employment_id',
            );
            $existing = $this->inputByExternalId(
                $supplierId,
                $employmentId,
                $period,
                'correction',
                $externalId,
            );
            if ($existing !== null) {
                $replayed[] = ['period_start' => $period, 'input_id' => (int) $existing['id']];
                continue;
            }
            $amount = -PayrollTimeValue::int($original['amount_minor'] ?? null, 'amount_minor');
            if ($amount >= 0) {
                throw new \DomainException('Původní vstup náhrady za dovolenou musí být kladný.');
            }
            $created[] = [
                'period_start' => $period,
                'input_id' => $this->insertReversalInput(
                    $supplierId,
                    $original,
                    $period,
                    $amount,
                    $externalId,
                    $userId,
                ),
                'amount_minor' => $amount,
            ];
        }

        return [
            'absence_id' => $absenceId,
            'created' => $created,
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $absence */
    private function employeeId(int $supplierId, array $absence): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([
            $supplierId,
            PayrollTimeValue::int($absence['employment_id'] ?? null, 'employment_id'),
        ]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \OutOfBoundsException('Pracovní vztah dovolené nebyl nalezen.');
        }

        return PayrollTimeValue::int($id, 'employee_id');
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
            throw new \DomainException(
                'Pro náhradu za dovolenou chybí účinná mzdová složka NAHRADA_MZDY_DOVOLENA.',
            );
        }

        return PayrollTimeValue::int($id, 'component_id');
    }

    /** @return list<array<string,mixed>> */
    private function originalInputs(int $supplierId, int $absenceId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND source_kind = "absence"
                AND external_id LIKE ? AND status IN ("approved", "locked")
              ORDER BY period_start, id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, "leave:{$absenceId}:%:original"]);

        return PayrollTimeValue::rows(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'payroll_inputs',
        );
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

    /** @param array<string,mixed> $originalInput */
    private function insertReversalInput(
        int $supplierId,
        array $originalInput,
        string $periodStart,
        int $amountMinor,
        string $externalId,
        ?int $userId,
    ): int {
        if ($originalInput['component_snapshot_json'] === null
            || $originalInput['component_snapshot_hash'] === null
        ) {
            throw new \DomainException(
                'Původní vstup náhrady za dovolenou nemá zmrazenou klasifikaci.',
            );
        }
        $snapshot = CanonicalJson::encode([
            'kind' => 'leave_compensation_reversal.v1',
            'period_start' => $periodStart,
            'reverses_input_id' => PayrollTimeValue::int($originalInput['id'] ?? null, 'input_id'),
            'amount_minor' => $amountMinor,
        ]);
        try {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id, period_start,
                     source_period_start, amount_minor, quantity_milliunits, source_kind,
                     external_id, source_snapshot_json, source_snapshot_hash, status,
                     component_snapshot_json, component_snapshot_hash, created_by,
                     approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, "correction", ?, ?, ?, "approved", ?, ?, ?, ?, NOW())',
            );
            $statement->execute([
                $supplierId,
                PayrollTimeValue::int($originalInput['employee_id'] ?? null, 'employee_id'),
                PayrollTimeValue::int($originalInput['employment_id'] ?? null, 'employment_id'),
                PayrollTimeValue::int($originalInput['component_id'] ?? null, 'component_id'),
                $periodStart,
                $periodStart,
                $amountMinor,
                $originalInput['quantity_milliunits'] === null
                    ? null
                    : -PayrollTimeValue::int(
                        $originalInput['quantity_milliunits'],
                        'quantity_milliunits',
                    ),
                $externalId,
                $snapshot,
                hash('sha256', $snapshot, true),
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
}
