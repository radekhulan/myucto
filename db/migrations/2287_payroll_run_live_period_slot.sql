-- MyÚčto.cz — zrušený převzatý běh neblokuje založení běžného běhu za totéž období.
--
-- PROČ
--
-- Běh je klíčovaný na `(firma, období, rozsah účtárny)`. Převzatý běh, který
-- účetní zahodila (`PayrollTakeoverRunService::discard()`), zůstává jako obal ve
-- stavu `cancelled` a drží tenhle klíč dál. Když se pak období má počítat
-- v MyÚčtu (posunutý začátek vedení mezd), `createOrGet()` vrátil právě zrušený
-- převzatý běh a založení skončilo hláškou, že převzatý běh se neřídí workflow
-- a má se zrušit — přestože zrušený už byl. Cesta ven nevedla.
--
-- CO SE MĚNÍ
--
-- `period_slot` je 1 u každého živého běhu a NULL u zrušeného převzatého obalu.
-- Unikátní klíč období se rozšíří o tenhle sloupec; NULL se v unikátním klíči
-- neporovnává, takže zrušený převzatý obal vedle sebe snese nový běžný běh.
-- Pro všechny ostatní běhy platí jeden běh za období a účtárnu jako dřív.
--
-- Obnovit zrušený převzatý obal (`reviveRun`) jde dál jen tehdy, když za
-- období žádný živý běh není — jinak ho klíč odmítne, a `build()` to hlídá už
-- předem hláškou o běžném běhu.

SET NAMES utf8mb4;

ALTER TABLE payroll_runs
  ADD COLUMN IF NOT EXISTS period_slot TINYINT UNSIGNED
    GENERATED ALWAYS AS (IF(run_kind = 'takeover' AND status = 'cancelled', NULL, 1)) STORED
    AFTER run_kind;

ALTER TABLE payroll_runs
  ADD UNIQUE KEY IF NOT EXISTS uq_payroll_run_live_period
    (supplier_id, period_start, office_scope_id, period_slot);

ALTER TABLE payroll_runs
  DROP INDEX IF EXISTS uq_payroll_run_period;
