CREATE TABLE IF NOT EXISTS catalog_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(50) NOT NULL,
    input_version INT UNSIGNED NOT NULL DEFAULT 1,
    input_json LONGTEXT NOT NULL CHECK (JSON_VALID(input_json)),
    status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
    checkpoint BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    report_json LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(report_json)),
    lease_token CHAR(64) NULL,
    lease_until DATETIME(6) NULL,
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(100) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) NULL,
    KEY ix_catalog_jobs_claim (status, lease_until, id),
    KEY ix_catalog_jobs_tenant_kind (supplier_id, kind, status),
    CONSTRAINT fk_catalog_jobs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_job_lanes (
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(50) NOT NULL,
    PRIMARY KEY (supplier_id, kind),
    CONSTRAINT fk_catalog_job_lanes_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
