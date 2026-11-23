-- Koncept vydané faktury z příjemky: vazba řádku příjemky na fakturu, která z něj vznikla,
-- a příznak řádku faktury „cenu zadá člověk". Vazba drží číslo řádku, ne id: řádky konceptu
-- příjemky se při úpravě mažou a znovu zakládají, číslo řádku přežije a vazba s ním.
CREATE TABLE IF NOT EXISTS stock_receipt_invoice_links (
  supplier_id  INT UNSIGNED NOT NULL,
  document_id  BIGINT UNSIGNED NOT NULL,
  line_no      INT UNSIGNED NOT NULL,
  invoice_id   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, document_id, line_no, invoice_id),
  KEY ix_stock_receipt_invoice_tenant (supplier_id, invoice_id),
  CONSTRAINT fk_stock_receipt_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_receipt_invoice_document FOREIGN KEY (document_id) REFERENCES stock_documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_receipt_invoice_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS manual_price TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Cenu řádku zadá člověk (cena 0 z konceptu), zhasne s první nenulovou cenou.';
