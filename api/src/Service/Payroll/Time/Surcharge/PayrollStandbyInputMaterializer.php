<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollHourlyAverageSegments;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PDO;

/**
 * Odměna za pracovní pohotovost (§ 140 ZP) ze schválené docházky do mzdového vstupu.
 *
 * Minuty pohotovosti se zadávají u publikovaných směn (`payroll_shifts.standby_minutes`)
 * a do měsíce patří podle místního začátku směny, stejně jako zápisy docházky.
 * Odměna = minuty / 60 × průměrný hodinový výdělek × sazba; sazba je sjednaná
 * (`payroll_employment_surcharge_policies.standby_rate_bp`), jinak zákonné
 * minimum ze sady pravidel (`surcharge.standby.rate`, 10 %).
 *
 * Mechanika je táž jako u příplatků ({@see PayrollSurchargeInputMaterializer}):
 * běží ve stejné transakci jako schválení docházky, opakované schválení beze
 * změny nic nezapíše a změněná evidence zapíše jen ROZDÍL proti tomu, co už je
 * za měsíc schválené. Chybějící průměrný výdělek u měsíce s pohotovostí
 * schválení zastaví — nula by byla tichý nedoplatek.
 */
final class PayrollStandbyInputMaterializer
{
    public const COMPONENT_CODE = 'ODMENA_POHOTOVOST';

