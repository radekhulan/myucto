-- MyÚčto.cz — mzdový list z RUČNÍ rekapitulace jde odložit, když měsíc přebírá modul.
--
-- ── Co bylo špatně ─────────────────────────────────────────────────────────
-- Firma, která začala mzdy zpracovávat ruční rekapitulací a pak si zapnula
-- modul Mzdy, nemohla starší měsíce do modulu převést. Rezervaci období
-- (`payroll_period_ownership.processor = 'legacy'`) uvolní
-- `PayrollPeriodOwnershipService::releaseLegacy()`, jenže ta je fail-closed
-- na DVĚ věci: aktivní účetní zápis rekapitulace a řádek ve mzdovém listu
-- (`payroll_monthly_records`).
--
-- Zápis šlo stornovat z aplikace (`POST /accounting/journal/{id}/reverse`).
-- Řádek mzdového listu ale NEŠLO odstranit nijak — tabulka má jen `upsert()`
-- a žádnou mazací ani odkládací cestu. Jediné, co zbývalo, byl ruční DELETE
-- v databázi. To není cesta, kterou lze dát uživateli.
--
-- ── Proč odložit, a ne smazat ──────────────────────────────────────────────
-- Mzdový list je evidence podle § 38j ZDP. Smazat ho znamená zahodit doklad
-- o tom, co plátce za měsíc skutečně srazil a odvedl — i když ten výpočet
-- mezitím nahradil modul. Řádek proto zůstává a jen se OZNAČÍ za odložený:
-- přestane se počítat do ročního mzdového listu, do kumulovaného základu
-- sociálního pojištění i do kontroly „už je za měsíc zaevidovaná hrubá mzda",
-- ale je dohledatelný i s důvodem a autorem.
--
-- Nové zaúčtování rekapitulace za týž měsíc odložení ZRUŠÍ (`upsert()`):
-- řádek zase platí, protože za ním zase stojí živý účetní zápis.

SET NAMES utf8mb4;

ALTER TABLE payroll_monthly_records
  ADD COLUMN IF NOT EXISTS retired_at DATETIME NULL AFTER journal_entry_id;

ALTER TABLE payroll_monthly_records
  ADD COLUMN IF NOT EXISTS retired_by BIGINT UNSIGNED NULL AFTER retired_at;

ALTER TABLE payroll_monthly_records
  ADD COLUMN IF NOT EXISTS retired_reason VARCHAR(500) NULL AFTER retired_by;

-- MariaDB neumí `IF NOT EXISTS` u CHECK, takže se nejdřív zahazuje.
ALTER TABLE payroll_monthly_records
  DROP CONSTRAINT IF EXISTS chk_payroll_monthly_record_retired;

ALTER TABLE payroll_monthly_records
  ADD CONSTRAINT chk_payroll_monthly_record_retired CHECK (
    (retired_at IS NULL AND retired_reason IS NULL)
    OR (retired_at IS NOT NULL AND retired_reason IS NOT NULL)
  );

-- Odložení bez důvodu by z evidence udělalo tichou díru; důvod je povinný
-- a proto se index staví nad dvojici, kterou čtou všechny filtrované dotazy.
CREATE INDEX IF NOT EXISTS idx_pmr_active_period
  ON payroll_monthly_records (supplier_id, year, month, retired_at);
