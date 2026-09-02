-- MyÚčto.cz - GoPay XML clearingy, pohyby a účetní nastavení.
--
-- XML je jediný importní zdroj modulu. Každý pohyb má stabilní externí ID,
-- clearing i pohyby jsou tenantově unikátní a účetní zápisy používají vlastní
-- source_type `gopay` s source_id = gopay_movements.id.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gopay_settings (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  currency                   CHAR(3) NOT NULL DEFAULT 'CZK',
  gopay_account_id           BIGINT UNSIGNED NOT NULL,
  receivable_account_id      BIGINT UNSIGNED NOT NULL,
  fee_account_id             BIGINT UNSIGNED NOT NULL,
  clearing_account_id        BIGINT UNSIGNED NOT NULL,
  destination_bank_account_id BIGINT UNSIGNED NOT NULL,
  -- Bez konkrétního čísla účtu: výchozí hodnota se propíše každé nové
  -- instalaci, takže by cizí účet vypadal jako vlastní a párování výplat
  -- by hádalo proti protistraně, která s firmou nemá nic společného.
  payout_account_number      VARCHAR(40) NOT NULL DEFAULT '',
  payout_bank_code           CHAR(4) NOT NULL DEFAULT '0100',
  payout_date_tolerance_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  updated_by                 BIGINT UNSIGNED NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_gopay_settings_supplier_currency (supplier_id, currency),
  CONSTRAINT fk_gopay_settings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_gopay_settings_gopay_account FOREIGN KEY (gopay_account_id) REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_gopay_settings_receivable_account FOREIGN KEY (receivable_account_id) REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_gopay_settings_fee_account FOREIGN KEY (fee_account_id) REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_gopay_settings_clearing_account FOREIGN KEY (clearing_account_id) REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_gopay_settings_destination_account FOREIGN KEY (destination_bank_account_id) REFERENCES chart_of_accounts(id),
  CONSTRAINT fk_gopay_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_gopay_settings_tolerance CHECK (payout_date_tolerance_days <= 14)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gopay_clearings (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  clearing_id                VARCHAR(40) NOT NULL,
  account_name               VARCHAR(190) NOT NULL,
  currency                   CHAR(3) NOT NULL,
  variable_symbol            VARCHAR(20) NOT NULL,
  cleared_from               DATE NOT NULL,
  cleared_to                 DATE NOT NULL,
  performed_on               DATE NOT NULL,
  amount_gross               DECIMAL(14,2) NOT NULL,
  amount_credit_note         DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_fee                 DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_fee_external        DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_storno              DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_storno_fee          DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_transfer            DECIMAL(14,2) NOT NULL,
  amount_sent                DECIMAL(14,2) NOT NULL,
  file_name                  VARCHAR(255) NOT NULL,
  file_hash                  CHAR(64) NOT NULL,
  file_content               MEDIUMBLOB NOT NULL,
  status                     ENUM('imported','processing','processed','needs_review') NOT NULL DEFAULT 'imported',
  movement_count             INT UNSIGNED NOT NULL DEFAULT 0,
  posted_count               INT UNSIGNED NOT NULL DEFAULT 0,
  issue_count                INT UNSIGNED NOT NULL DEFAULT 0,
  bank_transaction_id        BIGINT UNSIGNED NULL,
  bank_journal_entry_id      BIGINT UNSIGNED NULL,
  payout_issue_code          VARCHAR(80) NULL,
  payout_issue_message       VARCHAR(500) NULL,
  imported_by                BIGINT UNSIGNED NULL,
  imported_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at               TIMESTAMP NULL,

  UNIQUE KEY uq_gopay_clearing_supplier_id (supplier_id, clearing_id),
  UNIQUE KEY uq_gopay_clearing_supplier_hash (supplier_id, file_hash),
  KEY idx_gopay_clearing_supplier_date (supplier_id, performed_on),
  KEY idx_gopay_clearing_status (supplier_id, status, performed_on),
  KEY idx_gopay_clearing_bank_tx (bank_transaction_id),
  CONSTRAINT fk_gopay_clearing_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_gopay_clearing_bank_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_gopay_clearing_bank_entry FOREIGN KEY (bank_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_gopay_clearing_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_gopay_clearing_dates CHECK (cleared_from <= cleared_to),
  CONSTRAINT chk_gopay_clearing_amounts CHECK (
    amount_gross >= 0 AND amount_credit_note >= 0 AND amount_fee >= 0
    AND amount_fee_external >= 0 AND amount_storno >= 0 AND amount_storno_fee >= 0
    AND amount_transfer >= 0 AND amount_sent >= 0
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gopay_movements (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  clearing_id                BIGINT UNSIGNED NOT NULL,
  external_id                VARCHAR(80) NOT NULL,
  movement_type              ENUM('credit','storno','storno_fee','clearing_fee','fee_credit','payout') NOT NULL,
  performed_on               DATE NOT NULL,
  amount                     DECIMAL(14,2) NOT NULL,
  order_id                   VARCHAR(80) NULL,
  payment_session_id         VARCHAR(40) NULL,
  account_movement_id        VARCHAR(40) NULL,
  payment_channel            VARCHAR(40) NULL,
  counterparty_name          VARCHAR(190) NULL,
  invoice_id                 BIGINT UNSIGNED NULL,
  invoice_payment_id         BIGINT UNSIGNED NULL,
  credit_note_id             BIGINT UNSIGNED NULL,
  journal_entry_id           BIGINT UNSIGNED NULL,
  status                     ENUM('pending','posted','unmatched','error') NOT NULL DEFAULT 'pending',
  issue_code                 VARCHAR(80) NULL,
  issue_message              VARCHAR(500) NULL,
  processed_at               TIMESTAMP NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_gopay_movement_external (supplier_id, external_id),
  KEY idx_gopay_movement_clearing (clearing_id, id),
  KEY idx_gopay_movement_status (supplier_id, status, performed_on),
  KEY idx_gopay_movement_payment_session (supplier_id, payment_session_id),
  KEY idx_gopay_movement_order (supplier_id, order_id),
  KEY idx_gopay_movement_invoice (invoice_id),
  KEY idx_gopay_movement_credit_note (credit_note_id),
  KEY idx_gopay_movement_journal (journal_entry_id),
  CONSTRAINT fk_gopay_movement_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_gopay_movement_clearing FOREIGN KEY (clearing_id) REFERENCES gopay_clearings(id) ON DELETE CASCADE,
  CONSTRAINT fk_gopay_movement_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_gopay_movement_invoice_payment FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments(id) ON DELETE SET NULL,
  CONSTRAINT fk_gopay_movement_credit_note FOREIGN KEY (credit_note_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_gopay_movement_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
  CONSTRAINT chk_gopay_movement_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay'
  ) NOT NULL DEFAULT 'manual';
