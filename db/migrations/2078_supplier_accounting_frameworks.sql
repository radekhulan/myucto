-- MyÚčto.cz - historizovaný účetní rámec firmy (vyhláška 500/2002 Sb. a 504/2002 Sb.).
--
-- Účetní rámec je oddělený od způsobu evidence v supplier_accounting_modes.
-- V této etapě aplikace zapisuje pouze cz_500; cz_504 je ve schématu připraveno pro
-- navazující implementaci, ale AccountingFrameworkService jeho aktivaci odmítá.
--
-- Baseline zachová všem existujícím firmám dosavadní chování podle vyhlášky 500.
-- Idempotence: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS supplier_accounting_frameworks (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  effective_from       DATE NOT NULL,
  accounting_framework ENUM('cz_500','cz_504') NOT NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by           INT UNSIGNED NULL,

  UNIQUE KEY uq_supplier_accounting_framework_date (supplier_id, effective_from),
  KEY idx_supplier_accounting_framework_lookup (supplier_id, effective_from, accounting_framework),
  CONSTRAINT fk_supplier_accounting_framework_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO supplier_accounting_frameworks
  (supplier_id, effective_from, accounting_framework)
SELECT id, '1900-01-01', 'cz_500' FROM supplier;
