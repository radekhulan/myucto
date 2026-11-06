SET NAMES utf8mb4;
-- Účetní odpis roku vyřazení, který vrácení vyřazení stornovalo, ale na který odkazuje
-- neměnná historie investičního zdroje (FK RESTRICT), se nemaže. Řádek se označí jako
-- zrušený a plán, přehledy i kontroly chronologie ho nevidí, dokud ho nové zaúčtování
-- roku nepřepíše.
ALTER TABLE depreciation_entries ADD COLUMN IF NOT EXISTS voided_at DATETIME NULL DEFAULT NULL AFTER status;
UPDATE depreciation_entries de
   SET de.voided_at = de.updated_at
 WHERE de.voided_at IS NULL
   AND de.kind = 'accounting'
   AND de.amount = 0
   AND de.full_amount = 0
   AND de.months_count IS NULL
   AND de.detail IS NULL
   AND EXISTS (SELECT 1 FROM nonprofit_capital_events e WHERE e.supplier_id = de.supplier_id AND e.depreciation_id = de.id)
   AND NOT EXISTS (
       SELECT 1 FROM journal_entries j
        WHERE j.supplier_id = de.supplier_id AND j.source_type = 'depreciation' AND j.source_id = de.id AND j.reversed_by IS NULL
   );
