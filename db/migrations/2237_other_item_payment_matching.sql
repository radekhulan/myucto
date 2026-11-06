-- MyÚčto.cz - Ostatní pohledávky a závazky: účet protistrany a úhrada splátky.
--
-- `counterparty_account` + `counterparty_bank_code` nesou účet, ze kterého protistrana
-- platí nebo na který se platí. Český účet je uložený kanonicky jako `předčíslí-číslo`
-- bez vodicích nul s kódem banky, zahraniční účet jako IBAN bez kódu banky
-- (OtherItemCounterpartyAccount). Opakování účet přenáší v template_json šablony,
-- vlastní sloupce šablona nepotřebuje.
--
-- `other_item_allocations.installment_id` je splátka, kterou úhrada hradí. Jedna platba
-- má k položce nejvýš jeden řádek (uq_other_item_bank / uq_other_item_cash), proto
-- úhrada vyšší než otevřená část své splátky pokryje přebytkem další otevřené splátky
-- podle pořadí (OtherItemInstallmentCoverage). Stávající úhrady doplní PHP backfill
-- (api/bin/backfill-other-item-installments.php, spouští ho auto-backfill
-- v migrate.php); bez něj se úhrada bez splátky rozpočítá podle pořadí splátek,
-- takže čísla předpovědi a stav splátek vychází stejně.
--
-- Smazání splátky (nový kalendář je povolený jen bez aktivní úhrady) vazbu u
-- stornované úhrady vynuluje; opětovné přiřazení ji dopočítá znovu.
--
-- Re-run safe: ADD COLUMN/INDEX IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE other_items
  ADD COLUMN IF NOT EXISTS counterparty_account VARCHAR(40) NULL AFTER variable_symbol,
  ADD COLUMN IF NOT EXISTS counterparty_bank_code VARCHAR(4) NULL AFTER counterparty_account,
  ADD INDEX IF NOT EXISTS idx_other_item_counterparty (supplier_id, counterparty_account);

ALTER TABLE other_item_allocations
  ADD COLUMN IF NOT EXISTS installment_id BIGINT UNSIGNED NULL AFTER other_item_id,
  ADD INDEX IF NOT EXISTS idx_other_allocation_installment (installment_id);

ALTER TABLE other_item_allocations
  ADD CONSTRAINT fk_other_allocation_installment FOREIGN KEY IF NOT EXISTS (installment_id)
    REFERENCES other_item_installments(id) ON DELETE SET NULL;
