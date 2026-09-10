-- Převod agendy z Money S3 jako background job (průvodce „Přechod z Money S3").
--
-- Agenda s několika roky má desítky tisíc řádků deníku a k tomu doklady, banku,
-- pokladnu a uzávěrku historických let — synchronní request na to nestačí.
--
-- Rozšiřuje ENUM import_jobs.source o nový typ. MODIFY je idempotentní.
-- Re-list VŠECH stávajících členů (ověřeno proti 1805, scan_attach).

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import'
    ) NOT NULL;
