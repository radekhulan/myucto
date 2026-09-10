-- Připojení skenů k existujícím dokladům jako background job.
--
-- Dávka (stovky až tisíce souborů) je řádek `import_jobs` se zdrojem `scan_attach`.
-- Soubory dávky jsou v `scan_batch_items` (jeden řádek na obsah, sha256 unikátní
-- v rámci dávky → opakovaný běh po pádu nic nezdvojí), výsledek párování
-- v `scan_matches` (co se připojilo, co čeká na potvrzení, co uživatel odmítl).
--
-- `target_type` je řetězec, ne ENUM: nový typ cílového dokladu (pokladní doklad)
-- se zapne v kódu bez další migrace.

SET NAMES utf8mb4;

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'closing_package', 'file_import', 'document_backfill',
        'accounting_setup_analysis', 'accounting_history_reclassification',
        'automation_recommendations', 'scan_attach'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS scan_batch_items (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  job_id       BIGINT UNSIGNED NOT NULL COMMENT 'import_jobs.id dávky',
  file_name    VARCHAR(255) NOT NULL,
  sha256       CHAR(64) NOT NULL,
  size_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  document_id  BIGINT UNSIGNED NULL COMMENT 'dokument v sekci Dokumenty (nový nebo existující se stejným obsahem)',
  status       ENUM('stored','extracted','extract_failed','skipped') NOT NULL DEFAULT 'stored',
  ownership    ENUM('own','foreign','unknown') NULL COMMENT 'komu sken podle vytěžení patří; NULL = nevytěženo',
  outcome      ENUM('pending','attached','proposed','orphan','foreign','unknown','unreadable') NOT NULL DEFAULT 'pending',
  error        VARCHAR(500) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scanitem_job_sha (job_id, sha256),
  KEY idx_scanitem_supplier_job (supplier_id, job_id, outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_matches (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  job_id       BIGINT UNSIGNED NOT NULL,
  item_id      BIGINT UNSIGNED NOT NULL,
  target_type  VARCHAR(32) NOT NULL,
  target_id    BIGINT UNSIGNED NOT NULL,
  method       ENUM('barcode','doc_no','content') NOT NULL,
  level        ENUM('certain','likely','candidate') NOT NULL,
  score        SMALLINT NOT NULL DEFAULT 0,
  state        ENUM('attached','proposed','confirmed','rejected') NOT NULL,
  note         VARCHAR(500) NULL,
  decided_by   INT UNSIGNED NULL,
  decided_at   DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scanmatch_pair (job_id, item_id, target_type, target_id),
  KEY idx_scanmatch_supplier_job (supplier_id, job_id, state),
  KEY idx_scanmatch_target (supplier_id, target_type, target_id),
  CONSTRAINT fk_scanmatch_item FOREIGN KEY (item_id) REFERENCES scan_batch_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
