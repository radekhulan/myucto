-- Převzaté mzdy: příspěvek zaměstnavatele na produkty spoření na stáří v převzatém měsíci.
--
-- PROČ: limit osvobození § 6 odst. 9 písm. p) ZDP (50 000 Kč ročně, koš
-- `old_age_savings`) se počítá za celý zdaňovací rok. Rok přechodu má část
-- měsíců spočítanou předchozím programem; bez jejich příspěvků by MyÚčto
-- v dalších měsících osvobodilo znovu celý limit. Sloupec nese součet příspěvků
-- na penzijní připojištění, doplňkové penzijní spoření, penzijní pojištění,
-- soukromé životní pojištění a dlouhodobý investiční produkt (JMHZ 10292 až
-- 10296, PAMICA `MZ2.KcPDP` a `MZ2.KcDIP`). NULL = zdroj údaj nenese.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS; CHECK MariaDB s IF NOT EXISTS neumí,
-- proto se nejdřív zahodí a přidá znovu.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  ADD COLUMN IF NOT EXISTS old_age_savings_contribution_minor BIGINT NULL
    COMMENT 'příspěvek zaměstnavatele na produkty spoření na stáří v haléřích (JMHZ 10292-10296); NULL = zdroj nevydal'
    AFTER uninsured_income_minor;

ALTER TABLE payroll_migration_reference_totals
  DROP CONSTRAINT IF EXISTS chk_pmrt_old_age_savings;
ALTER TABLE payroll_migration_reference_totals
  ADD CONSTRAINT chk_pmrt_old_age_savings CHECK (
    old_age_savings_contribution_minor IS NULL OR old_age_savings_contribution_minor >= 0
  );
