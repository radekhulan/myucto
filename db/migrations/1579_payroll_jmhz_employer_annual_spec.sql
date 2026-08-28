-- Každá roční revize zaměstnavatele musí doložit, proti kterému oficiálnímu
-- balíku JMHZ byly ověřeny číselníkové hodnoty. Samotná schema_reference říká
-- jen tvar našeho snapshotu, nikoli obsah připnutých číselníků.

SET NAMES utf8mb4;

ALTER TABLE payroll_jmhz_employer_annual_evidence
  ADD COLUMN IF NOT EXISTS spec_manifest_sha256 CHAR(64) NULL
  AFTER schema_reference;

DROP TRIGGER IF EXISTS trg_payroll_jmhz_employer_annual_no_update;

UPDATE payroll_jmhz_employer_annual_evidence
   SET spec_manifest_sha256 =
       '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205'
 WHERE spec_manifest_sha256 IS NULL;

ALTER TABLE payroll_jmhz_employer_annual_evidence
  MODIFY COLUMN spec_manifest_sha256 CHAR(64) NOT NULL
  AFTER schema_reference;

ALTER TABLE payroll_jmhz_employer_annual_evidence
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_employer_annual_spec_hash;
ALTER TABLE payroll_jmhz_employer_annual_evidence
  ADD CONSTRAINT chk_payroll_jmhz_employer_annual_spec_hash
    CHECK (spec_manifest_sha256 REGEXP '^[0-9a-f]{64}$');

DELIMITER //

CREATE TRIGGER trg_payroll_jmhz_employer_annual_no_update
BEFORE UPDATE ON payroll_jmhz_employer_annual_evidence
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ employer annual evidence is immutable';
END//

DELIMITER ;
