-- Pravidla účtování bankovních pohybů: přednost před párováním s doklady a podmínka
-- platby kartou.
--
-- `skip_document_matching` = automatické párování pohyb, na který pravidlo sedí,
-- nepáruje s vystavenými ani přijatými doklady a pohyb zaúčtuje pravidlo. Typicky
-- faktoringová společnost nebo inkasní agentura, která platí s VS naší faktury.
-- Volba je dovolená jen u pravidla s účtem protistrany (hlídá validátor), ruční
-- spárování uživatelem zůstává možné.
--
-- `card_payment` = podmínka shody: NULL = jakýkoli pohyb, 1 = jen platba kartou,
-- 0 = jen pohyb bez karty. Platba kartou je pohyb s koncovkou karty (`card_last4`).
-- Podmínka „jen platba kartou" stačí jako jediné kritérium pravidla.
--
-- ⚠️ Výchozí hodnoty 0 a NULL zachovávají dosavadní chování všech pravidel.

ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS skip_document_matching TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = automatické párování s doklady pohyb vynechá, účtuje ho pravidlo'
    AFTER other_item_id,
  ADD COLUMN IF NOT EXISTS card_payment TINYINT(1) NULL DEFAULT NULL
    COMMENT 'NULL = jakýkoli pohyb, 1 = jen platba kartou, 0 = jen bez karty'
    AFTER skip_document_matching;

-- Idempotentně: CHECK nejdřív zahodit, pak založit s novým kritériem.
ALTER TABLE bank_posting_rules DROP CONSTRAINT IF EXISTS chk_bpr_criteria;
ALTER TABLE bank_posting_rules
  ADD CONSTRAINT chk_bpr_criteria CHECK (
    counterparty_account IS NOT NULL
    OR counterparty_bank IS NOT NULL
    OR counterparty_prefix IS NOT NULL
    OR variable_symbol IS NOT NULL
    OR message_contains IS NOT NULL
    OR card_payment = 1
  );
