-- MyÚčto.cz — náhrada mzdy za dovolenou (§ 222 odst. 1 ZP) jako vlastní mzdový vstup.
--
-- Dovolená se dosud vedla jen v knize dovolené (`payroll_leave_ledger`), tedy
-- v hodinách nároku a čerpání. Peníze za ni nevznikaly vůbec: na výplatní pásce
-- nebyl řádek s hodinami, sazbou ani částkou, protože žádný mzdový vstup pro ni
-- neexistoval. Náhrada přitom náleží ve výši průměrného výdělku a § 142 odst. 5
-- ZP chce doklad o jednotlivých složkách mzdy.
--
-- Vstup nese stopu výpočtu (hodiny, průměrný hodinový výdělek, použitý snapshot
-- průměru), aby šlo doložit, jak částka vznikla. Integritní kontrola snapshotu
-- to ale dosud dovolovala jen u pravidelných složek, pracovních cest a rychlého
-- měsíčního vstupu, takže se rozšiřuje o `absence` s předponou `leave:`.
--
-- MariaDB neumí u CHECK `IF NOT EXISTS`, proto se nejdřív zahodí a pak přidá —
-- díky tomu je migrace opakovatelná.

SET NAMES utf8mb4;

ALTER TABLE payroll_inputs
  DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;

ALTER TABLE payroll_inputs
  ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
    (
      recurring_component_id IS NOT NULL
      AND source_kind = 'recurring'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
      AND OCTET_LENGTH(source_snapshot_hash) = 32
    )
    OR
    (
      recurring_component_id IS NULL
      AND source_kind = 'travel'
      AND external_id LIKE 'travel:%'
      AND source_snapshot_json IS NOT NULL
      AND JSON_VALID(source_snapshot_json)
      AND source_snapshot_hash IS NOT NULL
      AND OCTET_LENGTH(source_snapshot_hash) = 32
    )
    OR
    (
      recurring_component_id IS NULL
      AND source_kind NOT IN ('recurring', 'travel')
      AND (
        (
          source_snapshot_json IS NULL
          AND source_snapshot_hash IS NULL
        )
        OR
        (
          source_kind = 'manual'
          AND external_id LIKE 'quick-monthly:%'
          AND source_snapshot_json IS NOT NULL
          AND JSON_VALID(source_snapshot_json)
          AND source_snapshot_hash IS NOT NULL
          AND OCTET_LENGTH(source_snapshot_hash) = 32
        )
        OR
        (
          source_kind IN ('absence', 'correction')
          AND external_id LIKE 'leave:%'
          AND source_snapshot_json IS NOT NULL
          AND JSON_VALID(source_snapshot_json)
          AND source_snapshot_hash IS NOT NULL
          AND OCTET_LENGTH(source_snapshot_hash) = 32
        )
      )
    )
  );
