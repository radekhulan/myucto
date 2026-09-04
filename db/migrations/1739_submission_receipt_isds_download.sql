-- Doručenka stažená aplikací z ISDS dostala vlastní způsob spárování.
--
-- PROČ
-- ---------------------------------------------------------------------------
-- `receipt_matched_by` znal tři hodnoty: `correlation_reference`,
-- `external_message_id` a `manual`. Doručenku, o kterou si aplikace sama řekne
-- ISDS operací `GetSignedDeliveryInfo` podle `external_message_id` z odchozí
-- fronty, nevystihuje ani jedna:
--   * `manual` je nepravda — soubor nikdo nevybíral a UI podle téhle hodnoty
--     píše „přiřadili jste ručně",
--   * `external_message_id` znamená „doručenku jsme dostali a spárovali ji
--     podle dmID", kdežto tady se nic nepárovalo: zeptali jsme se na dmID
--     konkrétního podání a dostali odpověď právě k němu.
--
-- Rozdíl je průkazní, ne kosmetický. U ručního nahrání ručí za vazbu člověk,
-- u staženého dokladu ISDS. Až se bude dohledávat, čím je podání doložené,
-- musí být poznat které z toho platí.
--
-- Podpis doručenky se ani u téhle cesty neověřuje — `receipt_signature_status`
-- zůstává `unverified`, viz docblock DeliveryReceiptService.
--
-- IDEMPOTENCE: `MODIFY COLUMN` nemá variantu `IF NOT EXISTS`, ale je
-- idempotentní svou povahou — opakované spuštění nastaví tutéž definici.
-- Rozšíření výčtu o hodnotu navíc žádný existující řádek nemění.

ALTER TABLE submission_outbox
  MODIFY COLUMN receipt_matched_by
    ENUM('correlation_reference','external_message_id','manual','isds_download') NULL
    COMMENT 'Jak vznikla vazba doručenky na podání; isds_download = vyžádala si ji aplikace z ISDS';
