-- Konečná faktura proformy, dobropisy a DDKP k dokladu se hledají podmínkou
-- supplier_id + parent_invoice_id (uzávěrková kontrola K3, saldokonto, vyúčtování
-- záloh). Index jen na parent_invoice_id optimizer při neaktuálních statistikách
-- obcházel přes idx_inv_supplier a za každou úhradu proformy procházel všechny
-- faktury firmy. Složený index drží hledání v rámci firmy na jednom klíči.

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_inv_supplier_parent
    (supplier_id, parent_invoice_id);
