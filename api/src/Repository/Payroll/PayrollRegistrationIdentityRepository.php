<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollRegistrationIdentityRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function identityAt(
        int $supplierId,
        int $employeeId,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, first_name, last_name,
                    title_prefix, title_suffix, birth_surname, birth_date,
                    birth_place, birth_country_code,
                    citizenship_country_code, sex, effective_from,
                    effective_to, row_version
               FROM payroll_person_identity_history
              WHERE supplier_id = ?
                AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
               ORDER BY effective_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historická identita osoby se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->row($rows[0]);
    }

    /**
     * @return list<array{
     *   id:int,identifier_type:string,value_ciphertext:string,
     *   value_hash:string,value_masked:string,row_version:int
     * }>
     */
    public function identifiers(
        int $supplierId,
        int $employeeId,
        bool $forUpdate = false,
    ): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext, value_hash,
                    value_masked, row_version
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY identifier_type, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->row($raw);
            $result[] = [
                'id' => $this->positiveInt($row, 'id'),
                'identifier_type' => $this->string($row, 'identifier_type'),
                'value_ciphertext' => $this->string($row, 'value_ciphertext'),
                'value_hash' => $this->string($row, 'value_hash'),
                'value_masked' => $this->string($row, 'value_masked'),
                'row_version' => $this->positiveInt($row, 'row_version'),
            ];
        }

        return $result;
    }

    /**
     * @param array{
     *   title_prefix:?string,title_suffix:?string,birth_date:?string,
     *   birth_place:?string,birth_country_code:?string,
     *   citizenship_country_code:?string,sex:?string
     * } $facts
     */
    public function updateIdentityFacts(
        int $supplierId,
        int $employeeId,
        int $identityId,
        int $expectedRowVersion,
        array $facts,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET title_prefix = ?,
                    title_suffix = ?,
                    birth_date = ?,
                    birth_place = ?,
                    birth_country_code = ?,
                    citizenship_country_code = ?,
                    sex = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND employee_id = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            $facts['title_prefix'],
            $facts['title_suffix'],
            $facts['birth_date'],
            $facts['birth_place'],
            $facts['birth_country_code'],
            $facts['citizenship_country_code'],
            $facts['sex'],
            $supplierId,
            $employeeId,
            $identityId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Historická identita neexistuje, patří jiné firmě nebo se změnila.',
            );
        }

        return $expectedRowVersion + 1;
    }

    /**
     * @return array{
     *   employee_id:int,start_date:?string,end_date:?string
     * }|null
     */
    public function employment(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'start_date' => $this->nullableString($row, 'start_date'),
            'end_date' => $this->nullableString($row, 'end_date'),
        ];
    }

    /**
     * @return array{
     *   employee_id:int,start_date:?string,end_date:?string
     * }|null
     */
    public function lockEmployment(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'start_date' => $this->nullableString($row, 'start_date'),
            'end_date' => $this->nullableString($row, 'end_date'),
        ];
    }

    public function lockEmployee(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasTrustedReceipt(
        int $supplierId,
        string $environment,
        int $receiptId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_receipts
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND verification_status = "trusted"
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $receiptId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function activeExternalId(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $identifierType,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_to IS NULL
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
            $identifierType,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false ? null : $this->externalId($this->row($raw));
    }

    /**
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }|null
     */
    public function activePersonExternalId(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, environment, identifier_type,
                    value_ciphertext, value_hash, value_masked,
                    valid_from, valid_to, source_kind, source_receipt_id,
                    source_reference_hash, row_version
               FROM payroll_person_external_ids
              WHERE supplier_id = ?
                AND employee_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_to IS NULL
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false
            ? null
            : $this->personExternalId($this->row($raw));
    }

    /**
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }|null
     */
    public function personExternalIdAt(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, environment, identifier_type,
                    value_ciphertext, value_hash, value_masked,
                    valid_from, valid_to, source_kind, source_receipt_id,
                    source_reference_hash, row_version
               FROM payroll_person_external_ids
              WHERE supplier_id = ?
                AND employee_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historie OIČ se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->personExternalId($this->row($rows[0]));
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function externalIdAt(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $identifierType,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
            $identifierType,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historie ID PPV se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->externalId($this->row($rows[0]));
    }

    /** @return list<string> */
    public function activeResolutionTaskKinds(
        int $supplierId,
        int $employmentId,
        string $environment,
        bool $forUpdate = false,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT task_kind
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND status IN ("open", "manual_review")
              ORDER BY task_kind, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->row($raw);
            $result[] = $this->string($row, 'task_kind');
        }

        return $result;
    }

    public function insertExternalIdPlaceholder(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $identifierType,
        string $validFrom,
        string $sourceKind,
        ?int $sourceReceiptId,
        string $sourceReferenceHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_external_ids
                (supplier_id, employee_id, employment_id, environment,
                 identifier_type, value_ciphertext, value_hash,
                 value_masked, valid_from, source_kind, source_receipt_id,
                 source_reference_hash, created_by)
             VALUES (?, ?, ?, ?, ?, 'enc:v2:pending', ?, '', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $identifierType,
            random_bytes(32),
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceReferenceHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertPersonExternalIdPlaceholder(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
        string $validFrom,
        string $sourceKind,
        ?int $sourceReceiptId,
        string $sourceReferenceHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_external_ids
                (supplier_id, employee_id, environment, identifier_type,
                 value_ciphertext, value_hash, value_masked, valid_from,
                 source_kind, source_receipt_id, source_reference_hash,
                 created_by)
             VALUES (?, ?, ?, ?, 'enc:v2:pending', ?, '••••', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
            random_bytes(32),
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceReferenceHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function sealExternalId(
        int $supplierId,
        int $id,
        string $ciphertext,
        string $hash,
        string $masked,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_external_ids
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([
            $ciphertext,
            $hash,
            $masked,
            $supplierId,
            $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Externí identifikátor vztahu nelze uložit.');
        }
    }

    public function sealPersonExternalId(
        int $supplierId,
        int $id,
        string $ciphertext,
        string $hash,
        string $masked,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_person_external_ids
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([
            $ciphertext,
            $hash,
            $masked,
            $supplierId,
            $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Externí identifikátor osoby nelze uložit.');
        }
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function externalIdById(
        int $supplierId,
        int $externalId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND environment = ? AND id = ?'
        );
        $statement->execute([$supplierId, $environment, $externalId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false ? null : $this->externalId($this->row($raw));
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,created:bool
     * }
     */
    public function openResolutionTask(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $taskKind,
        string $reasonCode,
        ?int $candidateCount,
        ?int $sourceReceiptId,
        ?int $assignedTo,
        ?int $createdBy,
    ): array {
        $existing = $this->db->pdo()->prepare(
            'SELECT id, status, row_version
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND task_kind = ?
                AND status IN ("open","manual_review")
              FOR UPDATE'
        );
        $existing->execute([
            $supplierId,
            $environment,
            $employmentId,
            $taskKind,
        ]);
        $raw = $existing->fetch(PDO::FETCH_ASSOC);
        if ($raw !== false) {
            $row = $this->row($raw);
            return [
                'id' => $this->positiveInt($row, 'id'),
                'status' => $this->string($row, 'status'),
                'row_version' => $this->positiveInt($row, 'row_version'),
                'created' => false,
            ];
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_identity_resolution_tasks
                (supplier_id, employee_id, employment_id, environment,
                 task_kind, reason_code, candidate_count, source_receipt_id,
                 assigned_to, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $taskKind,
            $reasonCode,
            $candidateCount,
            $sourceReceiptId,
            $assignedTo,
            $createdBy,
        ]);

        return [
            'id' => (int) $this->db->pdo()->lastInsertId(),
            'status' => 'open',
            'row_version' => 1,
            'created' => true,
        ];
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,task_kind:string,
     *   status:string,row_version:int
     * }|null
     */
    public function lockResolutionTask(
        int $supplierId,
        int $taskId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, task_kind, status,
                    row_version
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ? AND environment = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $taskId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'task_kind' => $this->string($row, 'task_kind'),
            'status' => $this->string($row, 'status'),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    public function resolveTask(
        int $supplierId,
        int $taskId,
        int $expectedRowVersion,
        ?int $externalId,
        string $evidenceHash,
        int $resolvedBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_identity_resolution_tasks
                SET status = "resolved",
                    resolved_external_id_id = ?,
                    resolution_evidence_hash = ?,
                    resolved_by = ?,
                    resolved_at = CURRENT_TIMESTAMP,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status IN ("open","manual_review")'
        );
        $statement->execute([
            $externalId,
            $evidenceHash,
            $resolvedBy,
            $supplierId,
            $taskId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Resolution task neexistuje, patří jiné firmě nebo se změnil.',
            );
        }

        return $expectedRowVersion + 1;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }
     */
    private function externalId(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'environment' => $this->string($row, 'environment'),
            'identifier_type' => $this->string($row, 'identifier_type'),
            'value_ciphertext' => $this->string($row, 'value_ciphertext'),
            'value_hash' => $this->string($row, 'value_hash'),
            'value_masked' => $this->string($row, 'value_masked'),
            'valid_from' => $this->string($row, 'valid_from'),
            'valid_to' => $this->nullableString($row, 'valid_to'),
            'source_kind' => $this->string($row, 'source_kind'),
            'source_receipt_id' => $this->nullablePositiveInt(
                $row,
                'source_receipt_id',
            ),
            'source_reference_hash' => $this->string(
                $row,
                'source_reference_hash',
            ),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }
     */
    private function personExternalId(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'environment' => $this->string($row, 'environment'),
            'identifier_type' => $this->string($row, 'identifier_type'),
            'value_ciphertext' => $this->string($row, 'value_ciphertext'),
            'value_hash' => $this->string($row, 'value_hash'),
            'value_masked' => $this->string($row, 'value_masked'),
            'valid_from' => $this->string($row, 'valid_from'),
            'valid_to' => $this->nullableString($row, 'valid_to'),
            'source_kind' => $this->string($row, 'source_kind'),
            'source_receipt_id' => $this->nullablePositiveInt(
                $row,
                'source_receipt_id',
            ),
            'source_reference_hash' => $this->string(
                $row,
                'source_reference_hash',
            ),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek registrační identity.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč registrační identity.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            throw new \UnexpectedValueException(
                "Databáze nevrátila pole {$key}.",
            );
        }
        if ($row[$key] === null) {
            return null;
        }
        if (!is_string($row[$key])) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $key): int
    {
        $value = filter_var(
            $row[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($value === false) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new \UnexpectedValueException(
                "Databáze nevrátila pole {$key}.",
            );
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->positiveInt($row, $key);
    }
}
