<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final readonly class PayrollRiskySavingsRepository
{
    public function __construct(private Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listPeriod(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT evidence.*, employee.full_name, employment.code AS employment_code,
                    account.institution_name AS payment_target_name,
                    evidence.institution_account_masked,
                    evidence.institution_account_row_version,
                    evidence.institution_account_hash,
                    contribution.id AS contribution_id,
                    contribution.revision_id,
                    contribution.assessment_base_minor,
                    contribution.contribution_minor,
                    contribution.payment_due_on,
                    contribution.paid_on,
                    contribution.status AS contribution_status
               FROM payroll_risky_savings_evidence evidence
               JOIN payroll_employments employment
                 ON employment.supplier_id = evidence.supplier_id
                AND employment.id = evidence.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
          LEFT JOIN payroll_institution_accounts account
                 ON account.supplier_id = evidence.supplier_id
                AND account.id = evidence.institution_account_id
          LEFT JOIN payroll_risky_savings_contributions contribution
                 ON contribution.supplier_id = evidence.supplier_id
                AND contribution.source_evidence_id = evidence.id
                AND contribution.id = (
                    SELECT selected.id
                      FROM payroll_risky_savings_contributions selected
                      JOIN payroll_run_revisions revision
                        ON revision.supplier_id = selected.supplier_id
                       AND revision.id = selected.revision_id
                     WHERE selected.supplier_id = evidence.supplier_id
                       AND selected.source_evidence_id = evidence.id
                       AND revision.status IN ("approved", "superseded")
                     ORDER BY (revision.status = "approved") DESC,
                              revision.revision_no DESC, selected.id DESC
                     LIMIT 1
                )
              WHERE evidence.supplier_id = ?
                AND evidence.period_start = ?
                AND evidence.revision_no = (
                    SELECT MAX(current_evidence.revision_no)
                      FROM payroll_risky_savings_evidence current_evidence
                     WHERE current_evidence.supplier_id = evidence.supplier_id
                       AND current_evidence.employment_id = evidence.employment_id
                       AND current_evidence.period_start = evidence.period_start
                )
              ORDER BY employee.full_name, employment.code, evidence.id'
        );
        $statement->execute([$supplierId, $periodStart]);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findEvidence(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_risky_savings_evidence
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
              . ' ORDER BY revision_no DESC, id DESC LIMIT 1'
        );
        $statement->execute([$supplierId, $employmentId, $periodStart]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function saveEvidence(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        array $data,
        int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $employment = $pdo->prepare(
            'SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $employment->execute([$supplierId, $employmentId]);
        if (!$employment->fetchColumn()) {
            throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
        }
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare(
                'SELECT id, revision_no, status, row_version
                   FROM payroll_risky_savings_evidence
                  WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                  ORDER BY revision_no DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $lock->execute([$supplierId, $employmentId, $periodStart]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            $expectedId = $data['source_evidence_id'] ?? null;
            $expectedVersion = $data['row_version'] ?? null;
            if (is_array($current)
                && ((int) $current['id'] !== $expectedId
                    || (int) $current['row_version'] !== $expectedVersion)
            ) {
                throw new PayrollRiskySavingsConflictException(
                    (int) $current['row_version'],
                );
            }

            $approved = ($data['status'] ?? null) === 'approved';
            $revisionNo = is_array($current)
                ? (int) $current['revision_no'] : 1;
            $insert = !is_array($current) || $current['status'] !== 'draft';
            if ($insert && is_array($current)) {
                ++$revisionNo;
            }
            $values = [
                $data['risk_factor'],
                $data['work_category'],
                $data['qualifying_shift_eighths'],
                $data['right_claimed_on'],
                $data['employee_informed_on'],
                $data['pension_company'],
                $data['institution_account_id'],
                $data['institution_account_row_version'],
                $data['institution_account_hash'],
                $data['institution_account_masked'],
                $data['product_reference'],
                $data['variable_symbol'],
                $data['specific_symbol'],
                $data['payment_message'],
                $data['evidence_reference'],
                $data['status'],
                $approved ? date('Y-m-d H:i:s') : null,
                $approved ? $actorUserId : null,
            ];
            if ($insert) {
                $statement = $pdo->prepare(
                    'INSERT INTO payroll_risky_savings_evidence
                        (supplier_id, employment_id, period_start, revision_no,
                         risk_factor, work_category, qualifying_shift_eighths,
                         right_claimed_on, employee_informed_on, pension_company,
                         institution_account_id, institution_account_row_version,
                         institution_account_hash, institution_account_masked,
                         product_reference,
                         variable_symbol, specific_symbol, payment_message,
                         evidence_reference, status, approved_at, approved_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $supplierId, $employmentId, $periodStart, $revisionNo, ...$values,
                ]);
                $savedId = (int) $pdo->lastInsertId();
                if (is_array($current)) {
                    $supersede = $pdo->prepare(
                        'UPDATE payroll_risky_savings_evidence
                            SET status = "superseded", superseded_at = CURRENT_TIMESTAMP,
                                superseded_by = ?, row_version = row_version + 1
                          WHERE supplier_id = ? AND id = ? AND status = "approved"'
                    );
                    $supersede->execute([$actorUserId, $supplierId, $current['id']]);
                }
            } else {
                $statement = $pdo->prepare(
                    'UPDATE payroll_risky_savings_evidence
                        SET risk_factor = ?, work_category = ?,
                            qualifying_shift_eighths = ?, right_claimed_on = ?,
                            employee_informed_on = ?, pension_company = ?,
                            institution_account_id = ?,
                            institution_account_row_version = ?,
                            institution_account_hash = ?, institution_account_masked = ?,
                            product_reference = ?,
                            variable_symbol = ?, specific_symbol = ?, payment_message = ?,
                            evidence_reference = ?, status = ?, approved_at = ?,
                            approved_by = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND status = "draft"
                        AND row_version = ?'
                );
                $statement->execute([
                    ...$values, $supplierId, $current['id'], $expectedVersion,
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new PayrollRiskySavingsConflictException(
                        (int) $current['row_version'],
                    );
                }
                $savedId = (int) $current['id'];
            }
            $saved = $this->findEvidenceById($supplierId, $savedId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $saved;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function paymentTarget(
        int $supplierId,
        int $accountId,
        string $effectiveOn,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT account.id, account.institution_name,
                    account.bank_account_masked,
                    LOWER(HEX(account.bank_account_hash)) AS bank_account_hash,
                    account.variable_symbol, account.specific_symbol,
                    account.row_version
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ? AND account.id = ?
                AND institution.institution_type = "other_recipient"
                AND account.currency_code = "CZK"
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)'
        );
        $statement->execute([$supplierId, $accountId, $effectiveOn, $effectiveOn]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException(
                'Vyberte platný ověřený účet penzijní společnosti pro datum splatnosti.',
            );
        }
        return [
            'institution_account_id' => (int) $row['id'],
            'institution_account_row_version' => (int) $row['row_version'],
            'institution_account_hash' => (string) $row['bank_account_hash'],
            'institution_account_masked' => (string) $row['bank_account_masked'],
            'payment_target_name' => (string) $row['institution_name'],
            'default_variable_symbol' => $row['variable_symbol'],
            'default_specific_symbol' => $row['specific_symbol'],
        ];
    }

    /** @return array<string,mixed> */
    private function findEvidenceById(int $supplierId, int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_risky_savings_evidence
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? $row
            : throw new \LogicException('Evidence se po uložení nenašla.');
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array<string,mixed>>
     */
    public function snapshotMany(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
    ): array {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($employmentIds, 500) as $chunk) {
            $statement = $this->db->pdo()->prepare(
                'SELECT evidence.id, evidence.employment_id, evidence.period_start,
                        evidence.revision_no, evidence.risk_factor,
                        evidence.work_category, evidence.qualifying_shift_eighths,
                        evidence.right_claimed_on, evidence.employee_informed_on,
                        evidence.pension_company, evidence.institution_account_id,
                        evidence.institution_account_row_version,
                        evidence.institution_account_hash,
                        evidence.institution_account_masked,
                        account.row_version AS current_institution_account_row_version,
                        LOWER(HEX(account.bank_account_hash))
                            AS current_institution_account_hash,
                        evidence.product_reference, evidence.variable_symbol,
                        evidence.specific_symbol, evidence.payment_message,
                        evidence.evidence_reference, evidence.status,
                        evidence.row_version, evidence.approved_at,
                        evidence.approved_by
                   FROM payroll_risky_savings_evidence evidence
              LEFT JOIN payroll_institution_accounts account
                     ON account.supplier_id = evidence.supplier_id
                    AND account.id = evidence.institution_account_id
                  WHERE evidence.supplier_id = ?
                    AND evidence.employment_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND evidence.period_start = ?
                    AND evidence.revision_no = (
                        SELECT MAX(selected.revision_no)
                          FROM payroll_risky_savings_evidence selected
                         WHERE selected.supplier_id = evidence.supplier_id
                           AND selected.employment_id = evidence.employment_id
                           AND selected.period_start = evidence.period_start
                    )
                  ORDER BY evidence.employment_id, evidence.id'
            );
            $statement->execute([$supplierId, ...$chunk, $periodStart]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['id'] = (int) $row['id'];
                $row['employment_id'] = (int) $row['employment_id'];
                $row['qualifying_shift_eighths'] =
                    (int) $row['qualifying_shift_eighths'];
                $row['row_version'] = (int) $row['row_version'];
                $row['current_institution_account_row_version'] =
                    $row['current_institution_account_row_version'] === null
                        ? null
                        : (int) $row['current_institution_account_row_version'];
                $row['approved_by'] = $row['approved_by'] === null
                    ? null : (int) $row['approved_by'];
                $result[$row['employment_id']] = $row;
            }
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param list<array<string,mixed>> $rows */
    public function storeApproved(
        int $supplierId,
        int $revisionId,
        string $periodStart,
        array $rows,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_risky_savings_contributions
                (supplier_id, revision_id, employment_id, source_evidence_id,
                 period_start, qualifying_shifts, qualifying_shift_eighths,
                 assessment_base_minor, contribution_minor, status,
                 right_claimed_on, pension_company, institution_account_id,
                 institution_account_row_version, institution_account_hash,
                 institution_account_masked,
                 product_reference, variable_symbol, specific_symbol,
                 payment_message, payment_due_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "approved", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        foreach ($rows as $row) {
            if (($row['status'] ?? null) !== 'calculated') {
                continue;
            }
            $eighths = (int) $row['qualifying_shift_eighths'];
            $statement->execute([
                $supplierId,
                $revisionId,
                $row['employment_id'],
                $row['source_evidence_id'],
                $periodStart,
                intdiv($eighths + 7, 8),
                $eighths,
                $row['assessment_base_minor'],
                $row['contribution_minor'],
                $row['right_claimed_on'],
                $row['pension_company'],
                $row['institution_account_id'],
                $row['institution_account_row_version'],
                $row['institution_account_hash'],
                $row['institution_account_masked'],
                $row['product_reference'],
                $row['variable_symbol'],
                $row['specific_symbol'],
                $row['payment_message'],
                $row['payment_due_on'],
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function lockApprovedContributionsForRevision(
        int $supplierId,
        int $revisionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT contribution.id, contribution.employment_id,
                    contribution.source_evidence_id,
                    contribution.contribution_minor,
                    contribution.payment_due_on,
                    contribution.pension_company,
                    contribution.product_reference,
                    contribution.institution_account_id,
                    contribution.institution_account_row_version,
                    contribution.institution_account_hash,
                    contribution.institution_account_masked,
                    contribution.variable_symbol,
                    contribution.specific_symbol,
                    contribution.payment_message,
                    institution.institution_code
               FROM payroll_risky_savings_contributions contribution
               JOIN payroll_institution_accounts account
                 ON account.supplier_id = contribution.supplier_id
                AND account.id = contribution.institution_account_id
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE contribution.supplier_id = ?
                AND contribution.revision_id = ?
                AND contribution.status = "approved"
                AND contribution.contribution_minor > 0
              ORDER BY contribution.employment_id, contribution.id
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $revisionId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ([
                'id', 'employment_id', 'source_evidence_id',
                'contribution_minor', 'institution_account_id',
                'institution_account_row_version',
            ] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $result[] = $row;
        }
        return $result;
    }
}
