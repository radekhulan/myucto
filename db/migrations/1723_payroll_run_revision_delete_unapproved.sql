-- MyÚčto.cz — neměnnost revize mzdového běhu patří jen tomu, o co se něco opírá.
--
-- ── Co bylo špatně ─────────────────────────────────────────────────────────
-- `trg_payroll_run_revision_immutable_delete` (migrace 1220) odmítal KAŽDÝ
-- DELETE, bez ohledu na stav revize. Revizi přitom zakládá už první klik —
-- `lock_inputs` ({@see PayrollRunCommandService}) — tedy dřív, než se cokoliv
-- spočítá, zaúčtuje, vyplatí nebo podá. Od té chvíle byl běh nesmazatelný
-- natrvalo a `canDelete` to hlásil jako „už obsahuje neměnnou revizi".
--
-- Neměnnost má chránit to, co bylo PODÁNO nebo ZAÚČTOVÁNO. Rozdělaná práce
-- si ji nezaslouží — u ní je to jen past.
--
-- ── Co se mění ─────────────────────────────────────────────────────────────
-- Trigger dál chrání revize ve stavu `approved` a `superseded`: podle nich se
-- už mohlo počítat, účtovat i podávat a `superseded` navíc celá řada dotazů
-- čte jako „tohle kdysi platilo". Revize ve stavu `snapshot`, `calculated`,
-- `reviewed` a `abandoned` neplatila nikdy a smazat ji lze.
--
-- ── Proč je to bezpečné i bez triggeru ─────────────────────────────────────
-- Skutečnou obranou nejsou triggery, ale CIZÍ KLÍČE: na
-- `payroll_run_revisions` míří přes 30 FK a všechny mají `ON DELETE RESTRICT`
-- (výplatní pásky, účetní dávky, platební závazky, podání, JMHZ i ELDP
-- snapshoty, roční tiskopisy). Jakmile se o revizi cokoliv opře, DELETE
-- odmítne sama databáze — a to i u revize, kterou tento trigger propustí.
-- Plošný trigger tedy nechránil nic navíc; jen zavíral i legitimní zrušení
-- omylem založeného běhu. Stejný krok jako u migrace 1721 (registrace
-- účtárny), ze stejného důvodu.
--
-- POZOR: tato migrace neměnnost jen ZUŽUJE. Servisní vrstva
-- (`PayrollRunRepository::canDelete()`) rozhoduje dál sama a je striktnější —
-- rozvinutí mazání i na rozpracované revize je samostatná práce, protože
-- vyžaduje odemknout `payroll_inputs` a uklidit snapshot graf.

SET NAMES utf8mb4;

DELIMITER //

DROP TRIGGER IF EXISTS trg_payroll_run_revision_immutable_delete//

CREATE TRIGGER trg_payroll_run_revision_immutable_delete
BEFORE DELETE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  IF OLD.status IN ('approved', 'superseded') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Approved payroll run revisions are append-only';
  END IF;
END//

DELIMITER ;
