-- Jednotkové a ceníkové ceny na šest desetinných míst. Levný materiál po
-- statisících kusů (0,004 EUR/ks) se na dvou místech vynuloval. Řádky dokladů
-- mají šest míst od migrace 1842, tady se dorovnají skladové karty, ceníky,
-- cenové hladiny, akční a zákaznické ceny, nákupní ceny dodavatelů a jednotkové
-- ceny materiálu, drobného majetku a tankování. Součty zůstávají v haléřích.
-- Rozšíření nemění uložené hodnoty a opakované spuštění nic nezmění.

ALTER TABLE stock_items
  MODIFY COLUMN sale_price_without_vat DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE stock_item_prices
  MODIFY COLUMN fixed_price DECIMAL(16,6) NULL DEFAULT NULL,
  MODIFY COLUMN computed_price DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE stock_item_customer_prices
  MODIFY COLUMN fixed_price DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE stock_item_promo_prices
  MODIFY COLUMN promo_price DECIMAL(16,6) NOT NULL;

ALTER TABLE stock_price_level_rules
  MODIFY COLUMN fixed_price DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE stock_item_vendors
  MODIFY COLUMN purchase_price DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE price_list_item_prices
  MODIFY COLUMN unit_price DECIMAL(16,6) NOT NULL;

ALTER TABLE price_list_customer_overrides
  MODIFY COLUMN unit_price DECIMAL(16,6) NOT NULL;

ALTER TABLE recurring_invoice_template_items
  MODIFY COLUMN catalog_source_unit_price DECIMAL(16,6) NULL DEFAULT NULL;

ALTER TABLE work_report_materials
  MODIFY COLUMN unit_price DECIMAL(16,6) NOT NULL DEFAULT 0.000000;

ALTER TABLE small_assets
  MODIFY COLUMN unit_price DECIMAL(16,6) NOT NULL DEFAULT 0.000000;

ALTER TABLE fuelings
  MODIFY COLUMN unit_price DECIMAL(16,6) NULL DEFAULT NULL;
