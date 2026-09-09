ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER pricing_base;

ALTER TABLE stock_items
  DROP CONSTRAINT IF EXISTS chk_stock_items_row_version;

ALTER TABLE stock_items
  ADD CONSTRAINT chk_stock_items_row_version CHECK (row_version > 0);
