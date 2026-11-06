-- EET: poukazy a kredit jako atribut zboží, úhrada poukazem/kreditem a tržby
-- evidované externím systémem (e-shop s vlastní EET).
--
-- stock_items.eet_item_kind: druh položky pro EET. `specific_voucher` = dárkový
-- poukaz na konkrétní zboží či službu (běžný prodej, žádná zvláštní pole),
-- `stored_value` = hodnotový poukaz na blíže nespecifikované zboží nebo dobití
-- kreditu v omezené síti (při prodeji se jeho podíl uvádí v `urceno_cerp_zuct`).
--
-- payment_events.channel `stored_value` = úhrada poukazem / kreditem (čerpání,
-- `cerp_zuct`). eet_decision a eet_records.status `external` = tržbu evidoval jiný
-- systém (e-shop) sám; záznam se v MyÚčtu jen eviduje a nikdy neodesílá. Externí
-- záznam nemusí mít zařízení ani jednotku v MyÚčtu, proto jsou tyto snapshoty
-- volitelné. `external_key` (EIČ|id_pokl|porad_cis) brání dvojímu převzetí téže tržby.
--
-- api_tokens.eet_external_by_default: úhrady přijaté tímto tokenem bez kontextu EET
-- eviduje externí systém.

SET NAMES utf8mb4;

ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS eet_item_kind ENUM('standard','specific_voucher','stored_value') NOT NULL DEFAULT 'standard'
    COMMENT 'druh položky pro EET: běžné zboží, dárkový poukaz na konkrétní plnění, hodnotový poukaz / kredit'
    AFTER item_type;

ALTER TABLE payment_events
  MODIFY COLUMN channel ENUM('cash','card_terminal','qr_in_person','bank_transfer','card_online','cash_on_delivery','other','stored_value') NULL,
  MODIFY COLUMN eet_decision ENUM('required','voluntary','not_subject','unknown','unsupported','inactive','external') NOT NULL;

ALTER TABLE eet_records
  MODIFY COLUMN status ENUM('pending','sending','retry_wait','confirmed','confirmed_warning','action_required','verification_ok','external') NOT NULL DEFAULT 'pending',
  MODIFY COLUMN unit_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN unit_number INT UNSIGNED NULL,
  MODIFY COLUMN device_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN device_code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN sequence_number VARCHAR(25) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD COLUMN IF NOT EXISTS external_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL
    COMMENT 'EIČ|id_pokl|porad_cis tržby evidované externím systémem'
    AFTER sequence_number,
  ADD UNIQUE KEY IF NOT EXISTS uq_eet_records_external (supplier_id, external_key);

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS eet_external_by_default TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'úhrady z tohoto tokenu bez kontextu EET eviduje externí systém (e-shop)'
    AFTER allow_payroll_submission_docs;
