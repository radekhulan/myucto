-- Přesný budoucí plán převzaté karty; skutečné odpisy zůstávají v depreciation_entries.
ALTER TABLE assets
  ADD COLUMN IF NOT EXISTS imported_depreciation_plan JSON NULL
  COMMENT 'Převzatý budoucí měsíční účetní a roční daňový plán, částky v haléřích';
