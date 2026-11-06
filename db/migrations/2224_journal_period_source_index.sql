-- Deník: hledání zápisu daného zdroje v období (kontrola uzávěrky).
--
-- Dotaz na uzávěrkový zápis období šel přes (supplier_id, period_id) a pro každý doklad
-- prošel a zamkl všechny zápisy období. Převod firmy s 21 000 zápisy na tom trávil
-- většinu času. Nový index dotaz zúží na zápisy uzávěrky a zámek na ně.

ALTER TABLE journal_entries
  ADD INDEX IF NOT EXISTS idx_je_supplier_period_source (supplier_id, period_id, source_type);
