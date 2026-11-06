SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS joint_allocation_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(190) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_joint_key_code (supplier_id, code),
    UNIQUE KEY uq_joint_key_tenant (supplier_id, id),
    CONSTRAINT fk_joint_key_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS joint_allocation_key_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    key_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NOT NULL,
    basis VARCHAR(16) NOT NULL,
    explanation TEXT NOT NULL,
    rounding_policy VARCHAR(50) NOT NULL,
    cells_json LONGTEXT NOT NULL CHECK (JSON_VALID(cells_json)),
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_joint_version_number (key_id, version_no),
    UNIQUE KEY uq_joint_version_tenant (supplier_id, id),
    KEY idx_joint_version_interval (supplier_id, key_id, valid_from, valid_to),
    CONSTRAINT chk_joint_version_interval CHECK (valid_from < valid_to),
    CONSTRAINT chk_joint_version_basis CHECK (basis IN ('hours', 'area', 'revenue', 'fixed')),
    CONSTRAINT fk_joint_version_key FOREIGN KEY (supplier_id, key_id) REFERENCES joint_allocation_keys(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS joint_allocation_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    key_version_id BIGINT UNSIGNED NOT NULL,
    target_date DATE NOT NULL,
    reason TEXT NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
    journal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_joint_batch_idempotency (supplier_id, idempotency_key),
    UNIQUE KEY uq_joint_batch_tenant (supplier_id, id),
    UNIQUE KEY uq_joint_batch_entry (journal_entry_id),
    CONSTRAINT fk_joint_batch_version FOREIGN KEY (supplier_id, key_version_id) REFERENCES joint_allocation_key_versions(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS joint_allocation_source_claims (
    supplier_id INT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (supplier_id, line_id),
    KEY idx_joint_claim_batch (supplier_id, batch_id),
    CONSTRAINT fk_joint_claim_batch FOREIGN KEY (supplier_id, batch_id) REFERENCES joint_allocation_batches(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE joint_allocation_source_claims
    ADD CONSTRAINT fk_joint_claim_source_line
        FOREIGN KEY IF NOT EXISTS (line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS joint_allocation_cancellations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    target_date DATE NOT NULL,
    reason TEXT NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reversal_entry_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_joint_cancel_batch (batch_id),
    UNIQUE KEY uq_joint_cancel_idempotency (supplier_id, idempotency_key),
    UNIQUE KEY uq_joint_cancel_entry (reversal_entry_id),
    CONSTRAINT fk_joint_cancel_batch FOREIGN KEY (supplier_id, batch_id) REFERENCES joint_allocation_batches(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE joint_allocation_cancellations MODIFY COLUMN reversal_entry_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS joint_allocation_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(16) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(24) NOT NULL,
    payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
    actor_id BIGINT UNSIGNED NULL,
    actor_source VARCHAR(24) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_joint_audit_entity (supplier_id, entity_type, entity_id, id),
    CONSTRAINT fk_joint_audit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
