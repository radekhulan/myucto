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
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_case_parties enforcement_party
                 WHERE enforcement_party.supplier_id = {$table}.supplier_id
                   AND (enforcement_party.source_document_id = {$table}.id
                     OR enforcement_party.source_document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_claim_breakdowns enforcement_breakdown
                 WHERE enforcement_breakdown.supplier_id = {$table}.supplier_id
                   AND (enforcement_breakdown.source_document_id = {$table}.id
                     OR enforcement_breakdown.source_document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_recipient_instructions recipient_instruction
                 WHERE recipient_instruction.supplier_id = {$table}.supplier_id
                   AND (recipient_instruction.source_document_id = {$table}.id
                     OR recipient_instruction.source_document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_enforcement_xmlzam_requests xmlzam_request
                  JOIN documents xmlzam_source
                    ON xmlzam_source.supplier_id = xmlzam_request.supplier_id
                   AND xmlzam_source.id = xmlzam_request.source_document_id
                 WHERE xmlzam_request.supplier_id = {$table}.supplier_id
                   AND (xmlzam_request.source_document_id = {$table}.id
                     OR xmlzam_request.source_document_id = {$table}.parent_document_id
                     OR xmlzam_source.parent_document_id = {$table}.id)
            )";
        }
        $table = $alias !== '' ? $alias : 'documents';
        if (!$viewer->canViewHiddenSubmissionInboxDocuments) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_inbox_messages hidden_inbox
                 WHERE hidden_inbox.supplier_id = {$table}.supplier_id
                   AND hidden_inbox.hidden_at IS NOT NULL
                   AND hidden_inbox.document_id IS NOT NULL
                   AND (hidden_inbox.document_id = {$table}.id
                     OR hidden_inbox.document_id = {$table}.parent_document_id)
            )";
        }
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
                  FROM submission_inbox_messages classified_payroll_inbox
                 WHERE classified_payroll_inbox.supplier_id = {$table}.supplier_id
                   AND classified_payroll_inbox.classification IN ('cssz_protocol','health_insurer_response')
                   AND classified_payroll_inbox.document_id IS NOT NULL
                   AND (classified_payroll_inbox.document_id = {$table}.id
                     OR classified_payroll_inbox.document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_statutory_obligation_evidence submission_evidence
                 WHERE submission_evidence.supplier_id = {$table}.supplier_id
                   AND (submission_evidence.document_id = {$table}.id
                     OR submission_evidence.document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_eldp_manual_completions eldp_evidence
                 WHERE eldp_evidence.confirmation_document_supplier_id = {$table}.supplier_id
                   AND (eldp_evidence.confirmation_document_id = {$table}.id
                     OR eldp_evidence.confirmation_document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_production_qualification_documents qualification_evidence
                 WHERE qualification_evidence.supplier_id = {$table}.supplier_id
                   AND (qualification_evidence.document_id = {$table}.id
                     OR qualification_evidence.document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_outbox payroll_receipt
                 WHERE payroll_receipt.supplier_id = {$table}.supplier_id
                   AND payroll_receipt.artifact_kind = 'payroll_submission'
                   AND (payroll_receipt.receipt_document_id = {$table}.id
                     OR payroll_receipt.receipt_document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_outbox payroll_receipt
                  JOIN submission_inbox_messages receipt_inbox
                    ON receipt_inbox.supplier_id = payroll_receipt.supplier_id
                   AND receipt_inbox.id = payroll_receipt.receipt_inbox_message_id
                 WHERE payroll_receipt.supplier_id = {$table}.supplier_id
                   AND payroll_receipt.artifact_kind = 'payroll_submission'
                   AND (receipt_inbox.document_id = {$table}.id
                     OR receipt_inbox.document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_inbox_messages payroll_response
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = payroll_response.supplier_id
                   AND payroll_outbox.id = payroll_response.matched_outbox_id
                 WHERE payroll_response.supplier_id = {$table}.supplier_id
                   AND payroll_outbox.artifact_kind = 'payroll_submission'
                   AND (payroll_response.document_id = {$table}.id
                     OR payroll_response.document_id = {$table}.parent_document_id)
            )";
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM submission_defect_notices defect_notice
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = defect_notice.supplier_id
                   AND payroll_outbox.id = defect_notice.outbox_id
                  JOIN submission_inbox_messages defect_inbox
                    ON defect_inbox.supplier_id = defect_notice.supplier_id
                   AND defect_inbox.id = defect_notice.inbox_message_id
                 WHERE defect_notice.supplier_id = {$table}.supplier_id
                   AND payroll_outbox.artifact_kind = 'payroll_submission'
                   AND (defect_inbox.document_id = {$table}.id
                     OR defect_inbox.document_id = {$table}.parent_document_id)
            )";
        }
        if (!$viewer->canViewPayrollForeignPermitEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_person_foreign_permits foreign_permit
                 WHERE foreign_permit.supplier_id = {$table}.supplier_id
                   AND (foreign_permit.document_id = {$table}.id
                     OR foreign_permit.document_id = {$table}.parent_document_id)
            )";
        }
        if (!$viewer->canViewPayrollHealthEvidence) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_person_health_coverage_history health_evidence
                 WHERE health_evidence.supplier_id = {$table}.supplier_id
                   AND health_evidence.health_evidence_document_id IS NOT NULL
                   AND (health_evidence.health_evidence_document_id = {$table}.id
                     OR health_evidence.health_evidence_document_id = {$table}.parent_document_id)
            )";
        }
        if (!$viewer->canViewPayrollDocuments) {
            $sql .= " AND NOT EXISTS (
                SELECT 1
                  FROM payroll_document_dms_links payroll_document_link
                  JOIN documents linked_payroll_document
                    ON linked_payroll_document.supplier_id = payroll_document_link.supplier_id
                   AND linked_payroll_document.id = payroll_document_link.dms_document_id
                 WHERE payroll_document_link.supplier_id = {$table}.supplier_id
                   AND (payroll_document_link.dms_document_id = {$table}.id
                     OR payroll_document_link.dms_document_id = {$table}.parent_document_id
                     OR linked_payroll_document.parent_document_id = {$table}.id)
            )";
        }
        return [$sql, $params];
    }
}
