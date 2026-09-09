ALTER TABLE catalog_jobs
    ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_catalog_jobs_tenant (id, supplier_id);

CREATE TABLE IF NOT EXISTS catalog_job_items (
    job_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    ordinal INT UNSIGNED NOT NULL,
    stock_item_id INT UNSIGNED NULL,
    source_row INT UNSIGNED NULL,
    expected_version BIGINT UNSIGNED NULL,
    status ENUM('pending','ready','applied','unchanged','failed','conflict','skipped') NOT NULL DEFAULT 'pending',
    error_code VARCHAR(100) NULL,
    input_json LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(input_json)),
    before_json LONGTEXT NULL CHECK (before_json IS NULL OR JSON_VALID(before_json)),
    after_json LONGTEXT NULL CHECK (after_json IS NULL OR JSON_VALID(after_json)),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (job_id, ordinal),
    KEY ix_catalog_job_items_status (supplier_id, job_id, status, ordinal),
    CONSTRAINT fk_catalog_job_items_job FOREIGN KEY (job_id, supplier_id)
        REFERENCES catalog_jobs(id, supplier_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
