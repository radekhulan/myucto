-- MyÚčto.cz — W25 / C-16: obrana do hloubky nad `payroll_runs`.
--
-- PROČ
--
-- Aplikační cesta (`PayrollRunCommandService` → `PayrollRunRepository::updateRun`)
-- je hlídaná: běh se zamkne `FOR UPDATE`, ověří se `row_version` a stav se mění
-- jen podle workflow. V DATABÁZI ale nebylo nic — a přitom `payroll_runs` je
-- KOŘEN celého mzdového stromu a jeden UPDATE odsud se propaguje dál:
-- `trg_payroll_run_result_period_propagate` (migrace 1593) při změně
-- `period_start` PŘEPÍŠE období všem výsledkům běhu, tedy i těm, které patří
-- schválené a jinak neměnné revizi (1621). Ruční `UPDATE payroll_runs SET
-- period_start = …` tak umí posunout do jiného měsíce mzdy, které už jsou
-- schválené, zaúčtované a vyplacené — a všechna ostatní neměnnost to mlčky
-- pustí, protože formálně jde o změnu odvozeného sloupce, ne o přepis částky.
--
-- CO GUARD DĚLÁ
--
-- 1. Identita běhu je neměnná: `id`, `supplier_id`, `office_id`, `created_by`,
--    `created_at`. Přesunout běh pod jinou firmu nebo provozovnu není oprava,
--    je to jiný běh.
-- 2. `row_version` nesmí klesnout. Optimistický zámek stojí na tom, že verze
--    roste; kdo ji sníží, umí zneviditelnit cizí souběžnou změnu.
-- 3. `current_revision_no` nesmí klesnout. Revize se jen přidávají (1621
--    nedovolí ani přepis, ani smazání schválené), takže pokles ukazatele znamená,
--    že `currentRevision()` začne vracet starší revizi než tu, která je platná.
-- 4. `period_start` a `payment_date` se ZMRAZÍ ve chvíli, kdy má běh alespoň
--    jednu schválenou (nebo už nahrazenou) revizi, nebo když k němu existuje
--    účetní dávka. Do té doby jde o dosud editovatelný běh a měnit období se smí
--    — proto propagace z 1593 dál funguje tam, kde má.
--
-- CO GUARD VĚDOMĚ NEDĚLÁ
--
-- Nekopíruje workflow přechodů stavů do SQL. Matice povolených příkazů žije
-- v `PayrollRunWorkflow` a její druhá, ručně udržovaná kopie v triggeru by se
-- dřív nebo později rozešla — a rozešlá obrana je horší než žádná, protože se jí
-- věří. Trigger hlídá jen ta tvrzení, která platí nezávisle na tom, jaký přechod
-- se zrovna provádí.
--
-- Nehlídá ani DELETE: mazání běhu je podporovaná operace s vlastním rozhodovacím
-- pravidlem (`PayrollRunRepository::canDelete`) a FK `ON DELETE RESTRICT` ze
-- schválených revizí a plateb ho drží zdola. Zamknout ho tady natvrdo by zavřelo
-- legitimní zrušení omylem založeného běhu.

SET NAMES utf8mb4;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_payroll_run_update_guard
BEFORE UPDATE ON payroll_runs
FOR EACH ROW
BEGIN
  DECLARE frozen_revisions INT DEFAULT 0;
  DECLARE posting_batches INT DEFAULT 0;

  IF NOT (
    NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.office_id <=> OLD.office_id
    AND NEW.created_by <=> OLD.created_by
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run identity is immutable';
  END IF;

  IF NEW.row_version < OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run row_version must not go backwards';
  END IF;

  IF NEW.current_revision_no < OLD.current_revision_no THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run revision pointer must not go backwards';
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
