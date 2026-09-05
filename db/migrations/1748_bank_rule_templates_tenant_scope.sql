SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_rule_template_defaults (
    template_key        VARCHAR(64) NOT NULL PRIMARY KEY,
    name_cs             VARCHAR(120) NOT NULL,
    name_en             VARCHAR(120) NOT NULL,
    direction           ENUM('incoming','outgoing') NOT NULL,
    operation_type      VARCHAR(40) NOT NULL,
    counterparty_bank   VARCHAR(10) NULL,
    counterparty_prefix VARCHAR(6) NULL,
    vs_placeholder      VARCHAR(40) NULL,
    message_contains    VARCHAR(120) NULL,
    rule_key            VARCHAR(64) NOT NULL,
    default_priority    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO bank_rule_template_defaults
    (template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
     counterparty_prefix, vs_placeholder, message_contains, rule_key,
     default_priority, sort_order, is_active)
SELECT template_key, name_cs, name_en, direction, operation_type, counterparty_bank,
       counterparty_prefix, vs_placeholder, message_contains, rule_key,
       default_priority, sort_order, is_active
FROM bank_rule_templates;

ALTER TABLE bank_rule_templates
    MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN IF NOT EXISTS supplier_id INT UNSIGNED NULL AFTER id;

ALTER TABLE bank_rule_templates DROP INDEX IF EXISTS uq_brt_key;

CREATE UNIQUE INDEX IF NOT EXISTS uq_brt_supplier_key
    ON bank_rule_templates (supplier_id, template_key);

INSERT IGNORE INTO bank_rule_templates
    (supplier_id, template_key, name_cs, name_en, direction, operation_type,
     counterparty_bank, counterparty_prefix, vs_placeholder, message_contains,
     rule_key, default_priority, sort_order, is_active)
SELECT supplier.id, defaults.template_key, defaults.name_cs, defaults.name_en,
       defaults.direction, defaults.operation_type, defaults.counterparty_bank,
       defaults.counterparty_prefix, defaults.vs_placeholder,
       defaults.message_contains, defaults.rule_key, defaults.default_priority,
       defaults.sort_order, defaults.is_active
FROM supplier
CROSS JOIN bank_rule_template_defaults defaults;

DELETE FROM bank_rule_templates WHERE supplier_id IS NULL;

ALTER TABLE bank_rule_templates
    MODIFY COLUMN supplier_id INT UNSIGNED NOT NULL,
    ADD KEY IF NOT EXISTS idx_brt_supplier (supplier_id),
    ADD CONSTRAINT fk_brt_supplier
        FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
