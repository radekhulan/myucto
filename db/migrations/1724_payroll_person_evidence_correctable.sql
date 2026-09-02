-- MyÚčto.cz — evidence osoby musí jít opravit.
--
-- Dvě neměnnosti v evidenci osoby chránily i to, o co se ještě nic neopírá,
-- a nevedla z nich cesta ven:
--
--  1. `payroll_person_foreign_permits` mělo BEZPODMÍNEČNÝ zákaz UPDATE i DELETE.
--     Formulář přitom nabízí jako výchozí účinnost DNEŠEK, takže překlep v čísle
--     oprávnění, ve státu vydání nebo v datu platnosti byl trvalý: opravit
--     nešlo, smazat nešlo, a „obnovení" (nový řádek) vyžaduje POZDĚJŠÍ začátek,
--     takže se špatně zadaná účinnost nedala vrátit dozadu. Řetěz oprávnění
--     přitom chrání cizí klíč `fk_payroll_foreign_permit_predecessor` (RESTRICT):
--     na co už navazuje obnovení, to se smazat nedá ani bez triggeru.
--     Nahrazujeme proto blanketní zákaz kontrolou, která platí pro UPDATE
--     stejná jako pro INSERT (doklad, řetěz, překryv), a mazání necháváme na
--     cizím klíči a na aplikaci.
--
--  2. `payroll_person_health_coverage_history` zamykalo připojený DMS důkaz
--     napořád („immutable once attached“). Připojený špatný sken se pak nedal
--     vyměnit a řádek evidence pojistné příslušnosti nešel opravit vůbec.
--     Věcná ochrana je jinde a je správná: řádek, který spadá do období
--     uzavřeného SCHVÁLENOU mzdou, se v aplikaci nepřepisuje, ale uzavírá se
--     a nová právní skutečnost vzniká jako nový řádek. Trigger proto nadále
--     hlídá jen to, co skutečně hlídat musí — že důkaz je aktivní dokument
--     TÉTO firmy se sedícím otiskem.
--
-- MariaDB neumí `CREATE TRIGGER IF NOT EXISTS` idempotentně vůči změně těla,
-- takže se každý trigger nejdřív zahazuje.

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_payroll_foreign_permit_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_foreign_permit_append_only_delete;
DROP TRIGGER IF EXISTS trg_payroll_foreign_permit_correct_update;
DROP TRIGGER IF EXISTS trg_pp_health_coverage_evidence_update;

DELIMITER //

CREATE TRIGGER trg_payroll_foreign_permit_correct_update
BEFORE UPDATE ON payroll_person_foreign_permits
FOR EACH ROW
BEGIN
  IF NOT (NEW.supplier_id <=> OLD.supplier_id)
     OR NOT (NEW.employee_id <=> OLD.employee_id) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit ownership is immutable';
  END IF;

  IF NOT EXISTS (
    SELECT 1
      FROM documents document
     WHERE document.id = NEW.document_id
       AND document.supplier_id = NEW.document_supplier_id
       AND document.deleted_at IS NULL
       AND document.scope = 'company'
       AND document.sha256 = NEW.document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit document mismatch';
  END IF;

  IF NEW.supersedes_permit_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_person_foreign_permits predecessor
     WHERE predecessor.id = NEW.supersedes_permit_id
       AND predecessor.supplier_id = NEW.supplier_id
       AND predecessor.employee_id = NEW.employee_id
       AND predecessor.permit_kind = NEW.permit_kind
       AND NEW.effective_from > predecessor.effective_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit predecessor mismatch';
  END IF;

  -- Překryv se posuzuje stejně jako při vložení, jen se z něj vyjímá sám
  -- opravovaný řádek a OBA jeho konce řetězu obnovení: ten, který nahrazuje,
  -- i ten, který nahrazuje jeho. Bez druhé strany by se předchůdce nedal
  -- opravit, jakmile na něj navázalo obnovení — a to je právě ten řádek,
  -- u kterého se překlep v datu obvykle objeví.
  IF EXISTS (
    SELECT 1
      FROM payroll_person_foreign_permits existing
     WHERE existing.supplier_id = NEW.supplier_id
       AND existing.employee_id = NEW.employee_id
       AND existing.permit_kind = NEW.permit_kind
       AND existing.id <> NEW.id
       AND existing.effective_from <= NEW.valid_until
       AND existing.valid_until >= NEW.effective_from
       AND (NEW.supersedes_permit_id IS NULL OR existing.id <> NEW.supersedes_permit_id)
       AND (existing.supersedes_permit_id IS NULL OR existing.supersedes_permit_id <> NEW.id)
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll foreign permit overlap requires predecessor';
  END IF;
END//

CREATE TRIGGER trg_pp_health_coverage_evidence_update
BEFORE UPDATE ON payroll_person_health_coverage_history
FOR EACH ROW
BEGIN
  IF (NEW.health_evidence_document_id IS NULL) <> (NEW.health_evidence_document_sha256 IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence document and SHA-256 must be recorded together';
  END IF;
  IF NEW.health_evidence_document_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM documents document
     WHERE document.id = NEW.health_evidence_document_id
       AND document.supplier_id = NEW.supplier_id
       AND document.deleted_at IS NULL
       AND document.sha256 = NEW.health_evidence_document_sha256
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Health evidence requires an active tenant DMS document with matching SHA-256';
  END IF;
END//

DELIMITER ;
