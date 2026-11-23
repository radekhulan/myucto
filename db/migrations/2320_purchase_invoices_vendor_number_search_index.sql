-- Globální hledání (Alt+Q, Ctrl+K) hledá přijatou fakturu i v ostatních firmách
-- uživatele, když ji v aktivní firmě nenajde. Číslo dokladu dodavatele se tam
-- porovnává od začátku (`LIKE 'x%'`) a index (supplier_id, vendor_invoice_number)
-- z toho dělá rozsah v každé firmě. Unikátní uq_pi_vendor_invoice má mezi firmou
-- a číslem dodavatele, takže se na hledání čísla nehodí.
SET NAMES utf8mb4;

CREATE INDEX IF NOT EXISTS ix_pi_supplier_vendor_number ON purchase_invoices (supplier_id, vendor_invoice_number);
