-- MyÚčto.cz — MZ-03-W05: mzdové dimenze (střediska, zakázky, činnosti) a jejich
-- přiřazení pracovním vztahům, nezávisle na účetním režimu firmy.
--
-- Firemní číselník `cost_centers` (migrace 1072) jsme nešli znovupoužít: je
-- gatovaný na accounting_mode='double_entry' (CostCenterAction::requireDoubleEntry)
-- a nemá typ ani účinnou historii. Mzdové dimenze musí fungovat i v daňové
-- evidenci a potřebují stejný vzor účinnosti od-do + optimistic lock jako
-- payroll_employer_policies (migrace 1276).
--
-- payroll_dimensions   = číselník s typem, kódem a jménem; kód je tenantově
--                        unikátní v rámci typu a s ohledem na historii účinnosti
--                        (stejný kód/typ smí existovat vícekrát v neprekrývajících
--                        se obdobích).
-- payroll_employment_dimensions = přiřazení dimenze pracovnímu vztahu s vlastní
--                        účinností; jeden vztah nesmí mít v jednu chvíli dvě
--                        dimenze stejného typu (overlap guard).
--
-- Použití ve schválené mzdové revizi (payroll_run_revisions.status='approved')
-- blokuje smazání dimenze — jde jen ukončit účinnost (UPDATE valid_to).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, DROP TRIGGER IF EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_dimensions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  dimension_type        ENUM('cost_center', 'project', 'activity') NOT NULL,
  code                  VARCHAR(50) NOT NULL,
  name                  VARCHAR(190) NOT NULL,
  valid_from            DATE NOT NULL,
  valid_to              DATE NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  default_account_code  VARCHAR(16) NULL
                          COMMENT 'Analytika k výchozím mzdovým kontacím MZ-03 (PayrollAccountingDefaults)',
  created_by            INT UNSIGNED NULL,
  updated_by            INT UNSIGNED NULL,
  row_version           INT UNSIGNED NOT NULL DEFAULT 1,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_dimension_supplier_id (supplier_id, id),
  KEY idx_payroll_dimension_effective
    (supplier_id, dimension_type, code, valid_from, valid_to),
  CONSTRAINT fk_payroll_dimension_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_dimension_code
    CHECK (code REGEXP '^[A-Z0-9][A-Z0-9._-]{0,49}$'),
  CONSTRAINT chk_payroll_dimension_dates
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_dimension_active CHECK (is_active IN (0, 1)),
  CONSTRAINT chk_payroll_dimension_account CHECK (
    default_account_code IS NULL
    OR default_account_code REGEXP '^[0-9]{3}[.A-Z0-9]{0,13}$'
  ),
  CONSTRAINT chk_payroll_dimension_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_dimensions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  employment_id  BIGINT UNSIGNED NOT NULL,
  dimension_id   BIGINT UNSIGNED NOT NULL,
  valid_from     DATE NOT NULL,
  valid_to       DATE NULL,
  created_by     INT UNSIGNED NULL,
  updated_by     INT UNSIGNED NULL,
  row_version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_employment_dimension_supplier_id (supplier_id, id),
  KEY idx_payroll_employment_dimension_employment
    (supplier_id, employment_id, valid_from, valid_to),
  KEY idx_payroll_employment_dimension_dimension (supplier_id, dimension_id),
  CONSTRAINT fk_payroll_employment_dimension_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_employment_dimension_dimension
    FOREIGN KEY (supplier_id, dimension_id)
    REFERENCES payroll_dimensions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_employment_dimension_dates
    CHECK (valid_to IS NULL OR valid_to >= valid_from),
  CONSTRAINT chk_payroll_employment_dimension_row_version CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_dimension_overlap_insert//

CREATE TRIGGER trg_payroll_dimension_overlap_insert
BEFORE INSERT ON payroll_dimensions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id
       AND d.dimension_type = NEW.dimension_type
       AND d.code = NEW.code
       AND d.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(d.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension code intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_dimension_overlap_update//

CREATE TRIGGER trg_payroll_dimension_overlap_update
BEFORE UPDATE ON payroll_dimensions
FOR EACH ROW
BEGIN
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension row version must increase';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id
       AND d.dimension_type = NEW.dimension_type
       AND d.code = NEW.code
       AND d.id <> NEW.id
       AND d.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(d.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension code intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_dimension_delete_guard//

CREATE TRIGGER trg_payroll_dimension_delete_guard
BEFORE DELETE ON payroll_dimensions
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_run_employments rune
        ON rune.supplier_id = ed.supplier_id
       AND rune.employment_id = ed.employment_id
      JOIN payroll_run_revisions rev
        ON rev.supplier_id = rune.supplier_id
       AND rev.id = rune.revision_id
       AND rev.status = 'approved'
      JOIN payroll_runs run
        ON run.supplier_id = rev.supplier_id
       AND run.id = rev.run_id
     WHERE ed.supplier_id = OLD.supplier_id
       AND ed.dimension_id = OLD.id
       AND ed.valid_from <= run.period_start
       AND COALESCE(ed.valid_to, '9999-12-31') >= run.period_start
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension used in an approved revision cannot be deleted';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_insert//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_insert
BEFORE INSERT ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_update//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_update
BEFORE UPDATE ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension row version must increase';
  END IF;

  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND ed.id <> NEW.id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

DELIMITER ;
