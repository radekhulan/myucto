CREATE TABLE IF NOT EXISTS property_service_lifecycle_commands (
 supplier_id INT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 response_json LONGTEXT NOT NULL CHECK(JSON_VALID(response_json)),
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 PRIMARY KEY(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id,case_id) REFERENCES property_service_lifecycle_cases(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
