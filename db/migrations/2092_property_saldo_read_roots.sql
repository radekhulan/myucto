ALTER TABLE unit_charges
 ADD COLUMN IF NOT EXISTS read_unit_id BIGINT UNSIGNED GENERATED ALWAYS AS (JSON_VALUE(snapshot_json,'$.unit_id')) STORED,
 ADD INDEX IF NOT EXISTS ix_unit_charge_read_unit (supplier_id,read_unit_id,id);
ALTER TABLE unit_payment_debts
 ADD COLUMN IF NOT EXISTS read_charge_id BIGINT UNSIGNED GENERATED ALWAYS AS (JSON_VALUE(snapshot_json,'$.charge_id')) STORED,
 ADD INDEX IF NOT EXISTS ix_unit_debt_read_charge (supplier_id,read_charge_id,id);
ALTER TABLE unit_credit_lots
 ADD COLUMN IF NOT EXISTS read_unit_id BIGINT UNSIGNED GENERATED ALWAYS AS (JSON_VALUE(snapshot_json,'$.unit_id')) STORED,
 ADD INDEX IF NOT EXISTS ix_unit_credit_read_unit (supplier_id,read_unit_id,id);
