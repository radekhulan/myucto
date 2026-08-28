-- MyÚčto.cz — MZ-14-W01: stručný důvod nové platební instrukce je součástí
-- neměnné právní historie, nikoli jen text ve formuláři.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_recipient_instructions
  ADD COLUMN IF NOT EXISTS change_reason VARCHAR(500) NULL
    AFTER source_document_sha256;
