<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA2EvidencePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA2EvidencePlan;
use PDO;
use PDOException;

final class PayrollRegistrationA2EvidenceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function correctiveMonths(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        string $employmentExternalIdentifier,
        bool $forUpdate = false,
    ): array {
        $periodEnd = substr($effectiveOn, 0, 7) . '-01';
        $statement = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id, run.period_start,
                    revision.id AS revision_id
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
                AND revision.status = "approved"
                AND revision.revision_kind = "correction"
               JOIN payroll_run_employments employment_result
                 ON employment_result.supplier_id = revision.supplier_id
                AND employment_result.revision_id = revision.id
                AND employment_result.employment_id = ?
              WHERE run.supplier_id = ?
                AND run.period_start BETWEEN "2026-01-01" AND ?
              ORDER BY run.period_start, run.id'
                . ($forUpdate ? ' FOR UPDATE' : ''),
        );
        $statement->execute([$employmentId, $supplierId, $periodEnd]);
        $months = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $months[] = $this->monthEvidence(
                $supplierId,
                $environment,
                (int) $row['run_id'],
                (int) $row['revision_id'],
                (string) $row['period_start'],
                $employmentExternalIdentifier,
                $forUpdate,
            );
        }
        return $months;
    }

    public function append(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $eventId,
        PayrollRegistrationA2EvidencePlan $plan,
        ?int $createdBy,
    ): void {
        $payload = $plan->toArray();
        unset($payload['fingerprint']);
        $json = CanonicalJson::encode($payload);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_a2_evidence_ledger
                (supplier_id, environment, employment_id, event_snapshot_id,
                 schema_reference, policy_reference, decision, plan_json,
                 plan_sha256, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        try {
            $statement->execute([
                $supplierId,
                $environment,
                $employmentId,
                $eventId,
                PayrollRegistrationA2EvidencePlan::SCHEMA_REFERENCE,
                PayrollRegistrationA2EvidencePlan::POLICY_REFERENCE,
                $plan->decision(),
                $json,
                $plan->fingerprint(),
                $createdBy,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $existing = $this->findForEvent($supplierId, $environment, $eventId);
            if ($existing === null
                || !hash_equals((string) $existing['plan_sha256'], $plan->fingerprint())
                || !hash_equals((string) $existing['plan_json'], $json)
            ) {
                throw new \DomainException('Událost REGZEC A2 už má jiný neměnný důkazní plán.');
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function findForEvent(int $supplierId, string $environment, int $eventId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_registration_a2_evidence_ledger
              WHERE supplier_id = ? AND environment = ? AND event_snapshot_id = ?',
        );
        $statement->execute([$supplierId, $environment, $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function monthEvidence(
        int $supplierId,
        string $environment,
        int $runId,
        int $revisionId,
        string $periodStart,
        string $employmentExternalIdentifier,
        bool $forUpdate,
    ): array {
        $preparation = $this->one(
            'SELECT id
               FROM payroll_jmhz_preparation_snapshots
              WHERE supplier_id = ? AND environment = ?
                AND run_id = ? AND source_revision_id = ?
                AND readiness_status = "source_ready"
              ORDER BY id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $environment, $runId, $revisionId],
        );
        $base = [
            'period_start' => $periodStart,
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'preparation_id' => $preparation === null ? null : (int) $preparation['id'],
            'submission_id' => null,
            'transport_attempt_id' => null,
            'receipt_id' => null,
            'submission_status' => null,
            'transport_status' => null,
            'transport_sent_at' => null,
            'transport_correlation_reference' => null,
            'receipt_status' => null,
            'receipt_verification_status' => null,
            'receipt_correlation_reference' => null,
            'form_status' => null,
        ];
        if ($preparation === null) {
            return [...$base, ...[
                'decision' => 'missing',
                'reason' => 'Opravná mzdová revize nemá zdrojově připravené JMHZ.',
            ]];
        }
        $sourceReference = 'jmhz_preparation:' . (int) $preparation['id'];
        $submission = $this->one(
            'SELECT submission.id, submission.status
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ? AND part.environment = ?
                AND part.source_entity_type = "jmhz_preparation"
                AND part.source_entity_reference = ?
                AND submission.submission_kind = "correction"
              ORDER BY submission.id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $environment, $sourceReference],
        );
        if ($submission === null) {
            return [...$base, ...[
                'decision' => 'missing',
                'reason' => 'Opravná mzdová revize nemá vytvořené opravné JMHZ.',
            ]];
        }
        $submissionId = (int) $submission['id'];
        $attempt = $this->one(
            'SELECT id, status, correlation_reference, sent_at
               FROM payroll_submission_transport_attempts
              WHERE supplier_id = ? AND environment = ? AND submission_id = ?
              ORDER BY attempt_no DESC, id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$supplierId, $environment, $submissionId],
        );
        $receipt = $this->one(
            'SELECT receipt.id, receipt.remote_status, receipt.correlation_reference,
                    receipt.verification_status, outcome.remote_status AS form_status
               FROM payroll_submission_receipts receipt
               LEFT JOIN payroll_jmhz_protocol_form_outcomes outcome
                 ON outcome.supplier_id = receipt.supplier_id
                AND outcome.environment = receipt.environment
                AND outcome.receipt_id = receipt.id
                AND outcome.external_employment_reference = ?
              WHERE receipt.supplier_id = ? AND receipt.environment = ?
                AND receipt.submission_id = ?
                AND receipt.protocol_code = "CSSZ_JMHZ"
              ORDER BY receipt.received_at DESC, receipt.id DESC
              LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$employmentExternalIdentifier, $supplierId, $environment, $submissionId],
        );
        $row = [...$base, ...[
            'submission_id' => $submissionId,
            'transport_attempt_id' => $attempt === null ? null : (int) $attempt['id'],
            'receipt_id' => $receipt === null ? null : (int) $receipt['id'],
            'submission_status' => (string) $submission['status'],
            'transport_status' => $attempt === null ? null : (string) $attempt['status'],
            'transport_sent_at' => $attempt === null || $attempt['sent_at'] === null
                ? null
                : (string) $attempt['sent_at'],
            'transport_correlation_reference' => $attempt === null
                || $attempt['correlation_reference'] === null
                ? null
                : (string) $attempt['correlation_reference'],
            'receipt_status' => $receipt === null || $receipt['remote_status'] === null
                ? null
                : (string) $receipt['remote_status'],
            'receipt_verification_status' => $receipt === null
                ? null
                : (string) $receipt['verification_status'],
            'receipt_correlation_reference' => $receipt === null
                || $receipt['correlation_reference'] === null
                ? null
                : (string) $receipt['correlation_reference'],
            'form_status' => $receipt === null || $receipt['form_status'] === null
                ? null
                : (string) $receipt['form_status'],
        ]];
        return [...$row, ...PayrollRegistrationA2EvidencePolicy::decide([
            'submission_status' => $submission['status'],
            'transport_status' => $attempt['status'] ?? null,
            'sent_at' => $attempt['sent_at'] ?? null,
            'correlation_reference' => $attempt['correlation_reference'] ?? null,
            'receipt_correlation_reference' => $receipt['correlation_reference'] ?? null,
            'verification_status' => $receipt['verification_status'] ?? null,
            'receipt_status' => $receipt['remote_status'] ?? null,
            'form_status' => $receipt['form_status'] ?? null,
        ])];
    }

    /** @param list<int|string> $parameters @return array<string,mixed>|null */
    private function one(string $sql, array $parameters): ?array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
