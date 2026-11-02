-- Výsledek dohledání je jen diagnostika; úhrady a návrhy zůstávají ve stávající evidenci.
ALTER TABLE purchase_invoices ADD COLUMN IF NOT EXISTS bank_matching_result JSON NULL;
CREATE INDEX IF NOT EXISTS idx_bt_statement_status_date ON bank_transactions (statement_id, match_status, posted_at);
CREATE INDEX IF NOT EXISTS idx_bt_statement_status_vs ON bank_transactions (statement_id, match_status, variable_symbol);
