<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\PayrollHourlyAverageSegments;
use MyInvoice\Service\Payroll\Time\CzechHolidayCalendar;
use MyInvoice\Service\Payroll\Time\PayrollWorkCalendarSchedule;
use PDO;
use PDOException;

/**
 * Převádí schválenou placenou překážku v práci na mzdový vstup náhrady mzdy.
 *
 * Krácení základní mzdy ({@see PayrollWageProrationService}) dobu placené
 * překážky ze mzdy vyjímá jako {@see PayrollWageReplacementTitle::PaidObstacle}.
 * Dokud nic nevzniklo na druhé straně, zaměstnanec za návštěvu lékaře nebo
 * prostoj přišel o mzdu a náhradu nedostal. Tohle je peněžní půlka téhož
 * schválení: hodiny ze stejných publikovaných směn, bez svátků, krát průměrný
 * hodinový výdělek krát sazba druhu překážky.
 *
 * Složka: strana zaměstnance NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC (JMHZ 10341),
 * strana zaměstnavatele NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL (10340).
 *
 * Tvar je záměrně stejný jako u dovolené ({@see PayrollLeaveInputMaterializer}):
 * idempotenci drží `external_id` (`leave:obstacle:{absence}:{období}:original`)
 * nad `uq_payroll_input_external` a zrušení překážky původní vstup nemění,
 * jen k němu přidá záporný korekční vstup ve stejném období. Předpona `leave:`
 * je podmínkou integritní kontroly `chk_payroll_input_source_snapshot`, která
 * stopu výpočtu dovolí jen u vstupů z nepřítomnosti s touto předponou
 * (migrace 1718).
 */
