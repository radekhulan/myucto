-- Čerpání přijaté zálohy konečnou fakturou po dvojicích záloha × faktura.
--
-- `purchase_invoices.advance_purchase_invoice_id` vede u konečné faktury jedinou zálohu
-- a jedinečný index dovolí zálohu čerpat jedinou fakturou. Skutečnost je obojí 1:N:
-- vyúčtování energií odečítá dvanáct měsíčních záloh najednou a kauci nebo zálohu na
-- nájem odečítá každá měsíční faktura po částech. Bez vazby saldokonto 314 nepozná,
-- že je záloha vyúčtovaná, a drží ji otevřenou v plné výši.
--
-- `amount` = částka v Kč, o kterou faktura snižuje zálohu na zálohovém účtu (u plátce
-- základ, DPH zálohy čerpá daňový doklad k platbě). Plní ji převod z účetního programu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_advance_deductions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED    NOT NULL,
  final_invoice_id    BIGINT UNSIGNED NOT NULL COMMENT 'purchase_invoices.id konečné faktury',
  advance_invoice_id  BIGINT UNSIGNED NOT NULL COMMENT 'purchase_invoices.id zálohové faktury',
  amount              DECIMAL(15,2)   NOT NULL COMMENT 'čerpáno ze zálohového účtu, Kč, kladné',
  created_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pad_pair (final_invoice_id, advance_invoice_id),
  KEY ix_pad_advance (supplier_id, advance_invoice_id),
  CONSTRAINT fk_pad_supplier FOREIGN KEY (supplier_id)        REFERENCES supplier(id)          ON DELETE CASCADE,
  CONSTRAINT fk_pad_final    FOREIGN KEY (final_invoice_id)   REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_pad_advance  FOREIGN KEY (advance_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_pad_user     FOREIGN KEY (created_by)         REFERENCES users(id)             ON DELETE SET NULL,
  CONSTRAINT chk_pad_amount  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
