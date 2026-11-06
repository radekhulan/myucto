ALTER TABLE unit_payment_operations
 ADD COLUMN IF NOT EXISTS read_links_json LONGTEXT
 GENERATED ALWAYS AS (JSON_OBJECT(
  'debt_ids',JSON_EXTRACT(snapshot_json,'$.allocations[*].debt_id'),
  'charge_ids',JSON_EXTRACT(snapshot_json,'$.allocations[*].charge_id'),
  'original_operation_id',JSON_EXTRACT(snapshot_json,'$.original_operation_id'),
  'credit_id',JSON_EXTRACT(snapshot_json,'$.credit.id'),
  'reservation_id',JSON_EXTRACT(snapshot_json,'$.reservation.id'),
  'correction_id',JSON_EXTRACT(snapshot_json,'$.correction_id'),
  'source_id',JSON_EXTRACT(snapshot_json,'$.source.id'),
  'credit_unit_id',JSON_EXTRACT(snapshot_json,'$.credit.unit_id')
 )) STORED;
