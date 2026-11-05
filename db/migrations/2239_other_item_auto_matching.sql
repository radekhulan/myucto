-- MyÚčto.cz - Ostatní pohledávky a závazky: automatické párování s bankou.
--
-- `supplier.auto_match_other_items` zapíná automatické párování bankovních pohybů
-- s ostatními pohledávkami a závazky (OtherItemAutoMatcher). Výchozí 0: dosavadní
-- firmy párují beze změny, firma si automatiku zapne v Nastavení.
--
-- `bank_posting_rules.other_item_id` je položka, které pravidlo pohyb přiřadí. Pravidlo
-- pohyb zaúčtuje na účet položky a úhradu k ní přiřadí jedním krokem. Smazaný koncept
-- položky vazbu vynuluje, pravidlo pak pohyb jen zaúčtuje.
--
-- `other_item_allocations.auto_match_reason` nese kód shody automatického přiřazení
-- (např. `other_item_vs_account`, `bank_rule`), u ručního přiřazení zůstává NULL.
--
-- `other_item_auto_match_blocks` pamatuje dvojice pohyb a položka, jejichž přiřazení
-- uživatel zrušil. Automatika je znovu nepřiřadí ani při Přepárovat výpis.
--
-- Re-run safe: ADD COLUMN/INDEX IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS,
-- CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS auto_match_other_items TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'automatické párování bankovních pohybů s ostatními pohledávkami a závazky';

ALTER TABLE bank_posting_rules
  ADD COLUMN IF NOT EXISTS other_item_id BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'ostatní položka, které pravidlo pohyb přiřadí',
  ADD INDEX IF NOT EXISTS idx_bpr_other_item (other_item_id);

ALTER TABLE bank_posting_rules
  ADD CONSTRAINT fk_bpr_other_item FOREIGN KEY IF NOT EXISTS (other_item_id)
    REFERENCES other_items(id) ON DELETE SET NULL;

ALTER TABLE other_item_allocations
  ADD COLUMN IF NOT EXISTS auto_match_reason VARCHAR(40) NULL DEFAULT NULL AFTER payment_on;

CREATE TABLE IF NOT EXISTS other_item_auto_match_blocks (
  supplier_id INT UNSIGNED NOT NULL,
  bank_transaction_id BIGINT UNSIGNED NOT NULL,
  other_item_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (bank_transaction_id, other_item_id),
  KEY idx_other_auto_block_supplier (supplier_id, bank_transaction_id),
  KEY idx_other_auto_block_item (other_item_id),
  CONSTRAINT fk_other_auto_block_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_auto_block_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_auto_block_item FOREIGN KEY (other_item_id) REFERENCES other_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
