-- Konečné faktury firmy navázané na poskytnutou zálohu.
--
-- Odpočty zálohy na konečných fakturách (PurchaseCostSql) se dohledávají pro jednu
-- firmu. Index `ix_pii_advance_purchase_invoice` na řádcích dokladů firmu nezná, takže
-- dotaz po něm procházel odpočtové řádky všech firem v databázi, a `uq_pi_advance_link`
-- na hlavičce také ne. Dotaz teď vychází z konečných faktur firmy s vyplněnou zálohou
-- a řádky odpočtu dohledá po `ix_pii_advance_purchase_invoice` pro každou z nich.
SET NAMES utf8mb4;

CREATE INDEX IF NOT EXISTS idx_pi_supplier_advance ON purchase_invoices (supplier_id, advance_purchase_invoice_id);
