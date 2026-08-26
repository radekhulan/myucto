<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final readonly class PayrollInsolvencyPaymentRepository
{
    public const LIABILITY_KIND = 'insolvency';

    public function __construct(private Connection $db) {}

    public static function liabilityReference(
        int $employeeId,
        int $employmentId,
    ): string {
        if ($employeeId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Reference oddlužení vyžaduje kladnou osobu a pracovní vztah.',
            );
        }

        return "insolvency:p{$employeeId}:e{$employmentId}";
    }

    /** @return list<array<string,mixed>> */
    public function payableForRevision(int $supplierId, int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT result.id AS month_result_id, result.employee_id,
                    result.period_start, result.input_snapshot_json,
                    result.input_snapshot_hash, allocation.total_minor_units,
                    instruction.id AS instruction_id,
                    instruction.instruction_hash,
                    instruction.employment_id,
                    instruction.institution_account_id,
                    instruction.institution_account_row_version,
                    instruction.institution_account_hash,
                    instruction.institution_type,
                    instruction.institution_code,
                    instruction.decision_document_id,
                    instruction.decision_document_hash,
                    account.institution_name,
                    account.bank_account_ciphertext,
                    LOWER(HEX(account.bank_account_hash)) AS current_account_hash,
                    account.currency_code, account.variable_symbol,
                    account.specific_symbol, account.constant_symbol,
                    account.valid_from, account.valid_to,
                    account.source_kind, account.source_reference,
                    account.verified_on, account.verified_by,
                    account.row_version AS current_account_row_version,
                    document.sha256 AS current_document_hash,
                    document.deleted_at AS document_deleted_at,
                    run.payment_date
               FROM payroll_enforcement_month_results result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               JOIN payroll_enforcement_allocations allocation
                 ON allocation.supplier_id = result.supplier_id
                AND allocation.month_result_id = result.id
                AND allocation.allocation_key = "insolvency-administrator"
               JOIN payroll_insolvency_payment_instructions instruction
                 ON instruction.supplier_id = result.supplier_id
                AND instruction.id = result.insolvency_payment_instruction_id
               JOIN payroll_institution_accounts account
                 ON account.supplier_id = instruction.supplier_id
                AND account.id = instruction.institution_account_id
               JOIN documents document
                 ON document.id = instruction.decision_document_id
                AND document.supplier_id = instruction.supplier_id
              WHERE result.supplier_id = ? AND result.revision_id = ?
                AND result.result_status = "supported"
              ORDER BY result.employee_id, instruction.employment_id,
                       result.id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $revisionId]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
