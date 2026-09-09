ALTER TABLE stock_documents
    ADD COLUMN IF NOT EXISTS allow_over_delivery TINYINT(1) NOT NULL DEFAULT 0;