final class PayrollObstacleInputMaterializer
{
    public const EXTERNAL_ID_PREFIX = 'leave:obstacle:';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAbsenceRepository $absences,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly CzechHolidayCalendar $holidays = new CzechHolidayCalendar(),
    ) {}

    /**
     * @param array<string,mixed> $absence schválená placená překážka s druhem
     * @return array<string,mixed>
     */
    public function materialize(int $supplierId, array $absence, ?int $userId): array
    {
        return $this->transactional(fn (): array => $this->materializeInTransaction($supplierId, $absence, $userId));
    }

    /** @return array<string,mixed> */
    public function reverseForAbsence(int $supplierId, int $absenceId, ?int $userId): array
    {
        return $this->transactional(fn (): array => $this->reverseInTransaction($supplierId, $absenceId, $userId));
    }

    /**
     * @param array<string,mixed> $absence
     * @return array<string,mixed>
     */
    private function materializeInTransaction(int $supplierId, array $absence, ?int $userId): array
    {
        $kind = self::kind($absence);
        $absenceId = PayrollTimeValue::int($absence['id'] ?? null, 'absence_id');
        $employmentId = PayrollTimeValue::int($absence['employment_id'] ?? null, 'employment_id');
        $averageHourly = PayrollTimeValue::int($absence['average_hourly_minor'] ?? null, 'average_hourly_minor');
        $averageId = PayrollTimeValue::int($absence['average_snapshot_id'] ?? null, 'average_snapshot_id');
        $rate = PayrollTimeValue::int(
            $absence['compensation_rate_basis_points'] ?? null,
            'compensation_rate_basis_points',
        );
        $this->components->ensureDefaults($supplierId);

        $segments = $this->absences->publishedShiftSegments($absence, false, AbsenceHolidayTreatment::Ignore);
        $holidays = PayrollWorkCalendarSchedule::holidaysBetween(
            $this->holidays,
            (string) $absence['date_from'],
            (string) $absence['date_to'],
        );
        $compensatedSegments = array_values(array_filter($segments, static fn(array $segment): bool => !array_key_exists((string) $segment['local_date'], $holidays)));
        $floor = (new PayrollHourlyAverageSegments($this->db))->forEmployment($supplierId, $employmentId, $compensatedSegments, $averageHourly);
        $result = PaidObstacleCompensationCalculator::calculate(
            $averageHourly,
            $rate,
            $segments,
            $holidays,
            $rounding = PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId),
            $floor['averages'],
        );
        if ($result['minutes'] === []) {
            // Bez rozvržené směny nevznikne ani krácení mzdy (měří se týmiž
            // směnami), takže tu není co nahrazovat. Volající to ale musí
            // vidět: schválená překážka bez náhrady je jinak tichá.
            return [
                'absence_id' => $absenceId,
                'created' => [],
                'replayed' => [],
                'warning' => 'obstacle_without_published_shifts',
            ];
        }

        $employeeId = null;
        $created = [];
        $replayed = [];
        foreach ($result['amounts'] as $period => $amount) {
            $externalId = self::EXTERNAL_ID_PREFIX . "{$absenceId}:{$period}:original";
            $existing = $this->inputByExternalId($supplierId, $employmentId, $period, 'absence', $externalId);
            if ($existing !== null) {
                $replayed[] = ['period_start' => $period, 'input_id' => (int) $existing['id']];
                continue;
            }
            $snapshot = CanonicalJson::encode([
                'kind' => 'obstacle_compensation.v1',
                'absence_id' => $absenceId,
                'absence_type' => $kind->absenceType(),
                'obstacle_kind' => $kind->value,
                'period_start' => $period,
                'minutes' => $result['minutes'][$period],
                'average_hourly_minor' => $averageHourly,
                'average_snapshot_id' => $averageId,
                'rate_basis_points' => $rate,
                'rate_reason' => $absence['compensation_rate_reason'] ?? null,
                'amount_minor' => $amount,
                'full_rate_amount_minor' => $result['full_rate_amounts'][$period],
                'holidays_excluded' => 'zp-115-3',
                'rounding' => $rounding->traceLabel(),
                'rounding_basis' => $rounding->basisLabel(),
                'entitlement_basis' => $kind->statutoryBasis(),
                ...(isset($floor['proofs'][$period]) ? ['hourly_average_floor' => $floor['proofs'][$period], 'hourly_average_segments' => array_values(array_filter($compensatedSegments, static fn(array $segment): bool => substr((string) $segment['local_date'], 0, 7) === substr($period, 0, 7)))] : []),
            ]);
            $employeeId ??= $this->employeeId($supplierId, $employmentId);
            $input = $this->inputs->create($supplierId, [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $this->componentId($supplierId, $kind->componentCode(), $period),
                'period_start' => $period,
                'source_period_start' => null,
                'amount_minor' => $amount,
                'quantity_milliunits' => intdiv($result['minutes'][$period] * 1000, 60),
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
            ) ?? throw new \RuntimeException('Schválený vstup náhrady mzdy za překážku nebyl nalezen.');
            if ($approved['status'] !== 'approved') {
                throw new \DomainException('Vstup náhrady mzdy za překážku není schválený.');
            }
            $created[] = [
                'period_start' => $period,
                'input_id' => PayrollTimeValue::int($approved['id'] ?? null, 'input_id'),
                'amount_minor' => $amount,
            ];
        }

        return [
            'absence_id' => $absenceId,
            'obstacle_kind' => $kind->value,
            'rate_basis_points' => $rate,
            'average_hourly_minor' => $averageHourly,
            'created' => $created,
            'replayed' => $replayed,
            'warning' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function reverseInTransaction(int $supplierId, int $absenceId, ?int $userId): array
    {
        $created = [];
        $replayed = [];
        foreach ($this->originalInputs($supplierId, $absenceId) as $original) {
            $period = PayrollTimeValue::string($original['period_start'] ?? null, 'period_start');
            $employmentId = PayrollTimeValue::int($original['employment_id'] ?? null, 'employment_id');
            $externalId = self::EXTERNAL_ID_PREFIX . "{$absenceId}:{$period}:reversal";
            $existing = $this->inputByExternalId($supplierId, $employmentId, $period, 'correction', $externalId);
            if ($existing !== null) {
                $replayed[] = ['period_start' => $period, 'input_id' => (int) $existing['id']];
                continue;
            }
            $amount = -PayrollTimeValue::int($original['amount_minor'] ?? null, 'amount_minor');
            if ($amount >= 0) {
                throw new \DomainException('Původní vstup náhrady mzdy za překážku musí být kladný.');
            }
            $created[] = [
                'period_start' => $period,
                'input_id' => $this->insertReversalInput($supplierId, $original, $period, $amount, $externalId, $userId),
                'amount_minor' => $amount,
            ];
        }

        return ['absence_id' => $absenceId, 'created' => $created, 'replayed' => $replayed];
    }

    /** @param array<string,mixed> $absence */
    private static function kind(array $absence): PayrollObstacleKind
    {
        $kind = is_string($absence['obstacle_kind'] ?? null)
            ? PayrollObstacleKind::tryFrom($absence['obstacle_kind'])
            : null;
        if ($kind === null || $kind->absenceType() !== ($absence['absence_type'] ?? null)) {
            throw new \DomainException('Náhradu mzdy lze promítnout jen z placené překážky se zapsaným druhem.');
        }

        return $kind;
    }

    private function employeeId(int $supplierId, int $employmentId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $employmentId]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \OutOfBoundsException('Pracovní vztah překážky nebyl nalezen.');
        }

        return PayrollTimeValue::int($id, 'employee_id');
    }

    private function componentId(int $supplierId, string $code, string $periodStart): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',
        );
        $statement->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \DomainException("Pro náhradu mzdy za překážku chybí účinná mzdová složka {$code}.");
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
        $statement->execute([$supplierId, self::EXTERNAL_ID_PREFIX . "{$absenceId}:%:original"]);

        return PayrollTimeValue::rows($statement->fetchAll(PDO::FETCH_ASSOC), 'payroll_inputs');
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
        if ($originalInput['component_snapshot_json'] === null || $originalInput['component_snapshot_hash'] === null) {
            throw new \DomainException('Původní vstup náhrady mzdy za překážku nemá zmrazenou klasifikaci.');
        }
        $snapshot = CanonicalJson::encode([
            'kind' => 'obstacle_compensation_reversal.v1',
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
                    : -PayrollTimeValue::int($originalInput['quantity_milliunits'], 'quantity_milliunits'),
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
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
