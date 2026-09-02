-- MyÚčto.cz — zahozená revize mzdového běhu má vlastní konečný stav.
--
-- ── Co bylo špatně ─────────────────────────────────────────────────────────
-- Běh má právě jednu živou revizi — tu, na kterou ukazuje
-- `payroll_runs.current_revision_no`. Každá starší revize, která se nikdy
-- neschválila, je mrtvá: `PayrollRunCommandService` sahá výhradně na aktuální
-- revizi, takže dokončit ji nejde a zrušit taky ne. Přesto zůstávala navždy
-- ve stavu `snapshot` / `calculated` / `reviewed`.
--
-- U opravné revize (`revision_kind = 'correction'`) to mělo tvrdý následek:
-- uzávěrka mzdového roku počítá otevřené korekce právě přes tyhle stavy
-- (`PayrollYearCloseRepository::openCorrectionCount()`), takže JEDNA zahozená
-- rozpracovaná korekce zablokovala uzávěrku roku NATRVALO — a z aplikace
-- z toho nevedla cesta ven.
--
-- ── Proč nový stav, a ne `superseded` ──────────────────────────────────────
-- `superseded` znamená „tohle KDYSI platilo a jede z toho účetnictví i JMHZ“ —
-- proto ho čte celá řada dotazů jako `status IN ('approved','superseded')`
-- (dokumenty, roční tiskopisy, exporty období). Zahozená revize neplatila
-- nikdy; kdyby dostala tentýž stav, začaly by z ní tyhle cesty generovat
-- doklady. Dostává proto vlastní konečný stav `abandoned` se stejnou stopou
-- (kdy a čím byla nahrazena), ale bez nároku na platnost.
--
-- Migrace povoluje JEDINÝ nový přechod: `snapshot|calculated|reviewed` →
-- `abandoned`, a to tak, že se u revize nesmí změnit nic jiného než stav
-- a stopa po odsunutí. Zbytek neměnnosti platí beze změny.

SET NAMES utf8mb4;

ALTER TABLE payroll_run_revisions
  MODIFY COLUMN status
    ENUM('snapshot','calculated','reviewed','approved','superseded','abandoned')
    NOT NULL;

-- MariaDB neumí `IF NOT EXISTS` u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_run_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_run_revision_superseded;

ALTER TABLE payroll_run_revisions
  ADD CONSTRAINT chk_payroll_run_revision_superseded CHECK (
    (status IN ('superseded','abandoned') AND superseded_at IS NOT NULL)
    OR (status NOT IN ('superseded','abandoned') AND superseded_at IS NULL
        AND superseded_by_revision_id IS NULL)
  );

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_revision_immutable_update
BEFORE UPDATE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  -- Odsunutá i zahozená revize je konečná: doklad o tom, co kdysi platilo
  -- (resp. co se zahodilo), se už nemění.
  IF OLD.status IN ('superseded','abandoned') THEN
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
  END IF;

  -- Nová revize nikdy nevzniká rovnou jako odsunutá a stopu po odsunutí
  -- nesmí nést nic, co odsunuté není.
  IF OLD.status <> 'approved' AND NEW.status = 'superseded' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Only an approved payroll run revision can be superseded';
  END IF;

  -- Zahodit lze JEN revizi, která se nikdy neschválila, a jen s doloženým
  -- nástupcem — jinak by z ní nebylo poznat, čím byla nahrazena.
  IF NEW.status = 'abandoned' THEN
    IF OLD.status NOT IN ('snapshot','calculated','reviewed') THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Only an unapproved payroll run revision can be abandoned';
    END IF;
    IF NEW.superseded_at IS NULL OR NEW.superseded_by_revision_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Abandoning a payroll run revision must stamp its successor';
    END IF;
  END IF;

  -- Odsunutí i zahození je JEN změna stavu. Kdyby se pod ním dal přepsat
  -- snapshot, hash nebo schvalovatel, byla by z povolené výjimky díra
  -- do neměnnosti.
  IF NEW.status IN ('superseded','abandoned') THEN
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
END//

DELIMITER ;
