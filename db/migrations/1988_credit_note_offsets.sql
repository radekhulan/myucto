-- MyÚčto.cz — zápočet dobropisu proti opravované faktuře (issue #140).
--
-- Dobropis navázaný na fakturu (vydaná strana invoices.parent_invoice_id, přijatá
-- purchase_invoices.parent_purchase_invoice_id) dosud „Zbývá uhradit" faktury nesnižoval.
-- Odběratel ale platí rozdíl, takže úhradu nešlo spárovat a dobropis visel jako
-- samostatná pohledávka/závazek.
--
-- credit_note_offsets = jeden zápočet dobropisu proti faktuře, vždy v plné výši dobropisu
-- a v měně dokladu. Vydaná strana k němu zapíše invoice_payments (source='credit_note'),
-- takže paid_total i párování banky počítají se zbytkem. Přijatá strana nemá paid_total,
-- zápočet čte PurchaseSettledExpr. Dobropis dostane stav 'paid' (vyrovnaný).
--
-- Zápočet dobropisu není peněžní tok ani nepeněžní úhrada dvou pohledávek: jen snižuje
-- tutéž pohledávku. Peněžní deník ho proto nezná, příjem/výdaj vzniká až úhradou zbytku.
-- DPH se nemění, dobropis je v evidenci DPH sám za sebe.
--
-- Idempotence: MODIFY (append-only ENUM), CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoice_payments
  MODIFY COLUMN source
  ENUM('manual','mark_paid','bank','legacy','cash','settlement','credit_note')
  NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS credit_note_offsets (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  doc_type           ENUM('invoice','purchase_invoice') NOT NULL,
  invoice_id         BIGINT UNSIGNED NOT NULL COMMENT 'opravovaná faktura (invoices.id / purchase_invoices.id)',
  credit_note_id     BIGINT UNSIGNED NOT NULL COMMENT 'dobropis (invoices.id / purchase_invoices.id)',
  amount             DECIMAL(15,2) NOT NULL COMMENT 'započtená částka v měně dokladu, kladná',
  offset_on          DATE NOT NULL COMMENT 'datum zápočtu = datum vystavení dobropisu',
  invoice_payment_id BIGINT UNSIGNED NULL COMMENT 'vydaná strana: platba na faktuře',
  created_by         INT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_cno_credit_note (doc_type, credit_note_id),
  KEY idx_cno_invoice (supplier_id, doc_type, invoice_id),
  CONSTRAINT fk_cno_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_cno_payment FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments(id) ON DELETE CASCADE,
  CONSTRAINT chk_cno_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
