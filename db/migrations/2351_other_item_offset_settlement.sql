-- MyÚčto.cz - Ostatní pohledávky a závazky: vyrovnání zápočtem (zápisem v deníku).
--
-- Úhradu ostatní položky dosud nesla jen alokace bankovního pohybu nebo pokladního
-- dokladu. Zápočet (pojistné plnění započtené proti přijaté faktuře, vyrovnání přes 395,
-- 379, 321, 311) se proto do agendy nepromítl a položka zůstala otevřená, i když ji hlavní
-- kniha měla vyrovnanou.
--
-- Vyrovnání zápisem je třetí kanál úhrady vedle banky a pokladny:
--   - vazbu na řádek deníku nese rozpad řádku na doklad saldokonta
--     (`journal_settlement_allocations`, druh `settlement`, doc_type `other_item`), takže
--     řádek hlídá JournalSettlementUseGuard (přeúčtování ani smazání zápisu ho tiše nezmění)
--     a jeho volná částka se počítá společně s rozpady na faktury;
--   - úhradu položky nese `other_item_allocations` s `journal_allocation_id`, takže uhrazená
--     část, stav, splátky, měsíční kontrola, předpověď a kniha ji počítají stejně jako banku
--     a pokladnu.
-- Smazání rozpadu (zrušení přiřazení) smaže kaskádou i úhradu. Storno zápisu úhradu
-- stornuje (`reversed_on`, PostingService::reverse), saldokonto k dřívějšímu dni ji dál počítá.
--
-- `internal_document_id` = interní doklad zápočtu, který vyrovnání samo zaúčtovalo
-- (OtherItemOffsetService). Takové vyrovnání se ruší stornem interního dokladu; přiřazení
-- existujícího zápisu (bez vlastního dokladu) se ruší jen odebráním vazby.
--
-- Re-run safe: ADD COLUMN/INDEX IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS,
-- DROP/ADD CONSTRAINT IF [NOT] EXISTS.

SET NAMES utf8mb4;

ALTER TABLE other_item_allocations
  ADD COLUMN IF NOT EXISTS journal_allocation_id BIGINT UNSIGNED NULL AFTER cash_document_id,
  ADD COLUMN IF NOT EXISTS internal_document_id BIGINT UNSIGNED NULL AFTER journal_allocation_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_other_allocation_journal (journal_allocation_id),
  ADD INDEX IF NOT EXISTS idx_other_allocation_internal_doc (internal_document_id);

ALTER TABLE other_item_allocations
  ADD CONSTRAINT fk_other_allocation_journal FOREIGN KEY IF NOT EXISTS (journal_allocation_id)
    REFERENCES journal_settlement_allocations(id) ON DELETE CASCADE;

ALTER TABLE other_item_allocations
  ADD CONSTRAINT fk_other_allocation_internal_doc FOREIGN KEY IF NOT EXISTS (internal_document_id)
    REFERENCES internal_documents(id) ON DELETE SET NULL;

ALTER TABLE other_item_allocations DROP CONSTRAINT IF EXISTS chk_other_allocation_target;
ALTER TABLE other_item_allocations
  ADD CONSTRAINT IF NOT EXISTS chk_other_allocation_target CHECK (
    (bank_transaction_id IS NOT NULL) + (cash_document_id IS NOT NULL) + (journal_allocation_id IS NOT NULL) = 1
  );
