-- Hromadné úlohy účtování na pozadí:
--   journal_rebook … přeúčtování účtu X → Y na zaúčtovaných zápisech deníku podle filtru,
--   bank_bulk      … zaúčtování nebo spárování nespárovaných bankovních pohybů podle filtru.
-- Každá úloha (nanečisto, ostře, vrácení) si ukládá výsledek po dokladech do
-- accounting_bulk_items. Ostrý běh zpracuje jen to, co ukázal běh nanečisto, a vrácení
-- jen to, co ostrý běh opravdu změnil.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach', 'money_s3_import', 'pohoda_import',
        'premier_import', 'stereo_nx_import', 'money_s3_batch', 'abra_flexi_import', 'myucto_import',
        'journal_rebook', 'bank_bulk'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS accounting_bulk_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    entry_id BIGINT UNSIGNED NULL COMMENT 'Aktivní zápis dokladu v okamžiku běhu (NULL = nezaúčtovaný pohyb)',
    source_type ENUM('invoice','purchase_invoice','bank','manual','cash') NOT NULL COMMENT 'manual: source_id = id zápisu',
    source_id BIGINT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    document_no VARCHAR(50) NULL,
    description VARCHAR(255) NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Součet přeúčtovaných řádků',
    status ENUM('would_change','unchanged','skipped','excluded','applied','failed') NOT NULL,
    strategy VARCHAR(16) NULL COMMENT 'replace = oprava zápisu, reverse = storno a nový zápis',
    target_date DATE NULL,
    date_shifted TINYINT(1) NOT NULL DEFAULT 0,
    reason_code VARCHAR(80) NULL,
    message VARCHAR(500) NULL,
    fingerprint CHAR(64) NULL COMMENT 'Otisk zaúčtování dokladu před změnou (nanečisto) / po změně (ostře)',
    before_json JSON NULL,
    after_json JSON NULL,
    result_entry_id BIGINT UNSIGNED NULL,
    items_synced SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounting_bulk_job_source (job_id, source_type, source_id),
    KEY idx_accounting_bulk_job_status (job_id, status, entry_date, id),
    KEY idx_accounting_bulk_supplier_job (supplier_id, job_id),
    CONSTRAINT fk_accounting_bulk_job_supplier FOREIGN KEY (supplier_id, job_id)
        REFERENCES import_jobs (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
