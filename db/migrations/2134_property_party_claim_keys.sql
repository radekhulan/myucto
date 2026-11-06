-- Osobní saldo SVJ stránkuje po množinách osob v databázi. Řádek osobního salda je množina
-- dlužníků nároku (nebo věřitelů kreditu) napříč jednotkami; jaká množina nárok k datu nese,
-- se mění potvrzeným přechodem dluhu. Tabulka proto drží pro každý zdroj, který může množinu
-- určit (titul dluhu, předpis, kredit a každý potvrzený přechod), otisk množiny a jednotku.
-- Stránka vezme po indexu další otisky, načte historii jen jejich jednotek a teprve v ní
-- určí množinu platnou k datu. Zdroje jsou neměnné, stačí je zapsat při vložení.
-- Otisk = SHA-256 ze vzestupně seřazených ID osob spojených čárkou, bez osob „-“.

CREATE TABLE IF NOT EXISTS property_party_claim_keys (
  supplier_id INT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  source_kind VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  debt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (supplier_id, unit_id, source_kind, source_id, debt_id),
  KEY idx_property_party_claim_key (supplier_id, key_hash, unit_id),
  CONSTRAINT chk_property_party_claim_kind CHECK (source_kind IN ('debt','charge','credit','event'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER property_party_claim_keys_debt AFTER INSERT ON unit_payment_debts FOR EACH ROW
INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
VALUES (NEW.supplier_id, JSON_VALUE(NEW.snapshot_json, '$.unit_id'), 'debt', NEW.id, 0, SHA2(COALESCE((
  SELECT GROUP_CONCAT(DISTINCT j.client_id ORDER BY j.client_id SEPARATOR ',')
  FROM JSON_TABLE(NEW.snapshot_json, '$.liability_groups[*]' COLUMNS (
    role VARCHAR(16) PATH '$.role',
    NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
  )) j
  WHERE j.role = 'debtor'
), '-'), 256));

CREATE OR REPLACE TRIGGER property_party_claim_keys_charge AFTER INSERT ON unit_charges FOR EACH ROW
INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
VALUES (NEW.supplier_id, NEW.unit_id, 'charge', NEW.id, 0, SHA2(COALESCE((
  SELECT GROUP_CONCAT(DISTINCT j.client_id ORDER BY j.client_id SEPARATOR ',')
  FROM JSON_TABLE(NEW.snapshot_json, '$.liability_groups[*]' COLUMNS (
    role VARCHAR(16) PATH '$.role',
    NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
  )) j
  WHERE j.role = 'debtor'
), '-'), 256));

CREATE OR REPLACE TRIGGER property_party_claim_keys_credit AFTER INSERT ON unit_credit_lots FOR EACH ROW
INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
VALUES (NEW.supplier_id, NEW.unit_id, 'credit', NEW.id, 0, SHA2(COALESCE((
  SELECT GROUP_CONCAT(DISTINCT j.client_id ORDER BY j.client_id SEPARATOR ',')
  FROM JSON_TABLE(NEW.snapshot_json, '$.parties[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$.id')) j
), '-'), 256));

CREATE OR REPLACE TRIGGER property_party_claim_keys_event AFTER INSERT ON property_claim_party_events FOR EACH ROW
INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
SELECT NEW.supplier_id, NEW.unit_id, 'event', NEW.id, j.debt_id,
  SHA2(COALESCE(GROUP_CONCAT(DISTINCT IF(j.role = 'debtor', j.client_id, NULL) ORDER BY IF(j.role = 'debtor', j.client_id, NULL) SEPARATOR ','), '-'), 256)
FROM JSON_TABLE(NEW.snapshot_json, '$.debts[*]' COLUMNS (
  debt_id BIGINT UNSIGNED PATH '$.debt_id',
  NESTED PATH '$.groups[*]' COLUMNS (
    role VARCHAR(16) PATH '$.role',
    NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
  )
)) j
GROUP BY j.debt_id;

INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
SELECT d.supplier_id, JSON_VALUE(d.snapshot_json, '$.unit_id'), 'debt', d.id, 0,
  SHA2(COALESCE(GROUP_CONCAT(DISTINCT IF(j.role = 'debtor', j.client_id, NULL) ORDER BY IF(j.role = 'debtor', j.client_id, NULL) SEPARATOR ','), '-'), 256)
FROM unit_payment_debts d
LEFT JOIN JSON_TABLE(d.snapshot_json, '$.liability_groups[*]' COLUMNS (
  role VARCHAR(16) PATH '$.role',
  NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
)) j ON TRUE
GROUP BY d.supplier_id, d.id;

INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
SELECT c.supplier_id, c.unit_id, 'charge', c.id, 0,
  SHA2(COALESCE(GROUP_CONCAT(DISTINCT IF(j.role = 'debtor', j.client_id, NULL) ORDER BY IF(j.role = 'debtor', j.client_id, NULL) SEPARATOR ','), '-'), 256)
FROM unit_charges c
LEFT JOIN JSON_TABLE(c.snapshot_json, '$.liability_groups[*]' COLUMNS (
  role VARCHAR(16) PATH '$.role',
  NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
)) j ON TRUE
GROUP BY c.supplier_id, c.unit_id, c.id;

INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
SELECT l.supplier_id, l.unit_id, 'credit', l.id, 0,
  SHA2(COALESCE(GROUP_CONCAT(DISTINCT j.client_id ORDER BY j.client_id SEPARATOR ','), '-'), 256)
FROM unit_credit_lots l
LEFT JOIN JSON_TABLE(l.snapshot_json, '$.parties[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$.id')) j ON TRUE
GROUP BY l.supplier_id, l.unit_id, l.id;

INSERT IGNORE INTO property_party_claim_keys (supplier_id, unit_id, source_kind, source_id, debt_id, key_hash)
SELECT e.supplier_id, e.unit_id, 'event', e.id, j.debt_id,
  SHA2(COALESCE(GROUP_CONCAT(DISTINCT IF(j.role = 'debtor', j.client_id, NULL) ORDER BY IF(j.role = 'debtor', j.client_id, NULL) SEPARATOR ','), '-'), 256)
FROM property_claim_party_events e
JOIN JSON_TABLE(e.snapshot_json, '$.debts[*]' COLUMNS (
  debt_id BIGINT UNSIGNED PATH '$.debt_id',
  NESTED PATH '$.groups[*]' COLUMNS (
    role VARCHAR(16) PATH '$.role',
    NESTED PATH '$.members[*]' COLUMNS (client_id BIGINT UNSIGNED PATH '$')
  )
)) j
GROUP BY e.supplier_id, e.unit_id, e.id, j.debt_id;
