-- MyÚčto.cz — zdroj registrace mzdové účtárny přestává být povinný.
--
-- `source_reference` je odkaz na výměr, tedy poznámka do naší evidence. ČSSZ ho
-- nevyžaduje a všechny ostatní `source_reference` / `evidence_reference` ve
-- mzdové agendě jsou nepovinné. Kontrola v migraci 1595 přesto bránila uložit
-- variabilní symbol, který má účetní opsaný z papíru — a VS je jediné, co na
-- registraci opravdu záleží (drží ho vlastní CHECK, který zůstává).
--
-- Sloupec zůstává NOT NULL: prázdno se ukládá jako prázdný řetězec, takže
-- všechna dosavadní čtení (`(string) $row['source_reference']`) drží beze změny.

SET NAMES utf8mb4;

ALTER TABLE payroll_office_registration_versions
  DROP CONSTRAINT IF EXISTS chk_payroll_office_registration_source;

ALTER TABLE payroll_office_registration_versions
  MODIFY source_reference VARCHAR(500) NOT NULL DEFAULT '';
