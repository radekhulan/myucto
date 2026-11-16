-- Platební identifikace je mimo neměnné finanční důkazy. Staré případy zůstávají beze změny.
ALTER TABLE nonprofit_membership_charge_cases
 ADD COLUMN IF NOT EXISTS variable_symbol VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN IF NOT EXISTS variable_symbol_match_key VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin
  GENERATED ALWAYS AS (NULLIF(TRIM(LEADING '0' FROM variable_symbol), '')) STORED,
 ADD INDEX IF NOT EXISTS ix_nmcc_variable_symbol (supplier_id,variable_symbol_match_key);

ALTER TABLE nonprofit_membership_charge_claims
 ADD INDEX IF NOT EXISTS ix_nmclaim_bank_amount (supplier_id,amount_cents,id);
