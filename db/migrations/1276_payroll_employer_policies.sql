-- MyÚčto.cz — MZ-03-W04/W06: účinné politiky zaměstnavatele a jejich audit.
--
-- Překryv období hlídá trigger, NE deklarativní `PERIOD FOR` + `UNIQUE ...
-- WITHOUT OVERLAPS`. Náhrada byla zvážena a ověřena proti MariaDB 11.8.9,
-- narazila na tři věci, které se s tímhle schématem nepotkávají:
--
--  1. Sloupce v PERIOD nesmí být NULL — MariaDB je při CREATE TABLE tiše
--     překlopí na NOT NULL. Otevřená platnost (`valid_to IS NULL`) by musela
--     přejít na sentinel '9999-12-31' napříč schématem, repository vrstvou
--     i API. Jediný ostrý řádek policy je přitom právě otevřený.
--  2. Obejít to generovaným sloupcem (`COALESCE(valid_to,'9999-12-31')`) nejde,
--     MariaDB to odmítá: ERROR 4155 "Period field cannot be GENERATED ALWAYS AS".
--  3. PERIOD je polootevřený interval [from, to) s vynuceným from < to, kdežto
--     trigger níž implementuje uzavřený [from, to]. Pár 2026-01-01..2026-06-01
--     a 2026-06-01..2026-09-01 trigger odmítne (oba si nárokují 6. 1.), PERIOD
--     ho přijme. Jednodenní platnost (valid_from = valid_to), kterou dnešní
--     CHECK dovoluje, by naopak přestala jít vložit (ERROR 4025).
--
-- Přechod na WITHOUT OVERLAPS tedy není refaktoring, ale migrace semantiky
-- platnosti z uzavřeného intervalu na polootevřený včetně posunu všech
-- existujících `valid_to` a změny významu pole "platnost do" v UI. Dokud to
-- nikdo vědomě neodrozhodne, zůstává trigger.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_employer_policies (
  id                                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                       INT UNSIGNED NOT NULL,
  valid_from                        DATE NOT NULL,
  valid_to                          DATE NULL,
  payday_day                        TINYINT UNSIGNED NOT NULL,
  payday_month_offset               TINYINT UNSIGNED NOT NULL DEFAULT 1,
  payday_business_day_rule          VARCHAR(32) NOT NULL,
  balance_rounding_mode             VARCHAR(32) NOT NULL,
  home_office_policy                VARCHAR(32) NOT NULL,
  travel_expense_policy             VARCHAR(32) NOT NULL,
  four_eyes_required                TINYINT(1) NOT NULL DEFAULT 1,
  automatic_calculation_enabled     TINYINT(1) NOT NULL DEFAULT 0,
  automatic_posting_enabled         TINYINT(1) NOT NULL DEFAULT 0,
  automatic_payments_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  delivery_channel                  VARCHAR(32) NOT NULL,
  delivery_verified_on              DATE NULL,
  source_kind                       VARCHAR(24) NOT NULL,
  source_reference                  VARCHAR(255) NULL,
  created_by                        INT UNSIGNED NULL,
  updated_by                        INT UNSIGNED NULL,
  row_version                       INT UNSIGNED NOT NULL DEFAULT 1,
  created_at                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employer_policy_start (supplier_id, valid_from),
  UNIQUE KEY uq_payroll_employer_policy_supplier_id (supplier_id, id),
  KEY idx_payroll_employer_policy_effective
    (supplier_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_employer_policy_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_employer_policy_dates
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_employer_policy_payday
    CHECK (payday_day BETWEEN 1 AND 31),
  CONSTRAINT chk_payroll_employer_policy_month_offset
    CHECK (payday_month_offset IN (0, 1)),
  CONSTRAINT chk_payroll_employer_policy_business_day
    CHECK (payday_business_day_rule IN
      ('none', 'previous_business_day', 'next_business_day')),
  CONSTRAINT chk_payroll_employer_policy_rounding
    CHECK (balance_rounding_mode IN
      ('exact_minor_units', 'nearest_crown', 'up_to_crown')),
  CONSTRAINT chk_payroll_employer_policy_home_office
    CHECK (home_office_policy IN
      ('not_used', 'manual_review', 'configured')),
  CONSTRAINT chk_payroll_employer_policy_travel
    CHECK (travel_expense_policy IN
      ('not_used', 'manual_review', 'configured')),
  CONSTRAINT chk_payroll_employer_policy_four_eyes
    CHECK (four_eyes_required IN (0, 1)),
  CONSTRAINT chk_payroll_employer_policy_auto_calculation
    CHECK (automatic_calculation_enabled IN (0, 1)),
  CONSTRAINT chk_payroll_employer_policy_auto_posting
    CHECK (automatic_posting_enabled IN (0, 1)),
  CONSTRAINT chk_payroll_employer_policy_auto_payments
    CHECK (automatic_payments_enabled IN (0, 1)),
  CONSTRAINT chk_payroll_employer_policy_delivery
    CHECK (delivery_channel IN
      ('disabled', 'employee_portal', 'smime_email', 'manual_handover')),
  CONSTRAINT chk_payroll_employer_policy_delivery_verification
    CHECK (
      (delivery_channel = 'disabled' AND delivery_verified_on IS NULL)
      OR
      (delivery_channel <> 'disabled' AND delivery_verified_on IS NOT NULL)
    ),
  CONSTRAINT chk_payroll_employer_policy_source
    CHECK (source_kind IN ('manual', 'import', 'migration', 'system')),
  CONSTRAINT chk_payroll_employer_policy_row_version
    CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employer_policy_audit (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  policy_id          BIGINT UNSIGNED NOT NULL,
  action             VARCHAR(16) NOT NULL,
  snapshot_json      JSON NOT NULL,
  snapshot_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_user_id      INT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_payroll_employer_policy_audit
    (supplier_id, policy_id, id),
  CONSTRAINT fk_payroll_employer_policy_audit_policy
    FOREIGN KEY (supplier_id, policy_id)
    REFERENCES payroll_employer_policies (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_employer_policy_audit_action
    CHECK (action IN ('created', 'updated')),
  CONSTRAINT chk_payroll_employer_policy_audit_hash
    CHECK (snapshot_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_payroll_employer_policy_audit_json
    CHECK (JSON_VALID(snapshot_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_overlap_insert//

CREATE TRIGGER trg_payroll_employer_policy_overlap_insert
BEFORE INSERT ON payroll_employer_policies
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_employer_policies policy
     WHERE policy.supplier_id = NEW.supplier_id
       AND policy.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(policy.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_overlap_update//

CREATE TRIGGER trg_payroll_employer_policy_overlap_update
BEFORE UPDATE ON payroll_employer_policies
FOR EACH ROW
BEGIN
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy row version must increase';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employer_policies policy
     WHERE policy.supplier_id = NEW.supplier_id
       AND policy.id <> NEW.id
       AND policy.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(policy.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_audit_update//

CREATE TRIGGER trg_payroll_employer_policy_audit_update
BEFORE UPDATE ON payroll_employer_policy_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll employer policy audit is append-only';
END//

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_audit_delete//

CREATE TRIGGER trg_payroll_employer_policy_audit_delete
BEFORE DELETE ON payroll_employer_policy_audit
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll employer policy audit is append-only';
END//

DROP TRIGGER IF EXISTS trg_payroll_employer_policy_delete//

CREATE TRIGGER trg_payroll_employer_policy_delete
BEFORE DELETE ON payroll_employer_policies
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll employer policies are retained for audit';
END//

DELIMITER ;
