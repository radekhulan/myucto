-- MyÚčto.cz — Ú-16: úhrada mzdového závazku dostává vlastní zdroj účetního zápisu.
--
-- Spárování platby mzdového závazku (`payroll_payment_matches`) dosud
-- NEZAKLÁDALO žádný účetní zápis — v celém platebním modulu není jediné
-- `journal_entries`. Úhradu 336/221 tak musela účetní zaúčtovat ručně nebo ji
-- musela trefit bankovní pravidlo. Zůstatky 331/336/342/379 proto zůstávaly
-- napořád „otevřené" bez ohledu na to, že se závazek reálně zaplatil, a
-- reconciliace v2 to viděla jen jako rozdíl na ose `diff_journal_payments_minor`.
--
-- Protizápis NESMÍ jít pod `source_type = 'payroll'`:
--   * `uq_je_supplier_source (supplier_id, source_type, source_id)` (migrace
--     1007) drží u mzdy vazbu 1:1 na `payroll_run_revisions.id`, kterou by
--     úhrady rozbily,
--   * trigger `trg_journal_payroll_batch_insert` (migrace 1264) na každý zápis
--     se `source_type='payroll'` vyžaduje připravenou dávku schválené aktuální
--     revize, kterou úhrada nemá a mít nemůže,
--   * `PostingService` mzdové zápisy vědomě zamyká proti přepisu i stornu
--     (`payroll_rewrite_forbidden`, `payroll_reversal_forbidden`) — u úhrad je
--     naopak storno běžný jev (`payroll_payment_matches.event_kind='reversed'`).
--
-- Nová hodnota `payroll_payment` proto stojí vedle `payroll` a nese
-- `source_id = payroll_payment_matches.id`. Tím se idempotence protizápisu
-- nevymýšlí znovu: hlídá ji TÁŽ unikátnost `uq_je_supplier_source`, kterou už
-- používá zbytek účetnictví. Storno platby je vlastní řádek `payroll_payment_matches`
-- se záporným `amount_minor`, takže dostane vlastní zápis s obrácenými stranami —
-- žádný `reverse()` nad původním zápisem se nekoná.
--
-- IDEMPOTENCE: `MODIFY` na ENUM je deklarativní, opakovaný běh nastaví touž
-- definici. Žádná data se nemění.

SET NAMES utf8mb4;

-- `journal_entries` je systémově verzovaná (SYSTEM VERSIONING). ALTER nad takovou
-- tabulkou MariaDB odmítne chybou 4119, dokud se explicitně nepovolí přepsání
-- historických řádků. Stejný přepínač jako v migracích 1153/1261/1324.
SET @@system_versioning_alter_history = 1;

ALTER TABLE journal_entries
  MODIFY source_type ENUM(
    'invoice','purchase_invoice','bank','cash','asset','manual','closing','opening',
    'depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax',
    'profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual',
    'settlement','deferred_tax','payroll','vat_clearing','payroll_payment'
  ) NOT NULL DEFAULT 'manual';
