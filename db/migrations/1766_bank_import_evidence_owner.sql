ALTER TABLE bank_transaction_imports
    ADD COLUMN IF NOT EXISTS supplier_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS original_statement_id BIGINT UNSIGNED NULL;

UPDATE bank_transaction_imports bti
JOIN bank_transactions bt ON bt.id = bti.bank_transaction_id
JOIN bank_statements bs ON bs.id = bt.statement_id
SET bti.supplier_id = bs.supplier_id, bti.original_statement_id = bs.id
WHERE bti.original_statement_id IS NULL;

ALTER TABLE bank_transaction_imports
    ADD FOREIGN KEY IF NOT EXISTS fk_bti_original (original_statement_id)
    REFERENCES bank_statements(id) ON DELETE RESTRICT;
