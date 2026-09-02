<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollRecurringComponentRepository
{
    /**
     * Tvrdý strop seznamu předpisů. `employment_id` je volitelný filtr, takže
     * bez něj je seznam součin „počet pracovních vztahů × počet předpisů" —
     * a právě tak se čte, když si uživatel otevře přehled za celou firmu.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRecurringComponentDeletionRepository $deletion,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function list(
        int $supplierId,
        ?int $employmentId = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $params = [$supplierId];
        $employmentFilter = '';
        if ($employmentId !== null) {
            $employmentFilter = ' AND recurring.employment_id = ?';
            $params[] = $employmentId;
        }
        $from = ' FROM payroll_recurring_components recurring
               JOIN payroll_employments employment
                 ON employment.supplier_id = recurring.supplier_id
                AND employment.id = recurring.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ?'
            . $employmentFilter;

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT recurring.*, employment.employee_id,
                    employment.code AS employment_code,
                    employee.full_name AS employee_name,
                    component.code AS component_code,
                    component.name AS component_name'
            . $from
            . ' ORDER BY recurring.is_active DESC, employee.full_name,
                       recurring.valid_from DESC, recurring.id DESC
              LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $this->deletion->decorate(
                $supplierId,
                array_map(
                    self::cast(...),
                    PayrollTimeValue::rows(
                        $stmt->fetchAll(PDO::FETCH_ASSOC),
                        'payroll_recurring_components',
                    ),
                ),
            ),
            'total' => $total,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT recurring.*, employment.employee_id,
                    employment.code AS employment_code,
                    employee.full_name AS employee_name,
                    component.code AS component_code,
                    component.name AS component_name
               FROM payroll_recurring_components recurring
               JOIN payroll_employments employment
                 ON employment.supplier_id = recurring.supplier_id
                AND employment.id = recurring.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ? AND recurring.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : $this->deletion->decorateOne(
                $supplierId,
                self::cast(PayrollTimeValue::row($row, 'payroll_recurring_component')),
            );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->assertValidReferences($supplierId, $data);
            $this->assertNoOverlap($supplierId, $data, null);
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_recurring_components
                    (supplier_id, employment_id, component_id, calculation_kind,
                     amount_minor, rate_basis_points, valid_from, valid_to,
                     allocation_rule, maximum_amount_minor, note, is_active,
                     created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['employment_id'],
                $data['component_id'],
                $data['calculation_kind'],
                $data['amount_minor'],
                $data['rate_basis_points'],
                $data['valid_from'],
                $data['valid_to'],
                $data['allocation_rule'],
                $data['maximum_amount_minor'],
                $data['note'],
                $data['is_active'] ? 1 : 0,
                $userId,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Předpis se překrývá nebo odkazuje na neplatný vztah či složku.',
                    previous: $e,
                );
            }
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Předpis opakované složky se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
        ?int $userId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $current = $this->lock($supplierId, $id);
            if ($current === null) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return null;
            }
            $currentVersion = PayrollTimeValue::int(
                $current['row_version'] ?? null,
                'row_version',
            );
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollRecurringComponentConflictException($currentVersion);
            }
            if (PayrollTimeValue::int(
                $current['employment_id'] ?? null,
                'employment_id',
            ) !== PayrollTimeValue::int($data['employment_id'] ?? null, 'employment_id')
                || PayrollTimeValue::int(
                    $current['component_id'] ?? null,
                    'component_id',
                ) !== PayrollTimeValue::int($data['component_id'] ?? null, 'component_id')
                || PayrollTimeValue::string(
                    $current['valid_from'] ?? null,
                    'valid_from',
                ) !== PayrollTimeValue::string($data['valid_from'] ?? null, 'valid_from')
            ) {
                throw new \InvalidArgumentException(
                    'Vztah, složku ani začátek platnosti nelze měnit; založte nový předpis.'
                );
            }
            $this->assertValidReferences($supplierId, $data, false);
            $this->assertNoOverlap($supplierId, $data, $id);
            $stmt = $pdo->prepare(
                'UPDATE payroll_recurring_components
                    SET calculation_kind = ?, amount_minor = ?,
                        rate_basis_points = ?, valid_to = ?,
                        allocation_rule = ?, maximum_amount_minor = ?,
                        note = ?, is_active = ?, updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $stmt->execute([
                $data['calculation_kind'],
                $data['amount_minor'],
                $data['rate_basis_points'],
                $data['valid_to'],
                $data['allocation_rule'],
                $data['maximum_amount_minor'],
                $data['note'],
                $data['is_active'] ? 1 : 0,
                $userId,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollRecurringComponentConflictException($currentVersion);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }
        return $this->find($supplierId, $id);
    }

    /** @return list<array<string,mixed>> */
    public function effectiveForPeriod(int $supplierId, string $periodStart): array
    {
        $period = new \DateTimeImmutable($periodStart);
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status,
                           EXISTS (
                               SELECT 1
                                 FROM payroll_employment_events lifecycle
                                WHERE lifecycle.supplier_id = employment.supplier_id
                                  AND lifecycle.employment_id = employment.id
                                  AND lifecycle.event_type = "status_changed"
                                  AND lifecycle.effective_on BETWEEN ? AND ?
                                  AND (
                                      lifecycle.from_status = "suspended"
                                      OR lifecycle.to_status = "suspended"
                                  )
                           ) AS suspended_in_month
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT recurring.*, employment.employee_id,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . '
                      AS monthly_gross_minor,
                    employment.code AS employment_code,
                    employment.effective_status AS employment_effective_status,
                    COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01" ELSE NULL END
                    ) AS employment_start,
                    employment.end_date AS employment_end,
                    employment.suspended_in_month
                        AS employment_suspended_in_month,
                    component.code AS component_code,
                    component.name AS component_name,
                    component.is_active AS component_is_active,
                    component.valid_from AS component_valid_from,
                    component.valid_to AS component_valid_to
               FROM payroll_recurring_components recurring
               JOIN effective_employment employment
                 ON employment.supplier_id = recurring.supplier_id
                AND employment.id = recurring.employment_id
               JOIN payroll_component_definitions component
                 ON component.supplier_id = recurring.supplier_id
                AND component.id = recurring.component_id
              WHERE recurring.supplier_id = ?
                AND recurring.is_active = 1
                AND recurring.valid_from <= ?
                AND (recurring.valid_to IS NULL OR recurring.valid_to >= ?)
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01"
                           ELSE NULL
                      END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                AND employment.effective_status IS NOT NULL
                AND employment.effective_status NOT IN ("archived", "no_show")
              ORDER BY recurring.id
              FOR UPDATE'
        );
        $stmt->execute([
            $periodEnd,
            $periodStart,
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodEnd,
            $supplierId,
            $periodEnd,
            $periodStart,
            $periodEnd,
            $periodStart,
        ]);
        return array_map(
            self::cast(...),
            PayrollTimeValue::rows(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'effective_recurring_components',
            ),
        );
    }

    /**
     * @param array<string,mixed> $recurring
     * @param array<string,mixed> $calculation
     * @return array{input_id:int,created:bool}
     */
    public function createDraftInput(
        int $supplierId,
        string $periodStart,
        array $recurring,
        array $calculation,
        ?int $userId,
    ): array {
        $recurringId = PayrollTimeValue::int($recurring['id'] ?? null, 'recurring.id');
        $employmentId = PayrollTimeValue::int(
            $recurring['employment_id'] ?? null,
            'employment_id',
        );
        $externalId = "recurring:{$recurringId}";
        $snapshot = [
            'recurring_component_id' => $recurringId,
            'recurring_row_version' => PayrollTimeValue::int(
                $recurring['row_version'] ?? null,
                'row_version',
            ),
            'calculation_kind' => PayrollTimeValue::string(
                $recurring['calculation_kind'] ?? null,
                'calculation_kind',
            ),
            'allocation_rule' => PayrollTimeValue::string(
                $recurring['allocation_rule'] ?? null,
                'allocation_rule',
            ),
            'amount_minor' => $recurring['amount_minor'] ?? null,
            'rate_basis_points' => $recurring['rate_basis_points'] ?? null,
            'maximum_amount_minor' => $recurring['maximum_amount_minor'] ?? null,
            'note' => $recurring['note'] ?? null,
            'valid_from' => PayrollTimeValue::string(
                $recurring['valid_from'] ?? null,
                'valid_from',
            ),
            'valid_to' => $recurring['valid_to'] ?? null,
            'trace' => $calculation['trace'] ?? [],
        ];
        $json = CanonicalJson::encode($snapshot);
        $hash = hash('sha256', $json, true);
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, external_id,
                     recurring_component_id, source_snapshot_json,
                     source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "recurring", ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                PayrollTimeValue::int($recurring['employee_id'] ?? null, 'employee_id'),
                $employmentId,
                PayrollTimeValue::int($recurring['component_id'] ?? null, 'component_id'),
                $periodStart,
                PayrollTimeValue::int(
                    $calculation['amount_minor'] ?? null,
                    'amount_minor',
                ),
                $externalId,
                $recurringId,
                $json,
                $hash,
                $userId,
            ]);
            return ['input_id' => (int) $this->db->pdo()->lastInsertId(), 'created' => true];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $existing = $this->db->pdo()->prepare(
                'SELECT id
                   FROM payroll_inputs
                  WHERE supplier_id = ? AND employment_id = ?
                    AND period_start = ? AND source_kind = "recurring"
                    AND external_id = ? AND status <> "cancelled"'
            );
            $existing->execute([$supplierId, $employmentId, $periodStart, $externalId]);
            $id = $existing->fetchColumn();
            if ($id === false) {
                throw $e;
            }
            return [
                'input_id' => PayrollTimeValue::int($id, 'input_id'),
                'created' => false,
            ];
        }
    }

    /**
     * Kontrola vazeb předpisu.
     *
     * `$creating` rozlišuje dvě různé situace, které se dřív posuzovaly stejně
     * a tvořily slepou uličku: založit předpis na neaktivní nebo už ukončenou
     * složku opravdu nemá smysl, ale UPRAVIT existující předpis — typicky ho
     * ukončit nebo vypnout — muselo jít pořád. Když se složka deaktivovala nebo
     * jí legislativní překlopení nasadilo `valid_to`, nešly její předpisy ani
     * upravit, ani smazat, a hláška navíc tvrdila, že vztah nepatří firmě.
     *
     * @param array<string,mixed> $data
     */
    private function assertValidReferences(
        int $supplierId,
        array $data,
        bool $creating = true,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT component.valid_from, component.valid_to,
                    COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1
                           THEN "1900-01-01"
                           ELSE NULL
                      END
                    )
                      AS employment_start,
                    employment.end_date AS employment_end
               FROM payroll_employments employment
               JOIN payroll_component_definitions component
                 ON component.supplier_id = employment.supplier_id
                AND component.id = ?
                AND component.frequency_kind = "regular"'
            . ($creating ? ' AND component.is_active = 1' : '')
            . ' WHERE employment.supplier_id = ? AND employment.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$data['component_id'], $supplierId, $data['employment_id']]);
        $raw = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            throw new \InvalidArgumentException(
                $creating
                    ? 'Pracovní vztah nebo pravidelná mzdová složka nepatří této '
                        . 'firmě, případně je složka neaktivní. Aktivujte složku '
                        . 'v číselníku, nebo vyberte jinou.'
                    : 'Pracovní vztah nebo pravidelná mzdová složka nepatří této firmě.'
            );
        }
        /*
         * Ukončení nebo vypnutí předpisu se nesmí posuzovat intervalem: právě
         * tím se předpis uklízí, když se pod ním složka nebo vztah zavřely.
         */
        if (!$creating
            && (!$data['is_active'] || ($data['valid_to'] ?? null) !== null)
        ) {
            return;
        }
        $component = PayrollTimeValue::row($raw, 'component_interval');
        $componentFrom = PayrollTimeValue::string(
            $component['valid_from'] ?? null,
            'component.valid_from',
        );
        $componentTo = $component['valid_to'] === null
            ? null
            : PayrollTimeValue::string($component['valid_to'], 'component.valid_to');
        if (($component['employment_start'] ?? null) === null) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nemá zadané datum nástupu.'
            );
        }
        $employmentStart = PayrollTimeValue::string(
            $component['employment_start'],
            'employment.start',
        );
        $employmentEnd = $component['employment_end'] === null
            ? null
            : PayrollTimeValue::string($component['employment_end'], 'employment.end');
        if ($data['valid_from'] < $componentFrom
            || ($componentTo !== null
                && ($data['valid_to'] === null || $data['valid_to'] > $componentTo))
            || $data['valid_from'] < $employmentStart
            || ($employmentEnd !== null
                && ($data['valid_to'] === null || $data['valid_to'] > $employmentEnd))) {
            throw new \InvalidArgumentException(
                'Platnost předpisu musí ležet uvnitř účinnosti vztahu a verze mzdové složky.'
            );
        }
    }

    /** @param array<string,mixed> $data */
    private function assertNoOverlap(int $supplierId, array $data, ?int $excludeId): void
    {
        if (!$data['is_active']) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_recurring_components
              WHERE supplier_id = ? AND employment_id = ? AND component_id = ?
                AND is_active = 1
                AND (? IS NULL OR id <> ?)
                AND valid_from <= COALESCE(?, "9999-12-31")
                AND (valid_to IS NULL OR valid_to >= ?)
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([
            $supplierId,
            $data['employment_id'],
            $data['component_id'],
            $excludeId,
            $excludeId,
            $data['valid_to'],
            $data['valid_from'],
        ]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException(
                'Platnost předpisu se překrývá s jiným aktivním předpisem stejné složky.'
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function lock(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_recurring_components
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : self::cast(PayrollTimeValue::row($row, 'payroll_recurring_component'));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'employment_id',
            'employee_id',
            'component_id',
            'amount_minor',
            'rate_basis_points',
            'maximum_amount_minor',
            'row_version',
            'monthly_gross_minor',
            'created_by',
            'updated_by',
        ] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        foreach ([
            'is_active',
            'component_is_active',
            'employment_suspended_in_month',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = PayrollTimeValue::bool($row[$key], $key);
            }
        }
        return $row;
    }

    private function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
