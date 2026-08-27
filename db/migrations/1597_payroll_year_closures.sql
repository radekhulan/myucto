-- MZ-25-W05: tenantová roční uzávěrka mezd. Historická data se záměrně nedoplňují;
-- řádek vznikne až při skutečném uzavření konkrétního roku.

CREATE TABLE IF NOT EXISTS payroll_year_closures (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id   INT UNSIGNED NOT NULL,
  calendar_year SMALLINT UNSIGNED NOT NULL,
  status        ENUM('open','closed') NOT NULL DEFAULT 'open',
  row_version   INT UNSIGNED NOT NULL DEFAULT 1,
  closed_at     DATETIME NULL,
  closed_by     BIGINT UNSIGNED NULL,
  reopened_at   DATETIME NULL,
  reopened_by   BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_year_closure_tenant_year (supplier_id, calendar_year),
  UNIQUE KEY uq_payroll_year_closure_tenant_id (supplier_id, id),
  CONSTRAINT fk_payroll_year_closure_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_year_closure_closed_by
    FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_year_closure_reopened_by
    FOREIGN KEY (reopened_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_year_closure_year CHECK (calendar_year BETWEEN 2000 AND 2200),
  CONSTRAINT chk_payroll_year_closure_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
