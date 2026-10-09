-- MyÚčto.cz — pravidla bankovních pohybů v daňové evidenci (issue #140).
--
-- Podvojné účetnictví má pravidla zaúčtování (bank_posting_rules, 1020), daňová
-- evidence účty nemá. Pohyb bez dokladu (bankovní poplatek, převod mezi vlastními
-- účty, vklad z osobních peněz) se dosud musel v peněžním deníku zařadit ručně.
--
-- Pravidlo = podmínky (směr, protiúčet, variabilní symbol, text, částka) a akce:
--   - action_ignore: pohyb se nepáruje s doklady (match_status='ignored', ignore_origin='rule'),
--   - tax_bucket: zařazení v peněžním deníku stejnými hodnotami jako ruční zařazení
--     (de_movement_classification, 1027). Daňová uznatelnost výdaje je tu tatáž volba
--     jako u pravidel nákladů (1989): expense_taxable = daňový, expense_nontax = nedaňový.
-- Pravidlo se uplatní jen na nespárovaný pohyb bez ručního zařazení: při importu výpisu
-- a tlačítkem na existující pohyby.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_evidence_bank_rules (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  name                 VARCHAR(120) NOT NULL,
  priority             SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'nižší číslo vyhrává',
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  direction            ENUM('any','incoming','outgoing') NOT NULL DEFAULT 'any',
  counterparty_account VARCHAR(64) NULL COMMENT 'číslo účtu protistrany (předčíslí-číslo, bez kódu banky)',
  variable_symbol      VARCHAR(20) NULL,
  text_contains        VARCHAR(255) NULL COMMENT 'hledá se ve zprávě a názvu protistrany',
  amount_min           DECIMAL(15,2) NULL COMMENT 'absolutní částka pohybu',
  amount_max           DECIMAL(15,2) NULL,
  action_ignore        TINYINT(1) NOT NULL DEFAULT 0,
  tax_bucket           ENUM('income_taxable','income_exempt','income_nontax',
                            'expense_taxable','expense_nontax','transfer','private') NULL,
  hit_count            INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at          DATETIME NULL,
  created_by           INT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_tebr_supplier (supplier_id, is_active, priority),
  CONSTRAINT fk_tebr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_tebr_criteria CHECK (counterparty_account IS NOT NULL OR variable_symbol IS NOT NULL
                                      OR text_contains IS NOT NULL),
  CONSTRAINT chk_tebr_action CHECK (action_ignore = 1 OR tax_bucket IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
