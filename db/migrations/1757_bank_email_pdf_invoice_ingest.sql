-- MyÚčto.cz — načítání PDF faktur z příloh e-mailů vedle bankovních avíz
--
-- Schránka, do které chodí bankovní avíza, bývá zároveň schránkou, kam dodavatelé
-- posílají faktury. Opt-in per IMAP účet: když je `ingest_pdf_invoices` zapnuté,
-- skener vedle parsování avíza projde i přílohy a ty, které projdou rozpoznáním
-- (typ dokladu + identita naší firmy), založí jako podání ve frontě
-- Nákup → Příchozí doklady. Účetní je pak zpracuje stejně jako doklad z portálu.
--
-- Defaultně VYPNUTO — bez explicitního zapnutí se chování stávajících účtů nemění
-- a nefetchují se ani přílohy zpráv.

SET NAMES utf8mb4;

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS ingest_pdf_invoices TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_forwarded;

-- Zpráva, ze které vzniklo podání z přílohy, ale která NENÍ bankovní avízo (parser
-- ji neumí), musí skončit vlastním stavem — jinak se tváří jako selhání a IMAP
-- post-process ji pošle do složky chyb.
ALTER TABLE bank_email_processed_messages
  MODIFY COLUMN status ENUM(
    'processed_success','duplicate','parse_failed','security_rejected','match_failed',
    'postprocess_failed','skipped_old','skipped_known','attachment_imported'
  ) NOT NULL;

-- Nový zdroj podání. `email` se chová jako `staff` v tom smyslu, že za ním nestojí
-- klient, kterého by šlo vyzvat k doplnění.
ALTER TABLE purchase_invoice_submissions
  MODIFY COLUMN submitted_via ENUM('portal','document_request','staff','email') NOT NULL DEFAULT 'portal';

-- Auditní stopa každé posouzené přílohy — i té zamítnuté. Bez ní by uživatel
-- neviděl, PROČ se příloha nenačetla, a heuristiku by nešlo ladit.
CREATE TABLE IF NOT EXISTS bank_email_attachment_ingests (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  imap_account_id         BIGINT UNSIGNED NULL,
  message_id              VARCHAR(255) NULL,
  sender                  VARCHAR(255) NULL,
  subject                 VARCHAR(500) NULL,
  filename                VARCHAR(255) NOT NULL,
  sha256                  CHAR(64) NOT NULL,
  size_bytes              INT UNSIGNED NOT NULL DEFAULT 0,
  status                  ENUM('imported','skipped_duplicate','skipped_not_invoice','rejected','failed') NOT NULL,
  reason                  VARCHAR(1000) NULL,
  submission_id           BIGINT UNSIGNED NULL,
  matched_by              VARCHAR(32) NULL,
  match_score             DECIMAL(5,2) NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_beai_supplier_sha (supplier_id, sha256),
  KEY idx_beai_supplier_created (supplier_id, created_at),
  KEY idx_beai_account (imap_account_id),
  KEY idx_beai_submission (submission_id),
  CONSTRAINT fk_beai_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_beai_account FOREIGN KEY (imap_account_id) REFERENCES bank_email_imap_settings(id) ON DELETE SET NULL,
  CONSTRAINT fk_beai_submission FOREIGN KEY (submission_id) REFERENCES purchase_invoice_submissions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
