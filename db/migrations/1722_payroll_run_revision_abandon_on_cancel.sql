-- MyÚčto.cz — zrušený mzdový běh smí zahodit i svou rozdělanou revizi.
--
-- ── Co bylo špatně ─────────────────────────────────────────────────────────
-- Migrace 1715 zavedla stav `abandoned` právě proto, že JEDNA zahozená
-- rozpracovaná OPRAVNÁ revize zablokovala uzávěrku mzdového roku natrvalo:
-- `PayrollYearCloseRepository::openCorrectionCount()` počítá revize
-- s `revision_kind = 'correction'` ve stavech `snapshot|calculated|reviewed`.
-- 1715 ale ošetřila jen jednu ze dvou cest, kterými taková revize vzniká —
-- tu, kde ji PŘEBIJE novější revize (`insertRevision` → `supersedeAbandoned…`).
--
-- Druhá cesta zůstala otevřená: ZRUŠENÍ běhu. `CANCEL` mění jen stav běhu
-- a živé revize se nedotkne. Účetní, která otevřela opravu (`REQUEST_CORRECTION`
-- → `REOPEN`), pak zjistila, že opravovat nebylo co, a běh zrušila, si tím
-- uzavřela rok — a to NATRVALO, protože zrušený běh už žádnou novou revizi
-- nezaloží (z `cancelled` vede jen `REOPEN`, který by problém odsunul o krok
-- dál, ne odstranil). Jediná cesta ven vedla ručním UPDATE v databázi.
--
-- ── Co se mění ─────────────────────────────────────────────────────────────
-- Zahození revize dosud VŽDY vyžadovalo doloženého nástupce
-- (`superseded_by_revision_id`). U zrušeného běhu ale žádný nástupce
-- neexistuje a existovat nemá — revize se nenahrazuje, celý běh se ruší.
-- Trigger proto nástupce nevyžaduje, pokud je běh ve stavu `cancelled`.
--
-- Co zůstává beze změny: zahodit jde pořád JEN revize, která se nikdy
-- neschválila (`snapshot|calculated|reviewed`), `superseded_at` se stampovat
-- musí vždy, schválená a odsunutá revize jsou dál neměnné a zahození je dál
-- POUZE změna stavu — pod ní se nesmí přepsat snapshot, hash ani schvalovatel.
-- Nástupce se smí vynechat jen tam, kde by byl lež.

SET NAMES utf8mb4;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_revision_immutable_update
BEFORE UPDATE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  DECLARE run_status VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

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

  IF NEW.status = 'abandoned' THEN
    IF OLD.status NOT IN ('snapshot','calculated','reviewed') THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Only an unapproved payroll run revision can be abandoned';
    END IF;
    IF NEW.superseded_at IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Abandoning a payroll run revision must stamp superseded_at';
    END IF;
    -- Nástupce je povinný jen tam, kde nějaký je. Zrušený běh revizi
    -- nenahrazuje, ruší ji spolu se sebou.
    IF NEW.superseded_by_revision_id IS NULL THEN
      SELECT status INTO run_status
        FROM payroll_runs
       WHERE supplier_id = NEW.supplier_id
         AND id = NEW.run_id;
      IF run_status IS NULL OR run_status <> 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Abandoning a payroll run revision must stamp its successor';
      END IF;
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
