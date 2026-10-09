-- 1994 — Přílohy k ruční úhradě vydané faktury (pohyb peněžního deníku daňové evidence).
--
-- Pohyby peněžního deníku přebírají přílohy existující vazbou dokument ↔ entita
-- (document_links): pokladní doklad, bankovní pohyb a přijatá faktura už entitou jsou.
-- Ruční úhrada faktury bez bankovního pohybu (invoice_payments, noha C1 deníku) dosud
-- ne — doplňuje se typ 'invoice_payment'. MODIFY se stejným výčtem je idempotentní.
SET NAMES utf8mb4;

ALTER TABLE document_links
  MODIFY entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry','bank_transaction','cash_document','other_item','invoice_payment') NOT NULL;