    /** Prefix `external_id` mzdového vstupu odměny za pohotovost. */
    public const EXTERNAL_PREFIX = 'standby:';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollSurchargeRepository $surcharges,
        private readonly PayrollSurchargeInputMaterializer $surchargeInputs,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @param string $periodStart první den měsíce, `RRRR-MM-01`
     * @return array<string,mixed>
     */
    public function materialize(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        ?int $userId,
    ): array {
        if (preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            throw PayrollSurchargeException::of(
                'invalid_period',
                'Období odměny za pohotovost musí být první den měsíce.',
            );
        }
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->reconcile($supplierId, $employmentId, $periodStart, $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Výpočet bez zápisu: minuty, sazba a částka za měsíc.
     *
     * @return array{minutes:int,rate_basis_points:int,rate_source:string,average_hourly_minor:int,amount_minor:int}
     */
    public function calculate(int $supplierId, int $employmentId, string $periodStart): array
    {
        $source = $this->db->pdo()->prepare('SELECT work_source FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = ?');
        $source->execute([$supplierId, $employmentId, $periodStart]);
        if ($source->fetchColumn() === 'manual_summary') {
            throw PayrollSurchargeException::of('manual_summary_standby_unavailable',
                'Ruční souhrn pracovní doby nedokládá pohotovost; staré směny nelze použít pro výpočet odměny.');
        }
        $segments = $this->standbySegments($supplierId, $employmentId, $periodStart);
        $minutes = array_sum(array_column($segments, 'minutes'));
        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
        $statutory = PayrollSurchargePolicy::basisPointsOf($ruleset->standbyRate());
        $policy = $this->surcharges->policy($supplierId, $employmentId, $periodStart);
        $agreed = $policy['standby_rate_bp'] ?? null;
        $rate = $statutory;
        $source = 'statutory';
        if ($agreed !== null && $agreed !== '' && (int) $agreed > $statutory) {
            $rate = (int) $agreed;
            $source = 'agreed';
        }
        if ($minutes === 0) {
            return [
                'minutes' => 0,
                'rate_basis_points' => $rate,
                'rate_source' => $source,
                'average_hourly_minor' => 0,
                'amount_minor' => 0,
            ];
        }
        $average = $this->surchargeInputs->averageHourlyMinor($supplierId, $employmentId, $periodStart);
        if ($average <= 0) {
            throw PayrollSurchargeException::of(
                'standby_average_earning_missing',
                sprintf(
                    'V měsíci %s je u směn evidovaná pracovní pohotovost (%d min), ale pro čtvrtletí '
                    . 'není schválený průměrný výdělek. Odměnu podle § 140 ZP bez něj spočítat nelze; '
                    . 'schvalte průměrný výdělek na kartě pracovního vztahu.',
                    substr($periodStart, 0, 7),
                    $minutes,
                ),
            );
        }

        $floor = (new PayrollHourlyAverageSegments($this->db))->forEmployment($supplierId, $employmentId, $segments, $average);
        $weighted = 0;
        foreach ($segments as $segment) $weighted += $segment['minutes'] * ($floor['averages'][$segment['local_date']] ?? $average);
        return [
            'minutes' => $minutes,
            'rate_basis_points' => $rate,
            'rate_source' => $source,
            'average_hourly_minor' => $average,
            'amount_minor' => PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId)
                ->mode()
                ->roundFraction($weighted * $rate, 60 * 10_000 * 100) * 100,
            ...(isset($floor['proofs'][$periodStart]) ? ['hourly_average_floor' => $floor['proofs'][$periodStart], 'hourly_average_segments' => $segments] : []),
        ];
    }

    /** @return array<string,mixed> */
    private function reconcile(int $supplierId, int $employmentId, string $periodStart, ?int $userId): array
    {
        $month = $this->timeMonth($supplierId, $employmentId, $periodStart);
        if (in_array($month['work_source'] ?? 'entries', ['import_summary', 'manual_summary'], true)) {
            // Měsíc ze souhrnu importu nese odměny jako spočítané složky;
            // druhý výpočet ze směn by nárok zaplatil dvakrát.
            return $this->report($employmentId, $periodStart, null, false, $month['work_source']);
        }
        if ($this->hasActiveImportInputs($supplierId, $employmentId, $periodStart)) {
            // Směny s ponechanými částkami z importu: odměny nesou importované vstupy.
            return $this->report($employmentId, $periodStart, null, false, 'import_inputs');
        }
        $calculation = $this->calculate($supplierId, $employmentId, $periodStart);
        $existing = $this->existingInputs($supplierId, $employmentId, $periodStart);
        $cumulative = array_sum(array_map(
            static fn (array $row): int => (int) $row['amount_minor'],
            $existing,
        ));
        $delta = $calculation['amount_minor'] - $cumulative;
        if ($delta === 0) {
            return $this->report($employmentId, $periodStart, $calculation, false, null);
        }
        $this->assertInputsNotLocked($supplierId, $employmentId, $periodStart);
        $this->components->ensureDefaults($supplierId);

        $snapshot = isset($calculation['hourly_average_floor']) ? CanonicalJson::encode($calculation) : null;
        $input = $this->inputs->createApproved($supplierId, [
            'employee_id' => $this->employeeId($supplierId, $employmentId),
            'employment_id' => $employmentId,
            'component_id' => $this->componentId($supplierId, $periodStart),
            'period_start' => $periodStart,
            'source_period_start' => null,
            'amount_minor' => $delta,
            'quantity_milliunits' => null,
            'source_kind' => 'time',
            'external_id' => sprintf('%s%s:%d', self::EXTERNAL_PREFIX, $periodStart, count($existing) + 1),
            'source_snapshot_json' => $snapshot, 'source_snapshot_hash' => $snapshot === null ? null : hash('sha256', $snapshot, true),
        ], $userId, false, true);
        if ($snapshot !== null) {
            $this->db->pdo()->prepare('INSERT INTO payroll_standby_floor_proofs (supplier_id, employment_id, input_id, period_start, source_snapshot_json, source_snapshot_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $employmentId, $input['id'], $periodStart, $snapshot, hash('sha256', $snapshot, true)]);
        }

        return $this->report($employmentId, $periodStart, $calculation, true, null) + [
            'input_id' => PayrollTimeValue::int($input['id'] ?? null, 'input_id'),
            'delta_minor' => $delta,
        ];
    }

    /**
     * Minuty pohotovosti z publikovaných směn, jejichž MÍSTNÍ začátek padá do měsíce.
     */
    private function standbySegments(int $supplierId, int $employmentId, string $periodStart): array
    {
        $from = (new DateTimeImmutable($periodStart . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('-1 day');
        $to = (new DateTimeImmutable($periodStart . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('+1 month +1 day');
        $stmt = $this->db->pdo()->prepare(
            'SELECT starts_at_utc, timezone_name, standby_minutes
               FROM payroll_shifts
              WHERE supplier_id = ? AND employment_id = ? AND status = "published"
                AND standby_minutes > 0
                AND starts_at_utc >= ? AND starts_at_utc < ?'
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
        ]);
        $month = substr($periodStart, 0, 7);
        $segments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $local = (new DateTimeImmutable((string) $row['starts_at_utc'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone((string) $row['timezone_name']));
            if ($local->format('Y-m') === $month) {
                $segments[] = ['local_date' => $local->format('Y-m-d'), 'minutes' => (int) $row['standby_minutes']];
            }
        }

        return $segments;
    }

    /** @return array<string,mixed> */
    private function timeMonth(int $supplierId, int $employmentId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, revision_no, work_source
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || ($row['status'] ?? null) !== 'approved') {
            throw PayrollSurchargeException::of(
                'time_month_not_approved',
                'Odměnu za pracovní pohotovost lze do mzdy promítnout jen ze schválené docházky.',
            );
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function existingInputs(int $supplierId, int $employmentId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, amount_minor
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "time" AND external_id LIKE ? AND status <> "cancelled"
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, self::EXTERNAL_PREFIX . $periodStart . ':%']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertInputsNotLocked(int $supplierId, int $employmentId, string $periodStart): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status = "locked"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        if ($stmt->fetchColumn() !== false) {
            throw PayrollSurchargeException::of(
                'inputs_locked',
                'Mzdové vstupy období jsou uzamčené mzdovým během. Odměnu za pohotovost do nich '
                . 'promítnout nelze — otevřete běh znovu a schválení docházky zopakujte.',
            );
        }
    }

    private function employeeId(int $supplierId, int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw PayrollSurchargeException::of('employment_missing', 'Pracovní vztah nepatří této firmě.');
        }

        return (int) $value;
    }

    private function componentId(int $supplierId, string $periodStart): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, self::COMPONENT_CODE, $periodStart, $periodStart]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw PayrollSurchargeException::of(
                'component_missing',
                sprintf('Pro odměnu za pohotovost chybí účinná mzdová složka %s.', self::COMPONENT_CODE),
            );
        }

        return (int) $id;
    }

    private function hasActiveImportInputs(int $supplierId, int $employmentId, string $periodStart): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ? AND status <> 'cancelled'
                AND (external_id LIKE ? OR external_id LIKE ?)
              LIMIT 1"
        );
        $stmt->execute([
            $supplierId, $employmentId, $periodStart,
            ...PayrollTimeRepository::importInputExternalPatterns($periodStart, $employmentId),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed>|null $calculation
     * @return array<string,mixed>
     */
    private function report(
        int $employmentId,
        string $periodStart,
        ?array $calculation,
        bool $written,
        ?string $skippedReason,
    ): array {
        return [
            'employment_id' => $employmentId,
            'period_start' => $periodStart,
            'minutes' => $skippedReason === 'manual_summary' ? null : ($calculation['minutes'] ?? 0),
            'rate_basis_points' => $calculation['rate_basis_points'] ?? null,
            'rate_source' => $calculation['rate_source'] ?? null,
            'amount_minor' => $skippedReason === 'manual_summary' ? null : ($calculation['amount_minor'] ?? 0),
            ...($skippedReason === 'manual_summary' ? ['requires_manual_review' => true] : []),
            'written' => $written,
            'skipped_reason' => $skippedReason,
        ];
    }
}
