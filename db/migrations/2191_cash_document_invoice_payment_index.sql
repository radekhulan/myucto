-- Pokladní doklad úhrady vydané faktury: saldokonto u každé úhrady zálohové faktury
-- ověřuje, jestli ji nese pokladní doklad (`cash_documents.invoice_payment_id`). Bez
-- indexu procházel každý dotaz všechny pokladní doklady firmy, na firmě se čtyřmi
-- tisíci doklady to byla desetina sekundy na každý saldokontní účet.
ALTER TABLE cash_documents
    ADD INDEX IF NOT EXISTS idx_cashdoc_invoice_payment (supplier_id, invoice_payment_id);
