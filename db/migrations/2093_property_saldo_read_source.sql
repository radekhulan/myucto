ALTER TABLE unit_payment_operations
 ADD COLUMN IF NOT EXISTS read_source_entry_id BIGINT UNSIGNED GENERATED ALWAYS AS (JSON_VALUE(snapshot_json,'$.source.receipt.ledger.entry.id')) STORED,
 ADD INDEX IF NOT EXISTS ix_unit_payment_read_source (supplier_id,read_source_entry_id,id);
