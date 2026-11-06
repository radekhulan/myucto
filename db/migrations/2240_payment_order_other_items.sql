-- MyÚčto.cz - ostatní závazky v platebních příkazech.
--
-- Položka příkazu může kromě přijaté faktury, vratky odběrateli a daně mířit i na
-- ostatní závazek (other_items, strana payable). `other_item_installment_id` je
-- splátka, kterou položka hradí; u závazku bez splátkového kalendáře je NULL a
-- položka hradí celý zbytek. Právě jeden cíl zůstává vyplněný (chk_poi_single_target),
-- splátka cílem není, jen ho upřesňuje.
--
-- Stav „v platebním příkazu" se u ostatních položek neukládá do vlastního sloupce,
-- odvozuje se z existence položky v nearchivovaném příkazu (stejně jako u daní).
-- Smazání příkazu jeho položky odstraní, takže se stav vrátí sám.
--
-- Ostatní položka se nikdy nemaže fyzicky (jen deleted_at), proto RESTRICT. Splátky se
-- při novém kalendáři mažou a zakládají znovu (OtherItemScheduleService), RESTRICT by
-- to zablokoval; položka nese vlastní snímek příjemce, VS i částky, vazba na splátku
-- je jen stopa a po smazání splátky se vynuluje. Sloupec s ON DELETE SET NULL nesmí
-- MariaDB použít v CHECK, vazbu splátky na položku proto hlídá PaymentOrderService.
--
-- Re-run safe: ADD COLUMN/INDEX IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS,
-- DROP CONSTRAINT IF EXISTS před novým CHECK.

SET NAMES utf8mb4;

ALTER TABLE payment_order_items
  ADD COLUMN IF NOT EXISTS other_item_id BIGINT UNSIGNED NULL AFTER tax_advance_schedule_id,
  ADD COLUMN IF NOT EXISTS other_item_installment_id BIGINT UNSIGNED NULL AFTER other_item_id,
  ADD INDEX IF NOT EXISTS idx_poi_other_item (other_item_id, other_item_installment_id),
  ADD INDEX IF NOT EXISTS idx_poi_other_installment (other_item_installment_id);

ALTER TABLE payment_order_items
  ADD CONSTRAINT fk_poi_other_item FOREIGN KEY IF NOT EXISTS (other_item_id)
    REFERENCES other_items(id) ON DELETE RESTRICT;

ALTER TABLE payment_order_items
  ADD CONSTRAINT fk_poi_other_installment FOREIGN KEY IF NOT EXISTS (other_item_installment_id)
    REFERENCES other_item_installments(id) ON DELETE SET NULL;

ALTER TABLE payment_order_items DROP CONSTRAINT IF EXISTS chk_poi_single_target;
ALTER TABLE payment_order_items
  ADD CONSTRAINT chk_poi_single_target CHECK (
    (purchase_invoice_id IS NOT NULL) + (invoice_id IS NOT NULL)
      + (tax_submission_id IS NOT NULL) + (tax_advance_schedule_id IS NOT NULL)
      + (other_item_id IS NOT NULL) = 1
  );
