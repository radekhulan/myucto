-- Platby firemní kartou přes mezičlen (výchozí 378) s analytikou pro každou kartu.
--
-- Režim se zapíná per firma s datem účinnosti. Pohyby před datem účinnosti zůstávají
-- tak, jak byly zaúčtované (321/221, 548/221 …) — migrace NIC nepřeúčtovává a do deníku
-- ani do párování nesahá. Mění jen schéma.
--
-- payment_cards.analytic_suffix nese jen číslo analytiky ('101'); kód skládá aplikace
-- (syntetika z nastavení + tečka + suffix), stejně jako u bankovních účtů.
-- payment_cards.is_verified = 0 u karty, kterou systém založil sám z koncovky ve výpisu.
--
-- card_clearing_settings: nastavení účtování karet per firma. Účty jsou volitelné —
-- NULL znamená výchozí účet (548/335/563/663/548/648), takže firma bez nastavení
-- účtuje stejně jako bankovní automatika.

SET NAMES utf8mb4;

ALTER TABLE payment_cards
  ADD COLUMN IF NOT EXISTS analytic_suffix VARCHAR(6) NULL AFTER currency_id,
  ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

ALTER TABLE payment_cards
  ADD UNIQUE INDEX IF NOT EXISTS uq_pc_supplier_analytic (supplier_id, analytic_suffix);

ALTER TABLE payment_cards DROP CONSTRAINT IF EXISTS chk_pc_analytic_suffix;
ALTER TABLE payment_cards
  ADD CONSTRAINT chk_pc_analytic_suffix CHECK (analytic_suffix IS NULL OR analytic_suffix REGEXP '^[0-9]{1,6}$');

CREATE TABLE IF NOT EXISTS card_clearing_settings (
  supplier_id               INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled                   TINYINT(1) NOT NULL DEFAULT 0,
  effective_from            DATE NULL COMMENT 'od tohoto data se platby kartou účtují přes mezičlen',
  clearing_synthetic        CHAR(3) NOT NULL DEFAULT '378',
  writeoff_account_id       BIGINT UNSIGNED NULL COMMENT 'uzavření platby bez dokladu (NULL = 548)',
  holder_account_id         BIGINT UNSIGNED NULL COMMENT 'pohledávka za držitelem karty (NULL = 335)',
  fx_loss_account_id        BIGINT UNSIGNED NULL COMMENT 'kurzová ztráta (NULL = kontace fx.loss, 563)',
  fx_gain_account_id        BIGINT UNSIGNED NULL COMMENT 'kurzový zisk (NULL = kontace fx.gain, 663)',
  rounding_loss_account_id  BIGINT UNSIGNED NULL COMMENT 'haléřový rozdíl — náklad (NULL = 548)',
  rounding_gain_account_id  BIGINT UNSIGNED NULL COMMENT 'haléřový rozdíl — výnos (NULL = 648)',
  auto_create_cards         TINYINT(1) NOT NULL DEFAULT 1,
  unmatched_alert_days      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  updated_by                BIGINT UNSIGNED NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ccs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccs_writeoff FOREIGN KEY (writeoff_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_holder FOREIGN KEY (holder_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_fx_loss FOREIGN KEY (fx_loss_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_fx_gain FOREIGN KEY (fx_gain_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_rounding_loss FOREIGN KEY (rounding_loss_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_rounding_gain FOREIGN KEY (rounding_gain_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_ccs_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE card_clearing_settings DROP CONSTRAINT IF EXISTS chk_ccs_synthetic;
ALTER TABLE card_clearing_settings
  ADD CONSTRAINT chk_ccs_synthetic CHECK (clearing_synthetic IN ('378', '261', '395'));

ALTER TABLE card_clearing_settings DROP CONSTRAINT IF EXISTS chk_ccs_alert_days;
ALTER TABLE card_clearing_settings
  ADD CONSTRAINT chk_ccs_alert_days CHECK (unmatched_alert_days BETWEEN 1 AND 365);

SET @@system_versioning_alter_history = 1;

-- Vypořádání platby kartou s dokladem (321/378.x) a uzavření platby bez dokladu
-- (548/378.x, 335/378.x) jsou samostatné zápisy se source_id = bank_transactions.id.
ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay',
    'card_settlement','card_writeoff'
  ) NOT NULL DEFAULT 'manual';
