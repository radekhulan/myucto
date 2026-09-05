CREATE TABLE IF NOT EXISTS automation_recommendation_snapshots (
    supplier_id INT UNSIGNED NOT NULL,
    generated_at DATETIME NULL,
    last_attempt_at DATETIME NULL,
    requested_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    completed_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (supplier_id),
    CONSTRAINT fk_automation_recommendation_snapshot_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_recommendation_items (
    supplier_id INT UNSIGNED NOT NULL,
    recommendation_id VARCHAR(120) NOT NULL,
    recommendation_type VARCHAR(32) NOT NULL,
    document_date DATE NOT NULL,
    payload JSON NOT NULL,
    PRIMARY KEY (supplier_id, recommendation_id),
    KEY idx_automation_recommendations_date (supplier_id, document_date),
    KEY idx_automation_recommendations_type_date (supplier_id, recommendation_type, document_date),
    CONSTRAINT fk_automation_recommendation_item_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_recommendation_coverage (
    supplier_id INT UNSIGNED NOT NULL,
    document_date DATE NOT NULL,
    sales INT UNSIGNED NOT NULL DEFAULT 0,
    purchases INT UNSIGNED NOT NULL DEFAULT 0,
    bank INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (supplier_id, document_date),
    CONSTRAINT fk_automation_recommendation_coverage_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
