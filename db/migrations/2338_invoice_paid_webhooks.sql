CREATE TABLE IF NOT EXISTS invoice_paid_webhooks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    url VARCHAR(2048) NOT NULL,
    secret_enc TEXT NOT NULL,
    include_advance TINYINT(1) NOT NULL DEFAULT 0,
    KEY ix_paid_hook_scope (supplier_id, template_id),
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES recurring_invoice_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_paid_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    hook_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    body_json LONGTEXT NOT NULL CHECK (JSON_VALID(body_json)),
    status ENUM('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lease_token CHAR(32) NULL,
    http_status SMALLINT UNSIGNED NULL,
    UNIQUE KEY uq_paid_delivery (hook_id, event_id),
    KEY ix_paid_delivery_due (status, available_at, id),
    KEY ix_paid_delivery_history (hook_id, id),
    FOREIGN KEY (hook_id) REFERENCES invoice_paid_webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Společný bod pro ruční úhradu, avízo, přepočet plateb i vystavení ze zálohy.
-- Fronta se potvrdí či odvolá stejnou transakcí jako samotný přechod faktury.
DELIMITER //
CREATE OR REPLACE TRIGGER trg_invoice_paid_webhook AFTER UPDATE ON invoices FOR EACH ROW
BEGIN
    DECLARE event_uuid CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE covered_by_advance BOOLEAN;
    DECLARE origin_template_id BIGINT UNSIGNED;
    IF OLD.status <> 'paid' AND NEW.status = 'paid' AND NEW.invoice_type IN ('invoice','proforma') THEN
        SET covered_by_advance = NEW.invoice_type = 'invoice' AND NEW.amount_to_pay <= 0
            AND (NEW.parent_invoice_id IS NOT NULL OR NEW.advance_paid_amount > 0);
        IF NEW.amount_to_pay > 0 OR covered_by_advance THEN
            SET origin_template_id = NEW.recurring_template_id;
            -- Vyúčtování zálohy dědí odběr původní šablony. Samotnou vazbu
            -- invoices.recurring_template_id neměníme, generátor ji používá pro periodicitu.
            IF origin_template_id IS NULL AND NEW.invoice_type = 'invoice' AND NEW.parent_invoice_id IS NOT NULL THEN
                SET origin_template_id = (SELECT p.recurring_template_id FROM invoices p
                    WHERE p.id = NEW.parent_invoice_id AND p.supplier_id = NEW.supplier_id AND p.invoice_type = 'proforma');
            END IF;
            SET event_uuid = UUID();
            INSERT INTO invoice_paid_deliveries (hook_id, event_id, body_json)
            SELECT h.id, event_uuid, JSON_OBJECT(
                'type', 'invoice.paid', 'version', 1, 'event_id', event_uuid,
                'occurred_at', DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ'),
                'supplier_id', NEW.supplier_id, 'invoice_id', NEW.id, 'invoice_type', NEW.invoice_type,
                'varsymbol', NEW.varsymbol, 'paid_at', NEW.paid_at, 'recurring_template_id', origin_template_id,
                'order_id', (SELECT l.order_id FROM sales_order_invoice_links l
                             WHERE l.supplier_id = NEW.supplier_id AND l.invoice_id = NEW.id),
                'reason', IF(covered_by_advance, 'covered_by_advance', 'payment'))
            FROM invoice_paid_webhooks h
            WHERE h.supplier_id = NEW.supplier_id AND (h.template_id IS NULL OR h.template_id = origin_template_id)
              AND (h.include_advance = 1 OR NOT covered_by_advance);
        END IF;
    END IF;
END//
DELIMITER ;
