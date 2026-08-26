-- Vytěžitelný PDF přehled pro pojišťovny, které jej přijímají přes ISDS.
ALTER TABLE payroll_submission_artifacts
  MODIFY COLUMN IF EXISTS artifact_kind ENUM(
    'outbound_xml','outbound_pdf','outbound_zip','validation_protocol',
    'receipt_original','receipt_parsed','manual_attachment'
  ) NOT NULL;
