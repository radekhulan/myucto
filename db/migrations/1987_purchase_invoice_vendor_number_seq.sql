-- MyÚčto.cz — vědomě potvrzený duplikát čísla přijatého dokladu (issue #140).
--
-- Platby jednoho platebního kalendáře (energie) mají stejného dodavatele, stejné
-- číslo dokladu a často i stejné datum vystavení. Unikátní klíč uq_pi_vendor_invoice
-- dostává pořadí vendor_number_seq: běžné doklady mají 0, takže kontrola duplicit
-- zůstává beze změny. Pořadí > 0 nastaví aplikace jen po výslovném potvrzení shody.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, index se zahodí a založí znovu.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS vendor_number_seq SMALLINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'pořadí vědomě potvrzeného duplikátu čísla dokladu dodavatele (0 = běžný doklad)';

ALTER TABLE purchase_invoices
  DROP INDEX IF EXISTS uq_pi_vendor_invoice,
  ADD UNIQUE KEY uq_pi_vendor_invoice (supplier_id, vendor_id, vendor_invoice_number, issue_date, vendor_number_seq);
