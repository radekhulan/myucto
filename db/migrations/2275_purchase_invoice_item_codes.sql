-- Kód položky dodavatele a EAN na řádku přijaté faktury.
--
-- Vytěžení (AI, ISDOC, Pohoda XML) kód zboží z řádku zahazovalo, takže řádek nešel
-- spárovat se skladovou kartou a člověk ho přiřazoval ručně. Délka `seller_item_code`
-- odpovídá `stock_item_vendors.vendor_sku`, délka `item_ean` odpovídá `stock_items.ean`.

ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS seller_item_code VARCHAR(80) NULL DEFAULT NULL AFTER stock_item_id,
  ADD COLUMN IF NOT EXISTS item_ean         VARCHAR(20) NULL DEFAULT NULL AFTER seller_item_code;
