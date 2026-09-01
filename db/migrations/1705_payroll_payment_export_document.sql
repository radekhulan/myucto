-- Doklad hromadného příkazu vedle souboru pro banku.
--
-- Trigger dosud trval na tom, že formát exportu se rovná formátu dávky. Tím
-- ale k dávce nešlo uložit nic jiného než soubor pro banku, a tištěný doklad
-- příkazu je právě takový druhý artefakt nad TÝMŽ zmrazeným snapshotem.
-- Nově se vedle formátu dávky připouští `pdf`; ostatní formáty zůstávají
-- svázané s dávkou jako dřív, aby se do archivu nedal propašovat cizí soubor
-- pro banku. Revize se počítají per formát (uq_payroll_payment_export_revision),
-- takže obě větve mají vlastní řadu.
--
-- Migrace se pouští opakovaně, proto se trigger nejdřív zahazuje a pak zakládá.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_payment_export_validate_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_export_validate_insert
BEFORE INSERT ON payroll_payment_exports
FOR EACH ROW
BEGIN
  DECLARE batch_snapshot_hash CHAR(64) DEFAULT NULL;
  DECLARE batch_export_format VARCHAR(16) DEFAULT NULL;
  DECLARE previous_batch_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE previous_export_format VARCHAR(16) DEFAULT NULL;
  DECLARE previous_revision_no INT UNSIGNED DEFAULT NULL;

  SELECT batch.snapshot_hash, batch.export_format
    INTO batch_snapshot_hash, batch_export_format
    FROM payroll_payment_batches batch
   WHERE batch.supplier_id = NEW.supplier_id
     AND batch.id = NEW.batch_id;

  IF batch_snapshot_hash IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export batch does not belong to supplier';
  END IF;
  IF NEW.source_snapshot_hash <> batch_snapshot_hash THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export source snapshot differs from batch';
  END IF;
  IF NEW.export_format <> batch_export_format AND NEW.export_format <> 'pdf' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export format differs from batch';
  END IF;
  IF NEW.storage_key <> NEW.file_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export content address is invalid';
  END IF;

  IF NEW.export_revision_no = 1 THEN
    IF NEW.supersedes_export_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export first revision cannot supersede another export';
    END IF;
  ELSE
    IF NEW.supersedes_export_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision requires its predecessor';
    END IF;

    SELECT previous.batch_id,
           previous.export_format,
           previous.export_revision_no
      INTO previous_batch_id,
           previous_export_format,
           previous_revision_no
      FROM payroll_payment_exports previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.supersedes_export_id;

    IF previous_batch_id IS NULL
      OR previous_batch_id <> NEW.batch_id
      OR previous_export_format <> NEW.export_format
      OR previous_revision_no + 1 <> NEW.export_revision_no
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision chain is inconsistent';
    END IF;
  END IF;
END//

DELIMITER ;
