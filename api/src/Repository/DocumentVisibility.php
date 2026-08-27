<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

final class DocumentVisibility
{
    /** @return array{0:string,1:list<int>} */
    public static function clause(DocumentViewerContext $viewer, string $alias = ''): array
    {
        $col = $alias !== '' ? $alias . '.' : '';
        $sql = '';
        $params = [];
        if (!$viewer->isAdmin) {
            if ($viewer->userId === null) {
                $sql = " AND {$col}scope = 'company'";
            } else {
                $sql = " AND ({$col}scope = 'company' OR ({$col}scope = 'user' AND {$col}owner_user_id = ?))";
                $params[] = $viewer->userId;
            }
        }
        if (!$viewer->canViewPayrollEnforcementEvidence) {
            $table = $alias !== '' ? $alias : 'documents';
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_case_documents payroll_evidence
                 WHERE payroll_evidence.supplier_id = {$table}.supplier_id
                   AND (payroll_evidence.dms_document_id = {$table}.id
                     OR payroll_evidence.dms_document_id = {$table}.parent_document_id)
            )";
        }
        $table = $alias !== '' ? $alias : 'documents';
        if (!$viewer->canViewPayrollInsolvencyEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_insolvency_payment_instructions insolvency_evidence
                 WHERE insolvency_evidence.supplier_id = {$table}.supplier_id
                   AND (insolvency_evidence.decision_document_id = {$table}.id
                     OR insolvency_evidence.decision_document_id = {$table}.parent_document_id)
            )";
        }
        if (!$viewer->canViewPayrollSubmissionEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_statutory_obligation_evidence submission_evidence
                 WHERE submission_evidence.supplier_id = {$table}.supplier_id
                   AND (submission_evidence.document_id = {$table}.id
                     OR submission_evidence.document_id = {$table}.parent_document_id)
            )";
        }
        return [$sql, $params];
    }
}
