ALTER TABLE sales_quote_orders
  ADD COLUMN IF NOT EXISTS line_origins LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(line_origins));
