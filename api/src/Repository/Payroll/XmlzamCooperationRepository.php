<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final readonly class XmlzamCooperationRepository
{
    public function __construct(private Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function pendingSourceCandidates(int $supplierId, string $environment): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT inbox.id AS inbox_message_id,
                    inbox.external_message_id, inbox.sender_box_id, inbox.sender_name,
                    inbox.subject, inbox.delivered_at, inbox.fetched_at,
                    child.id AS document_id, file_row.id AS document_file_id,
                    file_row.original_name, file_row.mime_type, file_row.size_bytes,
                    file_row.sha256
               FROM submission_inbox_messages inbox
               JOIN documents child
                 ON child.supplier_id = inbox.supplier_id
                AND child.parent_document_id = inbox.document_id
                AND child.source = \'zfo_extract\'
                AND child.deleted_at IS NULL
               JOIN document_files file_row
                 ON file_row.supplier_id = child.supplier_id
                AND file_row.document_id = child.id
                AND file_row.deleted_at IS NULL
              WHERE inbox.supplier_id = ? AND inbox.environment = ?
                AND inbox.channel = \'isds\' AND inbox.hidden_at IS NULL
                AND inbox.local_content_state = \'available\'
                AND LOWER(file_row.mime_type) LIKE \'%xml%\'
                AND NOT EXISTS (
                  SELECT 1 FROM payroll_enforcement_xmlzam_requests request_row
                   WHERE request_row.supplier_id = inbox.supplier_id
                     AND request_row.environment = inbox.environment
                     AND request_row.inbox_message_id = inbox.id
                     AND request_row.source_document_file_id = file_row.id
                )
              ORDER BY COALESCE(inbox.delivered_at, inbox.fetched_at) DESC,
                       inbox.id DESC, file_row.id ASC
              LIMIT 100'
        );
        $stmt->execute([$supplierId, $environment]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[] = [
                'inbox_message_id' => (int) $row['inbox_message_id'],
                'document_id' => (int) $row['document_id'],
                'document_file_id' => (int) $row['document_file_id'],
                'external_message_id' => (string) $row['external_message_id'],
                'sender_box_id' => $row['sender_box_id'] === null ? null : (string) $row['sender_box_id'],
                'sender_name' => $row['sender_name'] === null ? null : (string) $row['sender_name'],
                'subject' => $row['subject'] === null ? null : (string) $row['subject'],
                'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
                'fetched_at' => (string) $row['fetched_at'],
                'original_name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => (string) $row['sha256'],
            ];
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function sourceAttachment(int $supplierId, string $environment, int $inboxId, int $fileId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT inbox.id AS inbox_id, inbox.environment, inbox.sender_box_id,
                    inbox.document_id AS container_document_id, inbox.hidden_at,
                    inbox.local_content_state, child.id AS source_document_id,
                    child.parent_document_id, child.source, child.deleted_at AS document_deleted_at,
                    file_row.id AS source_document_file_id, file_row.sha256, file_row.filename,
                    file_row.original_name, file_row.mime_type, file_row.deleted_at AS file_deleted_at
               FROM submission_inbox_messages inbox
               JOIN documents child
                 ON child.supplier_id = inbox.supplier_id
                AND child.parent_document_id = inbox.document_id
               JOIN document_files file_row
                 ON file_row.supplier_id = inbox.supplier_id
                AND file_row.document_id = child.id
              WHERE inbox.supplier_id = ? AND inbox.environment = ? AND inbox.channel = \'isds\'
                AND inbox.id = ? AND file_row.id = ?'
        );
        $stmt->execute([$supplierId, $environment, $inboxId, $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array{employee_id:int,identifier_hash:?string}> */
    public function identityCandidates(
        int $supplierId,
        string $givenName,
        string $familyName,
        string $birthDate,
        string $issuedOn,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT identity_row.employee_id, identifier.value_hash AS identifier_hash
               FROM payroll_person_identity_history identity_row
          LEFT JOIN payroll_person_identifiers identifier
                 ON identifier.supplier_id = identity_row.supplier_id
                AND identifier.employee_id = identity_row.employee_id
                AND identifier.identifier_type = \'birth_number\'
              WHERE identity_row.supplier_id = ?
                AND LOWER(TRIM(identity_row.first_name)) = LOWER(TRIM(?))
                AND LOWER(TRIM(identity_row.last_name)) = LOWER(TRIM(?))
                AND identity_row.birth_date = ?
                AND identity_row.effective_from <= ?
                AND (identity_row.effective_to IS NULL OR identity_row.effective_to >= ?)'
        );
        $stmt->execute([$supplierId, $givenName, $familyName, $birthDate, $issuedOn, $issuedOn]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[] = [
                'employee_id' => (int) $row['employee_id'],
                'identifier_hash' => $row['identifier_hash'] === null ? null : (string) $row['identifier_hash'],
            ];
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $record
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function insertRequest(array $record): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_enforcement_xmlzam_requests
                    (supplier_id, environment, employee_id, inbox_message_id,
                     source_document_id, source_document_file_id, request_identifier,
                     issued_on, executor_box_id, source_xml_sha256, snapshot_ciphertext,
                     snapshot_fingerprint, imported_by, imported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $record['supplier_id'], $record['environment'], $record['employee_id'],
                $record['inbox_message_id'], $record['source_document_id'],
                $record['source_document_file_id'], $record['request_identifier'],
                $record['issued_on'], $record['executor_box_id'], $record['source_xml_sha256'],
                $record['snapshot_ciphertext'], $record['snapshot_fingerprint'], $record['imported_by'],
            ]);
            $id = (int) $this->db->pdo()->lastInsertId();
            $row = $this->findRequest((int) $record['supplier_id'], (string) $record['environment'], $id);
            if ($row === null) {
                throw new \RuntimeException('Uložený XMLZAM požadavek nelze načíst.');
            }
            return ['row' => $row, 'created' => true];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $row = $this->findRequestBySource(
                (int) $record['supplier_id'],
                (string) $record['environment'],
                (int) $record['inbox_message_id'],
                (int) $record['source_document_file_id'],
            );
            if ($row === null
                || !hash_equals((string) $row['source_xml_sha256'], (string) $record['source_xml_sha256'])
                || !hash_equals((string) $row['snapshot_fingerprint'], (string) $record['snapshot_fingerprint'])
            ) {
                throw new \DomainException('XMLZAM idempotency key koliduje s jiným požadavkem.', previous: $e);
            }
            return ['row' => $row, 'created' => false];
        }
    }

    /** @return array<string,mixed>|null */
    public function findRequest(int $supplierId, string $environment, int $id): ?array
    {
        return $this->one(
            'SELECT * FROM payroll_enforcement_xmlzam_requests
              WHERE supplier_id = ? AND environment = ? AND id = ?',
            [$supplierId, $environment, $id],
        );
    }

    /** @return array{id:int,full_name:string,is_active:bool}|null */
    public function employeeSummary(int $supplierId, int $employeeId): ?array
    {
        $row = $this->one(
            'SELECT id, full_name, is_active FROM payroll_employees
              WHERE supplier_id = ? AND id = ?',
            [$supplierId, $employeeId],
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function findRequestBySource(int $supplierId, string $environment, int $inboxId, int $fileId): ?array
    {
        return $this->one(
            'SELECT * FROM payroll_enforcement_xmlzam_requests
              WHERE supplier_id = ? AND environment = ?
                AND inbox_message_id = ? AND source_document_file_id = ?',
            [$supplierId, $environment, $inboxId, $fileId],
        );
    }

    /** @return array<string,mixed>|null */
    public function caseForEmployee(int $supplierId, int $caseId, int $employeeId): ?array
    {
        return $this->one(
            'SELECT * FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ? AND employee_id = ?',
            [$supplierId, $caseId, $employeeId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeClaims(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT claim_row.*, case_row.employee_id
               FROM payroll_enforcement_claims claim_row
               JOIN payroll_enforcement_cases case_row
                 ON case_row.supplier_id = claim_row.supplier_id AND case_row.id = claim_row.case_id
              WHERE claim_row.supplier_id = ? AND case_row.employee_id = ?
                AND claim_row.is_active = 1 AND claim_row.outstanding_minor_units > 0
                AND case_row.status IN (\'withhold_and_hold\',\'remit\',\'deferred_hold\')
              ORDER BY claim_row.priority_date, claim_row.id'
        );
        $stmt->execute([$supplierId, $employeeId]);
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function approvedRevisionForPeriod(int $supplierId, int $employeeId, string $periodStart): ?array
    {
        return $this->one(
            'SELECT revision.id, revision.run_id, revision.revision_no, revision.input_snapshot_hash,
                    revision.result_snapshot_hash, run_row.period_start,
                    enforcement.input_snapshot_json AS enforcement_input_json,
                    enforcement.input_snapshot_hash AS enforcement_input_hash
               FROM payroll_runs run_row
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_row.supplier_id AND revision.run_id = run_row.id
                AND revision.status = \'approved\'
               JOIN payroll_run_persons person_row
                 ON person_row.supplier_id = revision.supplier_id
                AND person_row.revision_id = revision.id AND person_row.employee_id = ?
                AND person_row.status = \'calculated\'
          LEFT JOIN payroll_enforcement_month_results enforcement
                 ON enforcement.supplier_id = revision.supplier_id
                AND enforcement.revision_id = revision.id AND enforcement.employee_id = ?
              WHERE run_row.supplier_id = ? AND run_row.period_start = ?
              ORDER BY revision.revision_no DESC LIMIT 1',
            [$employeeId, $employeeId, $supplierId, $periodStart],
        );
    }

    /** @return array{active:bool,start:string,end:?string} */
    public function employmentSummary(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(start_date) AS employed_from,
                    MAX(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) AS is_active,
                    CASE WHEN MAX(CASE WHEN status = \'active\' THEN 1 ELSE 0 END) = 1
                         THEN NULL ELSE MAX(end_date) END AS employed_to
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ? AND status IN (\'active\',\'ended\')
                AND start_date IS NOT NULL'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['employed_from'] === null) {
            throw new \DomainException('Pro odpověď XMLZAM chybí ověřené období zaměstnání.');
        }
        return [
            'active' => (int) $row['is_active'] === 1,
            'start' => (string) $row['employed_from'],
            'end' => $row['employed_to'] === null ? null : (string) $row['employed_to'],
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function insertResponse(array $record): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_enforcement_xmlzam_responses
                    (supplier_id, environment, request_id, case_id, response_identifier,
                     includes_wages, source_manifest_json, source_manifest_sha256, snapshot_ciphertext,
                     snapshot_fingerprint, xml_ciphertext, xml_sha256, idempotency_key_hash,
                     approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $record['supplier_id'], $record['environment'], $record['request_id'], $record['case_id'],
                $record['response_identifier'], $record['includes_wages'] ? 1 : 0, $record['source_manifest_json'],
                $record['source_manifest_sha256'], $record['snapshot_ciphertext'],
                $record['snapshot_fingerprint'], $record['xml_ciphertext'], $record['xml_sha256'],
                $record['idempotency_key_hash'], $record['approved_by'],
            ]);
            $row = $this->findResponse((int) $record['supplier_id'], (string) $record['environment'], (int) $this->db->pdo()->lastInsertId());
            if ($row === null) {
                throw new \RuntimeException('Uloženou odpověď XMLZAM nelze načíst.');
            }
            return ['row' => $row, 'created' => true];
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $row = $this->one(
                'SELECT * FROM payroll_enforcement_xmlzam_responses
                  WHERE supplier_id = ? AND environment = ? AND idempotency_key_hash = ?',
                [$record['supplier_id'], $record['environment'], $record['idempotency_key_hash']],
            );
            if ($row === null || !hash_equals((string) $row['snapshot_fingerprint'], (string) $record['snapshot_fingerprint'])) {
                throw new \DomainException('XMLZAM idempotency key koliduje s jinou odpovědí.', previous: $e);
            }
            return ['row' => $row, 'created' => false];
        }
    }

    /** @return array<string,mixed>|null */
    public function findResponse(int $supplierId, string $environment, int $id): ?array
    {
        return $this->one(
            'SELECT response_row.*, request_row.executor_box_id
               FROM payroll_enforcement_xmlzam_responses response_row
               JOIN payroll_enforcement_xmlzam_requests request_row
                 ON request_row.supplier_id = response_row.supplier_id AND request_row.id = response_row.request_id
              WHERE response_row.supplier_id = ? AND response_row.environment = ? AND response_row.id = ?',
            [$supplierId, $environment, $id],
        );
    }

    /** @return array<string,mixed>|null */
    public function findResponseByIdempotency(
        int $supplierId,
        string $environment,
        string $idempotencyKeyHash,
    ): ?array {
        return $this->one(
            'SELECT * FROM payroll_enforcement_xmlzam_responses
              WHERE supplier_id = ? AND environment = ? AND idempotency_key_hash = ?',
            [$supplierId, $environment, $idempotencyKeyHash],
        );
    }

    public function recordDispatch(int $supplierId, string $environment, int $responseId, int $outboxId, int $userId): int
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO payroll_enforcement_xmlzam_dispatches
                    (supplier_id, environment, response_id, outbox_id, enqueued_by, enqueued_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([$supplierId, $environment, $responseId, $outboxId, $userId]);
            return (int) $this->db->pdo()->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $row = $this->one(
                'SELECT id, outbox_id FROM payroll_enforcement_xmlzam_dispatches
                  WHERE supplier_id = ? AND environment = ? AND response_id = ?',
                [$supplierId, $environment, $responseId],
            );
            if ($row === null || (int) $row['outbox_id'] !== $outboxId) {
                throw new \DomainException('Odpověď XMLZAM už je navázána na jinou frontu.', previous: $e);
            }
            return (int) $row['id'];
        }
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
