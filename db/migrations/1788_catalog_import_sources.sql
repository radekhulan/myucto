CREATE TABLE IF NOT EXISTS catalog_import_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    config_json LONGTEXT NOT NULL CHECK (JSON_VALID(config_json)),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_import_profile_name (supplier_id, name),
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_import_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    storage_key CHAR(64) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    format ENUM('csv','xlsx') NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_import_storage (storage_key),
    KEY ix_catalog_import_source_tenant (supplier_id, id),
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_entity_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id INT UNSIGNED NOT NULL,
    source_key VARCHAR(100) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    external_id VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    internal_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_external_entity_identity (supplier_id, source_key, entity_type, external_id),
    KEY ix_external_entity_internal (supplier_id, entity_type, internal_id),
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
