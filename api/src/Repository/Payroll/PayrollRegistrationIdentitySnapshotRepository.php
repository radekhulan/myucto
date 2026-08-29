<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollRegistrationIdentitySnapshotRepository
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

    /**
     * @return array{
     *   submission_id:int,environment:string,submission_status:string,
     *   source_revision_id:int,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   revision_status:string,revision_no:int,current_revision_no:int,
     *   revision_input_hash:string
     * }|null
     */
    public function lockScope(
        int $supplierId,
        int $submissionId,
        int $sourceRevisionId,
        int $employeeId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id AS submission_id,
                    submission.environment,
                    submission.status AS submission_status,
                    submission.source_revision_id,
                    obligation.agenda_code,
                    obligation.subject_type,
                    obligation.subject_reference,
                    obligation.period_start,
                    obligation.period_end,
                    revision.status AS revision_status,
                    revision.revision_no,
                    run.current_revision_no,
                    revision.input_snapshot_json AS revision_input_json,
                    revision.input_snapshot_hash AS revision_input_hash
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = submission.supplier_id
                AND revision.id = submission.source_revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               JOIN payroll_run_persons run_person
                 ON run_person.supplier_id = revision.supplier_id
                AND run_person.revision_id = revision.id
                AND run_person.employee_id = ?
               JOIN payroll_run_employments run_employment
                 ON run_employment.supplier_id = revision.supplier_id
                AND run_employment.revision_id = revision.id
                AND run_employment.employee_id = run_person.employee_id
                AND run_employment.employment_id = ?
              WHERE submission.supplier_id = ?
                AND submission.id = ?
                AND submission.source_revision_id = ?
              FOR UPDATE'
        );
        $statement->execute([
            $employeeId,
            $employmentId,
            $supplierId,
            $submissionId,
            $sourceRevisionId,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        $revisionInputHash = $this->hash($row, 'revision_input_hash');
        if (!hash_equals(
            $revisionInputHash,
            hash('sha256', $this->string($row, 'revision_input_json')),
        )) {
            throw new \UnexpectedValueException(
                'Zdrojová revize má neplatný otisk vstupního snapshotu.',
            );
        }

        return [
            'submission_id' => $this->positiveInt($row, 'submission_id'),
            'environment' => $this->string($row, 'environment'),
            'submission_status' =>
                $this->string($row, 'submission_status'),
            'source_revision_id' =>
                $this->positiveInt($row, 'source_revision_id'),
            'agenda_code' => $this->string($row, 'agenda_code'),
            'subject_type' => $this->string($row, 'subject_type'),
            'subject_reference' =>
                $this->string($row, 'subject_reference'),
            'period_start' => $this->string($row, 'period_start'),
            'period_end' => $this->string($row, 'period_end'),
            'revision_status' => $this->string($row, 'revision_status'),
            'revision_no' => $this->positiveInt($row, 'revision_no'),
            'current_revision_no' =>
                $this->positiveInt($row, 'current_revision_no'),
            'revision_input_hash' => $revisionInputHash,
        ];
    }

    public function revisionInputHash(
        int $supplierId,
        int $sourceRevisionId,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT input_snapshot_json, input_snapshot_hash
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $sourceRevisionId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);
        $value = $this->hash($row, 'input_snapshot_hash');
        if (!hash_equals(
            $value,
            hash('sha256', $this->string($row, 'input_snapshot_json')),
        )) {
            throw new \UnexpectedValueException(
                'Zdrojová revize má neplatný otisk vstupního snapshotu.',
            );
        }

        return $value;
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * }|null
     */
    public function findByScopeForUpdate(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $sourceRevisionId,
        int $employmentId,
    ): ?array {
        return $this->findOne(
            'SELECT *
               FROM payroll_registration_identity_snapshots
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND source_revision_id = ?
                AND employment_id = ?
              FOR UPDATE',
            [
                $supplierId,
                $environment,
                $submissionId,
                $sourceRevisionId,
                $employmentId,
            ],
        );
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * }|null
     */
    public function findByIdempotencyForUpdate(
        int $supplierId,
        string $environment,
        string $idempotencyKeyHash,
    ): ?array {
        return $this->findOne(
            'SELECT *
               FROM payroll_registration_identity_snapshots
              WHERE supplier_id = ?
                AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
            [$supplierId, $environment, $idempotencyKeyHash],
        );
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * }|null
     */
    public function find(
        int $supplierId,
        int $snapshotId,
        string $environment,
    ): ?array {
        return $this->findOne(
            'SELECT *
               FROM payroll_registration_identity_snapshots
              WHERE supplier_id = ? AND id = ? AND environment = ?',
            [$supplierId, $snapshotId, $environment],
        );
    }

    /**
     * Poslední snapshot REGZEC, který pracovní vztah SKUTEČNĚ opustil.
     *
     * Tohle je jediný doložitelný výchozí stav pro detekci změn: verzovaný
     * profil `payroll_registration_a1_profiles` sice hlásitelné údaje nese,
     * ale nemá `environment` ani vazbu na podání, takže z něj nejde poznat,
     * jestli daná verze na ČSSZ vůbec doputovala. Porovnávat proti neodeslané
     * verzi by znamenalo hlásit změnu údaje, který úřad nikdy neviděl.
     *
     * Stavy jsou proto zúžené na ty, po kterých už podání odešlo. `draft`,
     * `ready` ani `rejected` mezi ně nepatří — odmítnuté podání nic
     * nezaregistrovalo, takže se nemá co měnit.
     *
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * }|null
     */
    public function latestSubmittedForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): ?array {
        return $this->findOne(
            'SELECT snapshot.*
               FROM payroll_registration_identity_snapshots snapshot
               JOIN payroll_submissions submission
                 ON submission.supplier_id = snapshot.supplier_id
                AND submission.environment = snapshot.environment
                AND submission.id = snapshot.submission_id
              WHERE snapshot.supplier_id = ?
                AND snapshot.environment = ?
                AND snapshot.employment_id = ?
                AND snapshot.agenda_code = \'REGZEC25\'
                AND submission.status IN (
                      \'submitted\', \'processing\', \'accepted\',
                      \'partially_accepted\'
                    )
              ORDER BY snapshot.effective_on DESC, snapshot.id DESC
              LIMIT 1',
            [$supplierId, $environment, $employmentId],
        );
    }

    /**
     * @param array{
     *   supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int
     * } $record
     */
    public function insert(array $record): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_identity_snapshots
                (supplier_id, environment, submission_id,
                 source_revision_id, employee_id, employment_id,
                 agenda_code, effective_on, schema_reference,
                 source_manifest_json, source_manifest_hash,
                 snapshot_ciphertext, snapshot_fingerprint,
                 request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $record['supplier_id'],
            $record['environment'],
            $record['submission_id'],
            $record['source_revision_id'],
            $record['employee_id'],
            $record['employment_id'],
            $record['agenda_code'],
            $record['effective_on'],
            $record['schema_reference'],
            $record['source_manifest_json'],
            $record['source_manifest_hash'],
            $record['snapshot_ciphertext'],
            $record['snapshot_fingerprint'],
            $record['request_fingerprint'],
            $record['idempotency_key_hash'],
            $record['created_by'],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<int|string> $parameters
     * @return array{
     *   id:int,supplier_id:int,environment:string,submission_id:int,
     *   source_revision_id:int,employee_id:int,employment_id:int,
     *   agenda_code:string,effective_on:string,schema_reference:string,
     *   source_manifest_json:string,source_manifest_hash:string,
     *   snapshot_ciphertext:string,snapshot_fingerprint:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   created_by:?int,created_at:string
     * }|null
     */
    private function findOne(string $sql, array $parameters): ?array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'id' => $this->positiveInt($row, 'id'),
            'supplier_id' => $this->positiveInt($row, 'supplier_id'),
            'environment' => $this->string($row, 'environment'),
            'submission_id' => $this->positiveInt($row, 'submission_id'),
            'source_revision_id' =>
                $this->positiveInt($row, 'source_revision_id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'agenda_code' => $this->string($row, 'agenda_code'),
            'effective_on' => $this->string($row, 'effective_on'),
            'schema_reference' =>
                $this->string($row, 'schema_reference'),
            'source_manifest_json' =>
                $this->string($row, 'source_manifest_json'),
            'source_manifest_hash' =>
                $this->hash($row, 'source_manifest_hash'),
            'snapshot_ciphertext' =>
                $this->string($row, 'snapshot_ciphertext'),
            'snapshot_fingerprint' =>
                $this->hash($row, 'snapshot_fingerprint'),
            'request_fingerprint' =>
                $this->hash($row, 'request_fingerprint'),
            'idempotency_key_hash' =>
                $this->binaryHash($row, 'idempotency_key_hash'),
            'created_by' => $this->nullablePositiveInt($row, 'created_by'),
            'created_at' => $this->string($row, 'created_at'),
        ];
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný snapshot registrační identity.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč snapshotu identity.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function hash(array $row, string $key): string
    {
        $value = $this->string($row, $key);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný otisk {$key}.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function binaryHash(array $row, string $key): string
    {
        $value = $this->string($row, $key);
        if (strlen($value) !== 32) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný binární otisk {$key}.",
            );
        }

        return $value;
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

