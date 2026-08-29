<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollEmployerPolicyRepository
{
    public const LIST_DEFAULT_LIMIT = 25;
    public const LIST_MAX_LIMIT = 200;

    private const POLICY_COLUMNS = <<<'SQL'
        id, supplier_id, valid_from, valid_to, payday_day,
        payday_month_offset, payday_business_day_rule,
        balance_rounding_mode, home_office_policy, travel_expense_policy,
        leave_entitlement_weeks,
        automatic_posting_enabled,
        delivery_channel, delivery_verified_on, source_kind,
        source_reference, created_by, updated_by, row_version,
        created_at, updated_at
        SQL;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmployerPolicyDeletionRepository $deletion,
    ) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::POLICY_COLUMNS . '
               FROM payroll_employer_policies
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : $this->deletion->decorateOne($supplierId, self::hydrate($row));
    }

    /** @return array<string,mixed>|null */
    public function findEffective(int $supplierId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::POLICY_COLUMNS . '
               FROM payroll_employer_policies
              WHERE supplier_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 2',
        );
        $stmt->execute([$supplierId, $effectiveOn, $effectiveOn]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \RuntimeException(
                'K danému dni je účinných více zaměstnavatelských politik.',
            );
        }

        return isset($rows[0]) ? self::hydrate($rows[0]) : null;
    }

    /**
     * Účinná politika pro API — na rozdíl od `findEffective()` nese `can_delete`
     * a `delete_blocker`. Oddělené schválně: `findEffective()` čte mzdový běh do
     * neměnného vstupního snapshotu, do kterého rozhodnutí o mazání nepatří,
     * protože by měnilo jeho hash.
     *
     * @return list<array<string,mixed>>
     */
    public function listEffective(int $supplierId, string $effectiveOn): array
    {
        $policy = $this->findEffective($supplierId, $effectiveOn);

        return $policy === null ? [] : $this->deletion->decorate($supplierId, [$policy]);
    }

    /**
     * Historie revizí politiky zaměstnavatele, po stránkách.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function list(
        int $supplierId,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici: repozitář volá
        // i jiný kód než akce a „nekonečný" seznam nesmí jít objednat nikudy.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_employer_policies
              WHERE supplier_id = ?',
        );
        $countStmt->execute([$supplierId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::POLICY_COLUMNS . '
               FROM payroll_employer_policies
              WHERE supplier_id = ?
              ORDER BY valid_from DESC, id DESC
              LIMIT ? OFFSET ?',
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = self::hydrate($row);
        }

        return [
            'items' => $this->deletion->decorate($supplierId, $result),
            'total' => $total,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data, ?int $actorUserId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $this->assertNoOverlap(
                $supplierId,
                self::requiredString($data, 'valid_from'),
                self::nullableString($data, 'valid_to'),
                null,
            );
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employer_policies
                    (supplier_id, valid_from, valid_to, payday_day,
                     payday_month_offset, payday_business_day_rule,
                     balance_rounding_mode, home_office_policy,
                     travel_expense_policy, leave_entitlement_weeks,
                     automatic_posting_enabled, delivery_channel,
                     delivery_verified_on, source_kind, source_reference,
                     created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute($this->writeValues(
                $supplierId,
                $data,
                $actorUserId,
                true,
            ));
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new \RuntimeException(
                    'Zaměstnavatelskou politiku se nepodařilo založit.',
                );
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException(
                    'Uloženou zaměstnavatelskou politiku se nepodařilo načíst.',
                );
            $this->appendAudit($row, 'created', $actorUserId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
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
        ?int $actorUserId,
    ): ?array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $this->lockTenant($supplierId);
            $lock = $pdo->prepare(
                'SELECT row_version
                   FROM payroll_employer_policies
                  WHERE supplier_id = ? AND id = ?
                  FOR UPDATE',
            );
            $lock->execute([$supplierId, $id]);
            $current = $lock->fetchColumn();
            if ($current === false) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }
            $currentVersion = (int) $current;
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollEmployerPolicyConflictException(
                    $currentVersion,
                );
            }
            $this->assertNoOverlap(
                $supplierId,
                self::requiredString($data, 'valid_from'),
                self::nullableString($data, 'valid_to'),
                $id,
            );

            $stmt = $pdo->prepare(
                'UPDATE payroll_employer_policies
                    SET valid_from = ?,
                        valid_to = ?,
                        payday_day = ?,
                        payday_month_offset = ?,
                        payday_business_day_rule = ?,
                        balance_rounding_mode = ?,
                        home_office_policy = ?,
                        travel_expense_policy = ?,
                        leave_entitlement_weeks = ?,
                        automatic_posting_enabled = ?,
                        delivery_channel = ?,
                        delivery_verified_on = ?,
                        source_kind = ?,
                        source_reference = ?,
                        updated_by = ?,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?',
            );
            $values = array_slice(
                $this->writeValues($supplierId, $data, $actorUserId, false),
                1,
            );
            $values[] = $supplierId;
            $values[] = $id;
            $values[] = $expectedVersion;
            $stmt->execute($values);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollEmployerPolicyConflictException(
                    $currentVersion,
                );
            }
            $row = $this->find($supplierId, $id)
                ?? throw new \RuntimeException(
                    'Upravenou zaměstnavatelskou politiku se nepodařilo načíst.',
                );
            $this->appendAudit($row, 'updated', $actorUserId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row;
    }

    /**
     * @return list<array{
     *   id:int,
     *   supplier_id:int,
     *   policy_id:int,
     *   action:string,
     *   snapshot_json:string,
     *   snapshot_hash:string,
     *   actor_user_id:?int,
     *   created_at:string
     * }>
     */
    public function auditTrail(int $supplierId, int $policyId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, policy_id, action, snapshot_json,
                    snapshot_hash, actor_user_id, created_at
               FROM payroll_employer_policy_audit
              WHERE supplier_id = ? AND policy_id = ?
              ORDER BY id',
        );
        $stmt->execute([$supplierId, $policyId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = self::databaseRow($fetched);
            $result[] = [
                'id' => self::requiredInt($row, 'id'),
                'supplier_id' => self::requiredInt($row, 'supplier_id'),
                'policy_id' => self::requiredInt($row, 'policy_id'),
                'action' => self::requiredString($row, 'action'),
                'snapshot_json' => self::requiredString($row, 'snapshot_json'),
                'snapshot_hash' => self::requiredString($row, 'snapshot_hash'),
                'actor_user_id' => self::nullableInt($row, 'actor_user_id'),
                'created_at' => self::requiredString($row, 'created_at'),
            ];
        }

        return $result;
    }

    private function lockTenant(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('Firma pro zaměstnavatelskou politiku neexistuje.');
        }
    }

    private function assertNoOverlap(
        int $supplierId,
        string $validFrom,
        ?string $validTo,
        ?int $exceptId,
    ): void {
        $sql = 'SELECT id
                  FROM payroll_employer_policies
                 WHERE supplier_id = ?
                   AND valid_from <= COALESCE(?, "9999-12-31")
                   AND COALESCE(valid_to, "9999-12-31") >= ?';
        $params = [$supplierId, $validTo, $validFrom];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new PayrollEmployerPolicyOverlapException(
                'Platnost zaměstnavatelské politiky se překrývá s jiným záznamem.',
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    private function writeValues(
        int $supplierId,
        array $data,
        ?int $actorUserId,
        bool $includeCreatedBy,
    ): array {
        $values = [
            $supplierId,
            self::requiredString($data, 'valid_from'),
            self::nullableString($data, 'valid_to'),
            self::requiredInt($data, 'payday_day'),
            self::requiredInt($data, 'payday_month_offset'),
            self::requiredString($data, 'payday_business_day_rule'),
            self::requiredString($data, 'balance_rounding_mode'),
            self::requiredString($data, 'home_office_policy'),
            self::requiredString($data, 'travel_expense_policy'),
            array_key_exists('leave_entitlement_weeks', $data)
                ? self::requiredInt($data, 'leave_entitlement_weeks')
                : 4,
            (int) self::requiredBool($data, 'automatic_posting_enabled'),
            self::requiredString($data, 'delivery_channel'),
            self::nullableString($data, 'delivery_verified_on'),
            self::requiredString($data, 'source_kind'),
            self::nullableString($data, 'source_reference'),
        ];
        if ($includeCreatedBy) {
            $values[] = $actorUserId;
        }
        $values[] = $actorUserId;

        return $values;
    }

    /** @param array<string,mixed> $policy */
    private function appendAudit(
        array $policy,
        string $action,
        ?int $actorUserId,
    ): void {
        // Rozhodnutí o mazání je odvozený, časem proměnlivý údaj — do neměnného
        // snapshotu politiky nepatří a nesmí ovlivnit jeho hash.
        unset($policy['can_delete'], $policy['delete_blocker']);
        $json = json_encode(
            $policy,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        );
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_policy_audit
                (supplier_id, policy_id, action, snapshot_json,
                 snapshot_hash, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $policy['supplier_id'],
            $policy['id'],
            $action,
            $json,
            hash('sha256', $json),
            $actorUserId,
        ]);
    }

    /** @return array<string,mixed> */
    private static function hydrate(mixed $value): array
    {
        $row = self::databaseRow($value);
        $row['id'] = self::requiredInt($row, 'id');
        $row['supplier_id'] = self::requiredInt($row, 'supplier_id');
        $row['payday_day'] = self::requiredInt($row, 'payday_day');
        $row['payday_month_offset'] = self::requiredInt(
            $row,
            'payday_month_offset',
        );
        $row['leave_entitlement_weeks'] = self::requiredInt(
            $row,
            'leave_entitlement_weeks',
        );
        $row['row_version'] = self::requiredInt($row, 'row_version');
        $row['automatic_posting_enabled'] = self::requiredBool(
            $row,
            'automatic_posting_enabled',
        );
        foreach (['created_by', 'updated_by'] as $field) {
            $row[$field] = self::nullableInt($row, $field);
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private static function databaseRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Databázový řádek zaměstnavatelské politiky není pole.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databázový řádek zaměstnavatelské politiky nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Pole {$field} zaměstnavatelské politiky není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Pole {$field} zaměstnavatelské politiky není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function requiredInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(
            "Pole {$field} zaměstnavatelské politiky není celé číslo.",
        );
    }

    /** @param array<string,mixed> $row */
    private static function nullableInt(array $row, string $field): ?int
    {
        if (($row[$field] ?? null) === null) {
            return null;
        }

        return self::requiredInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function requiredBool(array $row, string $field): bool
    {
        $value = $row[$field] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return $value === 1 || $value === '1';
        }

        throw new \UnexpectedValueException(
            "Pole {$field} zaměstnavatelské politiky není boolean.",
        );
    }
}
