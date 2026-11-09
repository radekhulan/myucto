-- MyÚčto.cz — zaúčtování už na řádku faktury: protiúčet a předkontace položky.
--
-- Řádek přijaté faktury nesl jen účet nákladu (`expense_account_code`, strana MD), řádek
-- vydané faktury jen výnosový účet (`revenue_account_code`, strana Dal). Druhou stranu
-- (závazek 321 / pohledávku 311) určovala vždy předkontace dokladu. Nově jde na řádku
-- zadat i ji, takže řádek nese úplnou dvojici účet MD + účet Dal:
--
--   přijatá faktura: MD = expense_account_code,  Dal = counter_account_code
--   vydaná faktura:  MD = counter_account_code,  Dal = revenue_account_code
--
-- `posting_rule_key` je předkontace řádku (posting_rules.rule_key). Účty, které řádek
-- nemá vyplněné, se doplní z ní; vyplněný účet má přednost.
--
-- NULL ve všech nových sloupcích = dosavadní chování beze změny. FK záměrně není ze
-- stejného důvodu jako u `expense_account_code` (1095): položky nemají `supplier_id`,
-- platnost účtu i předkontace vynucuje PostingService při zaúčtování.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS counter_account_code VARCHAR(10) NULL
      COMMENT 'protiúčet řádku (Dal, závazek); NULL = z předkontace řádku nebo dokladu'
      AFTER expense_account_code,
  ADD COLUMN IF NOT EXISTS posting_rule_key VARCHAR(64) NULL
      COMMENT 'předkontace řádku (posting_rules.rule_key); NULL = bez předkontace řádku'
      AFTER counter_account_code;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS counter_account_code VARCHAR(10) NULL
      COMMENT 'protiúčet řádku (MD, pohledávka); NULL = z předkontace řádku nebo dokladu'
      AFTER revenue_account_code,
  ADD COLUMN IF NOT EXISTS posting_rule_key VARCHAR(64) NULL
      COMMENT 'předkontace řádku (posting_rules.rule_key); NULL = bez předkontace řádku'
      AFTER counter_account_code;
