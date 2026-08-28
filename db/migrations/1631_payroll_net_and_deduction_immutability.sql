-- MyÚčto.cz — W25 / P-13: databázová neměnnost tří mzdových tabulek, které ji
-- jako jediné z modulu neměly.
--
-- PROČ
--
-- `payroll_payment_*` (migrace 1269), `payroll_posting_*` (1262), mzdový deník
-- (1264) i revize běhu (1621) mají guard v DATABÁZI: aplikační cesta sice zapisuje
-- správně, ale backfill, import, ruční UPDATE v konzoli ani chyba v budoucím kódu
-- se aplikační cestou neptají. `payroll_deduction_ledger`, `payroll_net_results`
-- a `payroll_payout_allocations` guard neměly, přestože nesou přesně tytéž peníze:
-- sražené částky, čistou mzdu a rozpis, kam se má vyplatit.
--
-- CO PŘESNĚ JE NEMĚNNÉ
--
-- Ověřeno proti zapisovatelům, ne odhadnuto. Jediným zapisovatelem všech tří tabulek
-- je `PayrollNetRepository` a dělá do nich VÝHRADNĚ INSERT:
--   * `appendLedgerMovement()` — append-only ledger; oprava se zapisuje jako
--     protipohyb (`reversed` / `payment_reversed` se záporná částkou a odkazem
--     `source_ledger_id`), původní řádek se nikdy nepřepisuje ani nemaže.
--   * `saveCalculation()` — jeden výsledek na dvojici (revize, zaměstnanec);
--     při opakovaném volání se POROVNÁ `result_hash` a buď se vrátí existující id,
--     nebo se vyhodí výjimka. Přepis se nekoná ani tady.
-- Přepočet ani reopen nic nemažou: vzniká NOVÁ revize (`revision_no + 1`,
-- `previous_revision_id` na schválený základ) a řádky staré revize zůstávají.
-- Všechny FK jsou `ON DELETE RESTRICT`, takže mazání zdola nahoru už zamčené je —
-- chybělo mazání a přepis samotného řádku.
--
-- Guard proto může být u ledgeru i u alokací výplaty ÚPLNÝ: žádný UPDATE, žádný
-- DELETE. Nezavírá to žádnou legitimní cestu, protože žádná neexistuje.
--
-- PROČ JE `payroll_net_results` VÝJIMKA (a užší guard)
--
-- Do `payroll_net_results` JEDEN legitimní UPDATE vede, a není z aplikace:
-- trigger `trg_payroll_run_result_period_propagate` (migrace 1593) při změně
-- `payroll_runs.period_start` přepíše odvozený sloupec `period_start` i tady.
-- Slepý zákaz UPDATE by tuhle propagaci rozbil a s ní i změnu období dosud
-- editovatelného běhu.
--
-- Guard je proto úzký: jediný povolený UPDATE je ten, který mění POUZE
-- `period_start`. Změní-li se cokoliv jiného — jakákoliv částka, `result_json`,
-- `result_hash`, `revision_id`, `employee_id` — zápis se odmítne.
--
-- Guard je SAMOSTATNÝ trigger, ne přepis `trg_payroll_net_result_period_update`
-- z migrace 1592. MariaDB od 10.2 zvládne víc triggerů téhož typu na tabulce a
-- pořadí je dané pořadím vzniku, takže kanonizace období běží první a guard po ní.
-- Kdyby se guard vlepil do triggeru 1592, opětovné spuštění 1592 (idempotence
-- migrací, `--only=` v runtime testu) by ho tiše odstranilo a nikdo by se to
-- nedozvěděl — zůstal by jen ten původní, slabší.
--
-- Že se tímhle oknem nedá posunout období SCHVÁLENÉ revize, řeší migrace 1632
-- na straně rodiče (`payroll_runs`). Tady se jen nesmí zavřít cesta, kterou
-- 1593 potřebuje.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_deduction_ledger_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_deduction_ledger_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payout_allocation_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payout_allocation_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_net_result_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_net_result_immutable_update;

DELIMITER //

-- Append-only ledger srážek: oprava je protipohyb, ne přepis.
CREATE TRIGGER trg_payroll_deduction_ledger_immutable_update
BEFORE UPDATE ON payroll_deduction_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll deduction ledger entries are immutable';
END//

CREATE TRIGGER trg_payroll_deduction_ledger_immutable_delete
BEFORE DELETE ON payroll_deduction_ledger
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll deduction ledger is append-only';
END//

-- Rozpis výplaty patří k jednomu konkrétnímu výsledku čisté mzdy. Jiný rozpis
-- znamená jiný výsledek, a ten vzniká novou revizí, ne přepsáním starého.
CREATE TRIGGER trg_payroll_payout_allocation_immutable_update
BEFORE UPDATE ON payroll_payout_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payout allocations are immutable';
END//

CREATE TRIGGER trg_payroll_payout_allocation_immutable_delete
BEFORE DELETE ON payroll_payout_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payout allocations are append-only';
END//

CREATE TRIGGER trg_payroll_net_result_immutable_delete
BEFORE DELETE ON payroll_net_results
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll net results are append-only';
END//

-- Doplňuje (nenahrazuje) `trg_payroll_net_result_period_update` z migrace 1592.
-- Jediný povolený UPDATE je propagace odvozeného `period_start` z rodičovského
-- běhu; jakákoliv jiná změna je přepis uzavřeného výsledku.
CREATE TRIGGER trg_payroll_net_result_immutable_update
BEFORE UPDATE ON payroll_net_results
FOR EACH ROW
BEGIN
  IF NOT (
    NEW.id <=> OLD.id
    AND NEW.supplier_id <=> OLD.supplier_id
    AND NEW.revision_id <=> OLD.revision_id
    AND NEW.employee_id <=> OLD.employee_id
    AND NEW.cash_income_minor <=> OLD.cash_income_minor
    AND NEW.non_cash_income_minor <=> OLD.non_cash_income_minor
    AND NEW.employee_social_minor <=> OLD.employee_social_minor
    AND NEW.employee_health_minor <=> OLD.employee_health_minor
    AND NEW.advance_tax_minor <=> OLD.advance_tax_minor
    AND NEW.withholding_tax_minor <=> OLD.withholding_tax_minor
    AND NEW.tax_bonus_minor <=> OLD.tax_bonus_minor
    AND NEW.correction_minor <=> OLD.correction_minor
    AND NEW.annual_settlement_minor <=> OLD.annual_settlement_minor
    AND NEW.deducted_minor <=> OLD.deducted_minor
    AND NEW.net_payable_minor <=> OLD.net_payable_minor
    AND NEW.result_json <=> OLD.result_json
    AND NEW.result_hash <=> OLD.result_hash
    AND NEW.created_at <=> OLD.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll net results are immutable except the derived period';
  END IF;
END//

DELIMITER ;
