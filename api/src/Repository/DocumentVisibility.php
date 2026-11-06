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
        $table = $alias !== '' ? $alias : 'documents';
        if (!$viewer->canViewPayrollEnforcementEvidence) {
            $sql .= self::hiddenFamily($table, 'payroll_enforcement_case_documents payroll_evidence',
                'payroll_evidence.supplier_id', 'payroll_evidence.dms_document_id');
            $sql .= self::hiddenFamily($table, 'payroll_enforcement_case_parties enforcement_party',
                'enforcement_party.supplier_id', 'enforcement_party.source_document_id');
            $sql .= self::hiddenFamily($table, 'payroll_enforcement_claim_breakdowns enforcement_breakdown',
                'enforcement_breakdown.supplier_id', 'enforcement_breakdown.source_document_id');
            $sql .= self::hiddenFamily($table, 'payroll_enforcement_recipient_instructions recipient_instruction',
                'recipient_instruction.supplier_id', 'recipient_instruction.source_document_id');
            $sql .= self::hiddenFamily($table, 'payroll_enforcement_xmlzam_requests xmlzam_request',
                'xmlzam_request.supplier_id', 'xmlzam_request.source_document_id');
        }
        if (!$viewer->canViewHiddenSubmissionInboxDocuments) {
            $sql .= self::hiddenFamily($table, 'submission_inbox_messages hidden_inbox',
                'hidden_inbox.supplier_id', 'hidden_inbox.document_id',
                'hidden_inbox.hidden_at IS NOT NULL');
        }
        if (!$viewer->canViewPayrollInsolvencyEvidence) {
            $sql .= self::hiddenFamily($table, 'payroll_insolvency_payment_instructions insolvency_evidence',
                'insolvency_evidence.supplier_id', 'insolvency_evidence.decision_document_id');
        }
        if (!$viewer->canViewPayrollSubmissionEvidence) {
            $sql .= self::hiddenFamily($table, 'submission_inbox_messages classified_payroll_inbox',
                'classified_payroll_inbox.supplier_id', 'classified_payroll_inbox.document_id',
                "classified_payroll_inbox.classification IN ('cssz_protocol','health_insurer_response')");
            $sql .= self::hiddenFamily($table, 'payroll_statutory_obligation_evidence submission_evidence',
                'submission_evidence.supplier_id', 'submission_evidence.document_id');
            $sql .= self::hiddenFamily($table, 'payroll_eldp_manual_completions eldp_evidence',
                'eldp_evidence.confirmation_document_supplier_id', 'eldp_evidence.confirmation_document_id');
            $sql .= self::hiddenFamily($table, 'payroll_production_qualification_documents qualification_evidence',
                'qualification_evidence.supplier_id', 'qualification_evidence.document_id');
            $sql .= self::hiddenFamily($table, 'submission_outbox payroll_receipt',
                'payroll_receipt.supplier_id', 'payroll_receipt.receipt_document_id',
                "payroll_receipt.artifact_kind = 'payroll_submission'");
            $sql .= self::hiddenFamily($table, 'submission_outbox payroll_receipt
                  JOIN submission_inbox_messages receipt_inbox
                    ON receipt_inbox.supplier_id = payroll_receipt.supplier_id
                   AND receipt_inbox.id = payroll_receipt.receipt_inbox_message_id',
                'payroll_receipt.supplier_id', 'receipt_inbox.document_id',
                "payroll_receipt.artifact_kind = 'payroll_submission'");
            $sql .= self::hiddenFamily($table, 'submission_inbox_messages payroll_response
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = payroll_response.supplier_id
                   AND payroll_outbox.id = payroll_response.matched_outbox_id',
                'payroll_response.supplier_id', 'payroll_response.document_id',
                "payroll_outbox.artifact_kind = 'payroll_submission'");
            $sql .= self::hiddenFamily($table, 'submission_defect_notices defect_notice
                  JOIN submission_outbox payroll_outbox
                    ON payroll_outbox.supplier_id = defect_notice.supplier_id
                   AND payroll_outbox.id = defect_notice.outbox_id
                  JOIN submission_inbox_messages defect_inbox
                    ON defect_inbox.supplier_id = defect_notice.supplier_id
                   AND defect_inbox.id = defect_notice.inbox_message_id',
                'defect_notice.supplier_id', 'defect_inbox.document_id',
                "payroll_outbox.artifact_kind = 'payroll_submission'");
        }
        if (!$viewer->canViewPayrollForeignPermitEvidence) {
            $sql .= self::hiddenFamily($table, 'payroll_person_foreign_permits foreign_permit',
                'foreign_permit.supplier_id', 'foreign_permit.document_id');
        }
        if (!$viewer->canViewPayrollHealthEvidence) {
            $sql .= self::hiddenFamily($table, 'payroll_person_health_coverage_history health_evidence',
                'health_evidence.supplier_id', 'health_evidence.health_evidence_document_id');
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

    /**
     * Skryje celou rodinu dokladu, na který ukazuje důkaz.
     *
     * Datová zpráva se ukládá jako ZFO kontejner a přílohy jako jeho potomci
     * (`parent_document_id`). Kontejner nese všechny přílohy uvnitř souboru,
     * takže skrýt jen navázanou přílohu nestačí: přes kontejner (detail
     * i stažení) by se k ní dostal kdokoli. Skrývá se proto celá zpráva,
     * tedy navázaný doklad, jeho potomci, jeho kontejner i ostatní přílohy
     * téže zprávy.
     *
     * Doklad patří do rodiny, když jeho `id` nebo `parent_document_id` leží
     * v množině {navázaný doklad, rodič navázaného dokladu}. Obě množiny jsou
     * nekorelované a bez UNION, takže je MariaDB materializuje jednou za dotaz.
     * Korelovaná podmínka s OR by se spouštěla znovu pro každý řádek dokumentů.
     */
    private static function hiddenFamily(string $table, string $from, string $supplier, string $linkedId, string $where = ''): string
    {
        $where = $where !== '' ? " AND {$where}" : '';
        $linked = "SELECT {$supplier}, {$linkedId} FROM {$from}
                    WHERE {$linkedId} IS NOT NULL AND {$supplier} IS NOT NULL{$where}";
        $parents = "SELECT {$supplier}, family_doc.parent_document_id FROM {$from}
                      JOIN documents family_doc
                        ON family_doc.supplier_id = {$supplier}
                       AND family_doc.id = {$linkedId}
                     WHERE family_doc.parent_document_id IS NOT NULL{$where}";

        return " AND ({$table}.supplier_id, {$table}.id) NOT IN ({$linked})
                 AND ({$table}.supplier_id, {$table}.id) NOT IN ({$parents})
                 AND ({$table}.parent_document_id IS NULL OR (
                     ({$table}.supplier_id, {$table}.parent_document_id) NOT IN ({$linked})
                     AND ({$table}.supplier_id, {$table}.parent_document_id) NOT IN ({$parents})))";
    }
}
