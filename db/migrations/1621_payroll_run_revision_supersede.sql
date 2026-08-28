-- MyÚčto.cz — W13 / P-12: schválená revize mzdového běhu smí být odsunuta.
--
-- Stav `superseded` byl v ENUM od 1210 a počítaly s ním repozitáře i triggery,
-- ale NIKDE se nenastavoval. Po opravné revizi tak zůstaly DVĚ revize ve stavu
-- `approved` a generátor dokumentů si mohl vybrat kteroukoli — zaměstnanec pak
-- dostal předkorekční výplatní pásku, přestože účetnictví i JMHZ už jely
-- z nové revize.
--
-- Migrace povoluje JEDINÝ nový přechod: `approved` → `superseded`, a to tak,
-- že se u revize nesmí změnit nic jiného než stav a stopa po odsunutí. Všechno
-- ostatní zůstává neměnné přesně jako dřív, včetně toho, že `superseded` je
-- konečný stav a mazat revize nejde vůbec.

SET NAMES utf8mb4;

ALTER TABLE payroll_run_revisions
  ADD COLUMN IF NOT EXISTS superseded_at DATETIME NULL AFTER approved_at;

ALTER TABLE payroll_run_revisions
  ADD COLUMN IF NOT EXISTS superseded_by_revision_id BIGINT UNSIGNED NULL
    AFTER superseded_at;

ALTER TABLE payroll_run_revisions
  DROP FOREIGN KEY IF EXISTS fk_payroll_run_revision_superseded_by;

ALTER TABLE payroll_run_revisions
  ADD CONSTRAINT fk_payroll_run_revision_superseded_by
    FOREIGN KEY (supplier_id, superseded_by_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT;

-- MariaDB neumí `IF NOT EXISTS` u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_run_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_run_revision_superseded;

ALTER TABLE payroll_run_revisions
  ADD CONSTRAINT chk_payroll_run_revision_superseded CHECK (
    (status = 'superseded' AND superseded_at IS NOT NULL)
    OR (status <> 'superseded' AND superseded_at IS NULL
        AND superseded_by_revision_id IS NULL)
  );

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_revision_immutable_update
BEFORE UPDATE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  -- Odsunutá revize je konečná: doklad o tom, co kdysi platilo, se už nemění.
  IF OLD.status = 'superseded' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Superseded payroll run revision is immutable';
  END IF;

  IF OLD.status = 'approved' THEN
    IF NEW.status <> 'superseded' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Approved payroll run revision is immutable';
    END IF;
    IF NEW.superseded_at IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Superseding a payroll run revision must stamp superseded_at';
    END IF;
    -- Odsunutí je JEN změna stavu. Kdyby se pod ním dal přepsat snapshot,
    -- hash nebo schvalovatel, byla by z povolené výjimky díra do neměnnosti.
    IF NOT (OLD.id <=> NEW.id)
      OR NOT (OLD.supplier_id <=> NEW.supplier_id)
      OR NOT (OLD.run_id <=> NEW.run_id)
      OR NOT (OLD.revision_no <=> NEW.revision_no)
      OR NOT (OLD.previous_revision_id <=> NEW.previous_revision_id)
      OR NOT (OLD.revision_kind <=> NEW.revision_kind)
      OR NOT (OLD.schema_version <=> NEW.schema_version)
      OR NOT (OLD.ruleset_manifest_hash <=> NEW.ruleset_manifest_hash)
      OR NOT (OLD.input_snapshot_json <=> NEW.input_snapshot_json)
      OR NOT (OLD.input_snapshot_hash <=> NEW.input_snapshot_hash)
      OR NOT (OLD.result_snapshot_json <=> NEW.result_snapshot_json)
      OR NOT (OLD.result_snapshot_hash <=> NEW.result_snapshot_hash)
      OR NOT (OLD.idempotency_key_hash <=> NEW.idempotency_key_hash)
      OR NOT (OLD.calculated_by <=> NEW.calculated_by)
      OR NOT (OLD.reviewed_by <=> NEW.reviewed_by)
      OR NOT (OLD.approved_by <=> NEW.approved_by)
      OR NOT (OLD.calculated_at <=> NEW.calculated_at)
      OR NOT (OLD.reviewed_at <=> NEW.reviewed_at)
      OR NOT (OLD.approved_at <=> NEW.approved_at)
      OR NOT (OLD.created_at <=> NEW.created_at)
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Superseding a payroll run revision must not change anything else';
    END IF;
  END IF;

  -- Nová revize nikdy nevzniká rovnou jako odsunutá a stopu po odsunutí
  -- nesmí nést nic, co odsunuté není.
  IF OLD.status <> 'approved' AND NEW.status = 'superseded' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Only an approved payroll run revision can be superseded';
  END IF;
END//

DELIMITER ;
