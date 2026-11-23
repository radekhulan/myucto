-- Filtr deníku a sestav na středisko hledá i řádky s textovým kódem střediska.
-- Bez indexu procházel celé řádky deníku firmy; index vede rovnou na řádky s kódem.
CREATE INDEX IF NOT EXISTS idx_jel_supplier_cost_center ON journal_entry_lines (supplier_id, cost_center, entry_id);
