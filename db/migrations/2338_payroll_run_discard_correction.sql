-- MyÚčto.cz — zahození opravy vrací mzdový běh na schválenou revizi.
--
-- ── Co bylo špatně ─────────────────────────────────────────────────────────
-- Z otevřené opravy schváleného běhu vedla jediná cesta zpět: zrušit celý běh.
-- Opravná revize se tím zahodila, běh ale zůstal ve stavu `cancelled` a měsíc
-- šlo dokončit jen další opravou. Účetní proto rušila a znovu otevírala běh
-- pokaždé, když v opravě něco zadala špatně, a každé znovuotevření založilo
-- další revizi.
--
-- Vrátit běh na schválenou revizi nešlo, protože guard z migrace 1632 (ve znění
-- 1853) nedovoluje snížit `current_revision_no`. Na ukazatel se přitom dívají
-- `PayrollRunRepository::currentRevision()`, souhrn běhu i triggery JMHZ
-- (1543, 1544): podklad hlášení musí být AKTUÁLNÍ schválená revize.
--
-- ── Co se mění ─────────────────────────────────────────────────────────────
-- Ukazatel smí klesnout v JEDINÉM případě: na schválenou revizi (`approved`)
-- téhož běhu, nad kterou jsou všechny revize zahozené (`abandoned`). Taková
-- revize je zároveň nejvyšší schválenou, protože nad ní žádná schválená ani
-- odsunutá revize není. Na odsunutou, rozpracovanou ani neexistující revizi
-- ukazatel klesnout nesmí, stejně jako nad živou revizí.
--
-- Zbytek guardu je beze změny (kopie z 1853): MariaDB trigger neumí doplnit,
-- jen nahradit, a druhý BEFORE UPDATE trigger nad týmž řádkem by rozdělil jedno
-- pravidlo do dvou souborů.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_run_update_guard//

CREATE TRIGGER trg_payroll_run_update_guard
BEFORE UPDATE ON payroll_runs
FOR EACH ROW
BEGIN
  DECLARE frozen_revisions INT DEFAULT 0;
  DECLARE posting_batches INT DEFAULT 0;
  DECLARE restored_revisions INT DEFAULT 0;
  DECLARE live_revisions_above INT DEFAULT 0;

  IF NOT (
    NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.office_id <=> OLD.office_id
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
    AND NEW.run_kind <=> OLD.run_kind
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run identity is immutable';
  END IF;

  IF NEW.row_version < OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run row_version must not go backwards';
  END IF;

  IF NEW.current_revision_no < OLD.current_revision_no THEN
    -- Jediná výjimka: návrat na schválenou revizi po zahození opravy.
    SELECT COUNT(*) INTO restored_revisions
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = OLD.supplier_id
       AND revision.run_id = OLD.id
       AND revision.revision_no = NEW.current_revision_no
       AND revision.status = 'approved';

    SELECT COUNT(*) INTO live_revisions_above
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = OLD.supplier_id
       AND revision.run_id = OLD.id
       AND revision.revision_no > NEW.current_revision_no
       AND revision.status <> 'abandoned';

    IF restored_revisions = 0 OR live_revisions_above > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll run revision pointer must not go backwards';
    END IF;
  END IF;

  IF NOT (NEW.period_start <=> OLD.period_start)
     OR NOT (NEW.payment_date <=> OLD.payment_date)
  THEN
    SELECT COUNT(*) INTO frozen_revisions
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = OLD.supplier_id
       AND revision.run_id = OLD.id
       AND revision.status IN ('approved', 'superseded');

    SELECT COUNT(*) INTO posting_batches
      FROM payroll_posting_batches batch
     WHERE batch.supplier_id = OLD.supplier_id
       AND batch.run_id = OLD.id;

    IF frozen_revisions > 0 OR posting_batches > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Approved payroll run period and payment date are frozen';
    END IF;
  END IF;
END//

DELIMITER ;
