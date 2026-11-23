-- Přihlášky k podání (A1) hledají u všech vztahů firmy jedním dotazem, jestli
-- už mají živou přihlášku. Část podání se k vztahu váže přes subject_reference;
-- bez indexu by dotaz procházel všechny součásti podání firmy včetně měsíčních
-- hlášení. Stejnou cestou jde i dotaz karty vztahu na živou přihlášku.

ALTER TABLE payroll_submission_parts
  ADD INDEX IF NOT EXISTS idx_payroll_submission_parts_subject
    (supplier_id, environment, subject_reference, agenda_code);
