-- MyÚčto.cz — adresná výjimka z append-only ledgeru pokusů o odeslání
--
-- Ledger `payroll_submission_transport_attempts` je append-only a hlídá to
-- trigger, ne jen konvence (migrace 1372). To je správně: ledger, který smí
-- zapomenout odeslání, není ledger, ale stavová proměnná.
--
-- Jenže po nepovedeném prvním odeslání (ČSSZ zprávu převezme a odmítne ji
-- protokolem — třeba proto, že certifikát není u OSSZ v registru podávajících)
-- zůstane v přehledu viset záznam, který NIC nedokládá, dál se doptává na
-- výsledek a účetní u něj vidí otevřenou transakci, přestože povinnost je
-- dávno podaná jinou cestou. Odklidit ho nešlo.
--
-- Skulina je proto ADRESNÁ, ne plošná: trigger pustí smazání jen tehdy, když
-- si o něj spojení řekne pro KONKRÉTNÍ řádek přes session proměnnou
-- `@payroll_transport_attempt_delete_allowed`. Nastavuje ji výhradně
-- PayrollSubmissionAttemptDeletionService, těsně před DELETE, a hned ji zase
-- uklidí. Cokoli jiného — omylem spuštěný DELETE, chybná migrace, ruční dotaz
-- v konzoli — narazí na tutéž hlášku jako dosud.
--
-- Co smazat NELZE, rozhoduje aplikace (pokus s protokolem, s dodejkou nebo
-- ten, jehož identifikátorem je podání u úřadu vedené) a zůstává po něm zápis
-- v auditním logu.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_transport_attempts_no_delete//
CREATE TRIGGER trg_payroll_transport_attempts_no_delete
BEFORE DELETE ON payroll_submission_transport_attempts
FOR EACH ROW
BEGIN
  IF @payroll_transport_attempt_delete_allowed IS NULL
     OR @payroll_transport_attempt_delete_allowed <> OLD.id
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'payroll_submission_transport_attempts are append-only';
  END IF;
END//

DELIMITER ;
