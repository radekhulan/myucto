-- Posuny stavů převzatých výpisů podle prvního výpisu banky (ImportedStatementRebase).
-- Smazání výpisu banky, podle kterého se stavy posunuly, posun vrátí.
CREATE TABLE IF NOT EXISTS bank_statement_rebases (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  anchor_statement_id BIGINT UNSIGNED NOT NULL COMMENT 'výpis banky, podle kterého se stavy posunuly',
  shift               DECIMAL(14,2) NOT NULL COMMENT 'posun v měně účtu',
  statement_ids       TEXT NOT NULL COMMENT 'JSON seznam posunutých převzatých výpisů',
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bsr_anchor (supplier_id, anchor_statement_id),
  CONSTRAINT fk_bsr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
