-- Párování řádku přijaté faktury se skladovou kartou: hledá se podle kódu dodavatele
-- a podle EAN v rámci firmy (kód karty už index má, uq_si_supplier_sku).

ALTER TABLE stock_item_vendors
  ADD INDEX IF NOT EXISTS idx_siv_vendor_sku (supplier_id, client_id, vendor_sku);

ALTER TABLE stock_items
  ADD INDEX IF NOT EXISTS idx_si_supplier_ean (supplier_id, ean);

-- Volba „zapamatovat kód“ čeká na řádku příjemky na zaúčtování: koncept kartu nemění.
ALTER TABLE stock_document_lines
  ADD COLUMN IF NOT EXISTS remember_seller_code TINYINT(1) NOT NULL DEFAULT 0 AFTER purchase_order_line_id;
