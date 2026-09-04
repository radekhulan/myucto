-- Návrat podání k odeslání smí correlation VYNULOVAT.
--
-- `trg_payroll_submission_correlation_update` (migrace 1279) zakazuje jakoukoli
-- změnu už vyplněné correlation. Dokud stavový automat chodil jen dopředu, byla
-- to čistá auditní pojistka: correlation váže podání na to, co úřad potvrdil, a
-- přepsat ji jinou hodnotou by ten vztah zfalšovalo.
--
-- Návrat uvízlého podání na `ready` ale žádné přepsání není — je to smazání
-- stopy po pokusu, který se vědomě zahodil, aby šlo podat znovu. Bez něj skončí
-- druhé odeslání na tomhle triggeru a povinnost zůstane nepodatelná, tedy přesně
-- v tom slepém konci, kvůli kterému návrat vznikl.
--
-- Uvolňuje se proto jediný případ, a to spolu se stavem: correlation smí zmizet
-- (`NEW.correlation_reference IS NULL`) jen když se řádek zároveň vrací do
-- předodeslaného stavu — do stejné čtveřice, kterou jmenuje
-- `chk_payroll_submissions_dates`. Přepis na JINOU hodnotu zůstává zakázaný
-- stejně jako dřív.
--
-- O důkaz se tím nepřichází: correlation každého pokusu má vlastní kopii
-- v `payroll_submission_transport_attempts`, což je append-only ledger. Historie
-- pokusu zůstává i po zahození, jen přestane blokovat další odeslání.
--
-- CREATE TRIGGER IF NOT EXISTS by starou verzi triggeru nechalo být, proto se
-- nejdřív zahazuje (idempotentní i při opakovaném spuštění).

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_submission_correlation_update//

CREATE TRIGGER trg_payroll_submission_correlation_update
BEFORE UPDATE ON payroll_submissions
FOR EACH ROW
BEGIN
  IF OLD.correlation_reference IS NOT NULL
     AND NOT (NEW.correlation_reference <=> OLD.correlation_reference)
     AND NOT (
       NEW.correlation_reference IS NULL
       AND NEW.status IN ('draft', 'validated', 'prepared', 'ready')
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll submission correlation is immutable';
  END IF;
END//

DELIMITER ;
