-- Platební karty firmy a koncovka karty u bankovního pohybu.
--
-- Celé číslo karty se NIKDY neukládá. Tabulka nese jen poslední čtyři číslice
-- a CHECK to hlídá i na úrovni databáze, ať žádná cesta (import, API, ruční SQL)
-- nemůže do sloupce propašovat delší číslo.
--
-- employee_id / user_id záměrně NEMAJÍ cizí klíč: mzdová tabulka i uživatelé mají
-- vlastní mazací pojistky, které vyjmenovávají navázané tabulky. Vazbu na firmu
-- ověřuje aplikace (PaymentCardRepository) a jméno držitele drží holder_name.

CREATE TABLE IF NOT EXISTS payment_cards (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     INT UNSIGNED NOT NULL,
  label           VARCHAR(120) NOT NULL,
  holder_name     VARCHAR(191) NULL,
  last4           CHAR(4) NOT NULL,
  card_type       ENUM('debit','credit','prepaid','fuel','other') NOT NULL DEFAULT 'debit',
  card_network    ENUM('visa','mastercard','maestro','amex','other') NULL,
  currency_id     INT UNSIGNED NULL COMMENT 'bankovní (měnový) účet firmy, ze kterého se platby strhávají',
  employee_id     BIGINT UNSIGNED NULL COMMENT 'payroll_employees.id — držitel zaměstnanec',
  user_id         BIGINT UNSIGNED NULL COMMENT 'users.id — držitel uživatel aplikace',
  valid_from      DATE NULL,
  valid_to        DATE NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  archived_at     DATETIME NULL,
  note            VARCHAR(500) NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pc_supplier_last4 (supplier_id, last4, valid_from),
  KEY idx_pc_employee (employee_id),
  KEY idx_pc_user (user_id),
  KEY idx_pc_currency (currency_id),
  CONSTRAINT fk_pc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE SET NULL,
  CONSTRAINT chk_pc_last4 CHECK (last4 REGEXP '^[0-9]{4}$'),
  CONSTRAINT chk_pc_validity CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Koncovka karty z maskovaného čísla ve výpisu (GPC 078/079, PDF, API, avízo).
ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS card_last4 CHAR(4) NULL AFTER counterparty_name;

ALTER TABLE bank_transactions
  ADD INDEX IF NOT EXISTS idx_bt_card_last4 (card_last4, posted_at);

ALTER TABLE bank_transactions DROP CONSTRAINT IF EXISTS chk_bt_card_last4;
ALTER TABLE bank_transactions
  ADD CONSTRAINT chk_bt_card_last4 CHECK (card_last4 IS NULL OR card_last4 REGEXP '^[0-9]{4}$');
