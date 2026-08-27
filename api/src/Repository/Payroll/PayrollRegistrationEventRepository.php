<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class PayrollRegistrationEventRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $record
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function insert(array $record): array
    {
        $existing = $this->findByBusinessKey($record);
        if ($existing !== null) {
            return $this->existingOrConflict($existing, $record);
        }
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_event_snapshots
                (supplier_id, employee_id, employment_id, environment,
                 interaction_code, action_code, effective_on, source_kind,
                 source_reference, source_manifest_json, source_manifest_hash,
                 snapshot_ciphertext, snapshot_fingerprint, approved_by,
                 approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        try {
            $statement->execute([
                $record['supplier_id'], $record['employee_id'],
                $record['employment_id'], $record['environment'],
                $record['interaction_code'], $record['action_code'],
                $record['effective_on'], $record['source_kind'],
                $record['source_reference'], $record['source_manifest_json'],
                $record['source_manifest_hash'], $record['snapshot_ciphertext'],
                $record['snapshot_fingerprint'], $record['approved_by'],
            ]);
        } catch (PDOException $exception) {
            if (!$this->isBusinessKeyRace($exception)) {
                throw $exception;
            }
            $existing = $this->findByBusinessKey($record);
            if ($existing === null) {
                throw $exception;
            }

            return $this->existingOrConflict($existing, $record);
        }

        $stored = $this->find(
            (int) $record['supplier_id'],
            (string) $record['environment'],
            (int) $this->db->pdo()->lastInsertId(),
        );
        if ($stored === null) {
            throw new \RuntimeException('Neměnný zdroj REGZEC nelze načíst.');
        }

        return ['row' => $stored, 'created' => true];
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function findByBusinessKey(array $record): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_event_snapshots
              WHERE supplier_id = ? AND environment = ? AND employment_id = ?
                AND interaction_code = ? AND effective_on = ?
                AND source_reference = ?'
        );
        $statement->execute([
            $record['supplier_id'],
            $record['environment'],
            $record['employment_id'],
            $record['interaction_code'],
            $record['effective_on'],
            $record['source_reference'],
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $record
     * @return array{row:array<string,mixed>,created:false}
     */
    private function existingOrConflict(array $existing, array $record): array
    {
        if (!hash_equals(
            (string) ($existing['snapshot_fingerprint'] ?? ''),
            (string) $record['snapshot_fingerprint'],
        )) {
            throw new \DomainException(
                'Stejná registrační událost REGZEC už byla schválena s jiným neměnným obsahem.',
            );
        }

        return ['row' => $existing, 'created' => false];
    }

    private function isBusinessKeyRace(PDOException $exception): bool
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }
        $message = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return str_contains($message, 'uq_payroll_registration_event_business')
            || str_contains($message, 'uq_payroll_registration_event_source');
    }

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $eventId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_event_snapshots
              WHERE supplier_id = ? AND environment = ? AND id = ?'
        );
        $statement->execute([$supplierId, $environment, $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT event.*,
                    EXISTS (
                      SELECT 1 FROM payroll_submission_parts part
                       WHERE part.supplier_id = event.supplier_id
                         AND part.environment = event.environment
                         AND part.source_entity_type = "payroll_registration_event"
                         AND part.source_entity_reference = CONCAT("payroll_registration_event:", event.id)
                    ) AS consumed
               FROM payroll_registration_event_snapshots event
              WHERE event.supplier_id = ? AND event.environment = ?
                AND event.employment_id = ?
              ORDER BY event.effective_on DESC, event.id DESC'
        );
        $statement->execute([$supplierId, $environment, $employmentId]);

        return array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            'is_array',
        ));
    }

    /** @return array<string,mixed>|null */
    public function employmentSourceAt(
        int $supplierId,
        int $employmentId,
        string $effectiveOn,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.status,
                    employment.start_date, employment.actual_start_date,
                    employment.end_date, employment.row_version,
                    supplier.company_name,
                    office.social_security_variable_symbol,
                    settings.social_security_office_code,
                    terms.id AS terms_id, terms.row_version AS terms_row_version,
                    terms.activity_code,
                    terms.jmhz_relationship_detail_code
               FROM payroll_employments employment
               JOIN supplier ON supplier.id = employment.supplier_id
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = employment.supplier_id
                AND office.id = employment.office_id
               LEFT JOIN payroll_employer_settings settings
                 ON settings.supplier_id = employment.supplier_id
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ? AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([
            $effectiveOn, $effectiveOn, $supplierId, $employmentId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function acceptedRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id, submission.status, part.agenda_code,
                    part.id AS part_id, part.source_snapshot_hash,
                    artifact.id AS artifact_id, artifact.artifact_sha256
               FROM payroll_submissions submission
               JOIN payroll_submission_parts part
                 ON part.supplier_id = submission.supplier_id
                AND part.environment = submission.environment
                AND part.submission_id = submission.id
               JOIN payroll_submission_artifacts artifact
                 ON artifact.supplier_id = part.supplier_id
                AND artifact.environment = part.environment
                AND artifact.submission_id = part.submission_id
                AND artifact.part_id = part.id
                AND artifact.artifact_kind = "outbound_xml"
                AND artifact.direction = "outbound"
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND submission.id = ?
                AND submission.status = "accepted"
                AND part.agenda_code = "REGZEC25"
                AND part.subject_reference = ?
              LIMIT 1'
        );
        $statement->execute([
            $supplierId, $environment, $submissionId,
            PayrollRegistrationSubmissionRepository::employmentReference(
                $employmentId,
            ),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
