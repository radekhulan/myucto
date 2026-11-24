ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS public_published_at DATETIME(6) NULL;

CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    secret_enc TEXT NOT NULL,
    event_types JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    include_advance TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_webhook_endpoint_scope (supplier_id, id),
    KEY ix_webhook_template (template_id),
    CONSTRAINT fk_webhook_endpoint_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_webhook_endpoint_template FOREIGN KEY (template_id) REFERENCES recurring_invoice_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    event_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    body_json LONGTEXT NOT NULL CHECK (JSON_VALID(body_json)),
    status ENUM('pending','processing','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lease_token CHAR(32) NULL,
    http_status SMALLINT UNSIGNED NULL,
    last_error_code VARCHAR(32) NULL,
    UNIQUE KEY uq_webhook_delivery (supplier_id, endpoint_id, event_id),
    KEY ix_webhook_delivery_history (supplier_id, endpoint_id, id),
    KEY ix_webhook_delivery_test_rate (supplier_id, endpoint_id, event_type, created_at),
    KEY ix_webhook_delivery_due (status, available_at, id),
    KEY ix_webhook_delivery_retention (created_at, id),
    CONSTRAINT fk_webhook_delivery_endpoint FOREIGN KEY (supplier_id, endpoint_id) REFERENCES webhook_endpoints(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
