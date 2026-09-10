-- Převod agendy z Money S3 (průvodce „Přechod z Money S3").
--
-- money_s3_imports: jeden řádek na běh průvodce (zkouška nanečisto i ostrý import)
-- s protokolem. Protokol je důkaz, že převod sedí: rekonciliace obratové předvahy
-- proti deníku Money, doklady proti deníku a uzávěrka historických let.
--
-- money_s3_import_map: co už z které agendy v MyÚčtu vzniklo. Na tom stojí
-- idempotence — opakovaný import téže (nebo novější) zálohy založí jen to, co
-- ještě chybí, a nic nezdvojí. Klíč je tenantový: stejná agenda nahraná do jiné
-- firmy má vlastní mapu.
--
-- Idempotence migrace: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS money_s3_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    mode ENUM('dry_run','import') NOT NULL,
    status ENUM('running','completed','completed_with_warnings','failed','cancelled') NOT NULL DEFAULT 'running',
    agenda_ico VARCHAR(20) NULL,
    agenda_name VARCHAR(190) NULL,
    money_version VARCHAR(20) NULL,
    backup_sha256 CHAR(64) NULL,
    protocol LONGTEXT NULL CHECK (protocol IS NULL OR JSON_VALID(protocol)),
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY ix_money_s3_imports_supplier (supplier_id, id),
    CONSTRAINT fk_money_s3_imports_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS money_s3_import_map (
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
    money_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, kind, money_key),
    KEY ix_money_s3_import_map_target (supplier_id, kind, target_id),
    CONSTRAINT fk_money_s3_import_map_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
