-- Vývoj hodnoty zásob hlídá během výpočtu jen doklady změněné od začátku úlohy
-- (StockValueTrendService::fingerprint), ne celou historii firmy.

ALTER TABLE stock_documents
  ADD INDEX IF NOT EXISTS idx_sd_supplier_updated (supplier_id, updated_at);
