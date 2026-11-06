-- MyÚčto.cz — Dimenze a vlastní bankovní účet v pravidle účtování bankovních pohybů
--
-- Pravidlo účtování (`bank_posting_rules`) nese hodnoty dimenzí, které se při schválení
-- návrhu nebo automatickém zaúčtování zapíší k pohybu (původ `bank_rule_default`,
-- odkaz `bank_posting_rule:<id>`). Na pohybu stojí pod dimenzemi hrazených dokladů
-- a nad výchozími dimenzemi vlastního bankovního účtu.
--
-- Nová podmínka pravidla `bank_account_id`: pravidlo platí jen pro pohyby na daném
-- vlastním bankovním účtu. Pravidlo omezené na účet má při souběhu přednost před
-- obecným se stejnou prioritou, teprve potom rozhoduje počet použití.
--
-- Idempotentní: CREATE TABLE / ADD COLUMN / INDEX / FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_posting_rule_dimensions (
    rule_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    dimension_type_id BIGINT UNSIGNED NOT NULL,
    dimension_value_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (rule_id, dimension_type_id),
    KEY idx_bprd_supplier (supplier_id),
    KEY idx_bprd_type_value (dimension_type_id, dimension_value_id),
    CONSTRAINT fk_bprd_rule FOREIGN KEY (rule_id) REFERENCES bank_posting_rules(id) ON DELETE CASCADE,
    CONSTRAINT fk_bprd_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_bprd_value FOREIGN KEY (dimension_type_id, dimension_value_id)
        REFERENCES dimension_values(type_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS bank_account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER direction;

ALTER TABLE bank_posting_rules
  ADD INDEX IF NOT EXISTS idx_bpr_bank_account (bank_account_id);

ALTER TABLE bank_posting_rules
  ADD CONSTRAINT fk_bpr_bank_account FOREIGN KEY IF NOT EXISTS (bank_account_id)
    REFERENCES supplier_bank_accounts(id) ON DELETE CASCADE;
