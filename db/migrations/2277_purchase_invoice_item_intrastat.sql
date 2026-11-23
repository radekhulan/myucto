-- Kód zboží pro Intrastat (KN8) a země původu z řádku přijaté faktury.
--
-- Hodnoty z dokladu se na kartu samy nezapisují: řádek příjemky nese výslovnou volbu
-- člověka nebo volajícího API (`fill_intrastat`) a doplnění proběhne až po zaúčtování
-- příjemky. Formát sloupců na řádku faktury odpovídá sloupcům ve `stock_items`.

ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS intrastat_cn8_code          CHAR(8) NULL DEFAULT NULL AFTER item_ean,
  ADD COLUMN IF NOT EXISTS intrastat_country_of_origin CHAR(2) NULL DEFAULT NULL AFTER intrastat_cn8_code;

ALTER TABLE stock_document_lines
  ADD COLUMN IF NOT EXISTS fill_intrastat TINYINT(1) NOT NULL DEFAULT 0 AFTER purchase_order_line_id;
