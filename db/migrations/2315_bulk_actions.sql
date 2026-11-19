-- Hromadné akce v seznamech (Účetní deník, Vydané a Přijaté faktury): jeden mechanismus
-- úloh s operacemi přeúčtování účtu, nastavení dimenzí, změny předkontace a smazání.
--   bulk_action   … úloha nad výběrem dokladů nebo zápisů (operace je v params.operation),
--   journal_entry … položka úlohy, kterou je zápis v deníku (source_id = id zápisu).
-- Úloha journal_rebook (stránka Přeúčtovat) zůstává a běží týmž mechanismem.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import',
        'premier_import', 'stereo_nx_import', 'money_s3_batch', 'abra_flexi_import', 'myucto_import',
        'journal_rebook', 'bank_bulk', 'bulk_action'
    ) NOT NULL;

ALTER TABLE accounting_bulk_items
    MODIFY COLUMN source_type ENUM('invoice','purchase_invoice','bank','manual','cash','journal_entry') NOT NULL
        COMMENT 'manual a journal_entry: source_id = id zápisu';
