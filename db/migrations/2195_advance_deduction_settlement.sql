-- Čerpání zálohy konečnou fakturou zadané v saldokontu (akce „Vyrovnat" u zálohy na 314/324).
--
-- Poskytnuté zálohy na 314 už čerpání po dvojicích záloha × faktura mají
-- (`purchase_advance_deductions`, migrace 2190), plní ho ale jen převod z účetního
-- programu. Saldokonto do něj teď zapisuje i ručně. `source` odliší ruční čerpání,
-- které jde v saldokontu zase zrušit, od převzatého z převodu: to patří ke zdrojovým
-- datům a ruší se opravou převodu. Výchozí `import` drží stávající i budoucí zápisy
-- převodů beze změny jejich SQL.
--
-- Přijaté zálohy na 324 vedou vazbu jen přes `invoices.parent_invoice_id`, tedy jedna
-- konečná faktura = jedna proforma. Vyúčtování víc záloh jednou fakturou nebo zálohy
-- čerpané po částech víc fakturami tak saldokonto nepoznalo. `invoice_advance_deductions`
-- je zrcadlo `purchase_advance_deductions` pro vydanou stranu: `amount` = Kč, o kolik
-- konečná faktura snižuje přijatou zálohu na zálohovém účtu (má dáti 324). Čerpání
-- platí od zaúčtování konečné faktury, stejně jako vazba přes parent_invoice_id.
SET NAMES utf8mb4;

ALTER TABLE purchase_advance_deductions
  ADD COLUMN IF NOT EXISTS source ENUM('import','saldo') NOT NULL DEFAULT 'import'
      COMMENT 'import = převod z účetního programu, saldo = ručně v saldokontu' AFTER amount;

CREATE TABLE IF NOT EXISTS invoice_advance_deductions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED    NOT NULL,
  final_invoice_id    BIGINT UNSIGNED NOT NULL COMMENT 'invoices.id konečné faktury',
  advance_invoice_id  BIGINT UNSIGNED NOT NULL COMMENT 'invoices.id zálohové faktury (proforma)',
  amount              DECIMAL(15,2)   NOT NULL COMMENT 'čerpáno ze zálohového účtu, Kč, kladné',
  source              ENUM('import','saldo') NOT NULL DEFAULT 'import'
                      COMMENT 'import = převod z účetního programu, saldo = ručně v saldokontu',
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iad_pair (final_invoice_id, advance_invoice_id),
  KEY ix_iad_advance (supplier_id, advance_invoice_id),
  KEY ix_iad_final (supplier_id, final_invoice_id),
  CONSTRAINT fk_iad_supplier FOREIGN KEY (supplier_id)        REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_iad_final    FOREIGN KEY (final_invoice_id)   REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_iad_advance  FOREIGN KEY (advance_invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_iad_user     FOREIGN KEY (created_by)         REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT chk_iad_amount  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
