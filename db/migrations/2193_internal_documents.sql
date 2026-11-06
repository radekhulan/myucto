-- Interní doklady (agenda Účetnictví → Interní doklady): evidence ID s číselnou řadou
-- jako hlavička nad ručním zápisem deníku (zápočty, přeúčtování, odpisy pohledávek,
-- kurzové rozdíly, opravy, dohadné položky).
--
-- Zápis v deníku zůstává ručním zápisem (`source_type = 'manual'`, `source_id` NULL):
-- saldokonto (LinkedManualSettlementSql), uzávěrková kontrola K3 i souběh se starým
-- systémem s ručními zápisy počítají právě podle `manual`, a `source_id` ručních zápisů
-- už obsazují mzdové rekapitulace (RRRRMM). Vazbu proto nese hlavička
-- (`journal_entry_id`, `reversal_entry_id`), jeden zápis patří nejvýš jednomu dokladu.
--
-- Číslo se vydává při zaúčtování z řady `manual` (prefix ID), kterou sdílí i ruční zápisy
-- s automatickým číslováním, aby dva doklady nedostaly totéž číslo.
--
-- Koncept drží řádky kontace (`internal_document_lines`) a vazby na doklady
-- (`document_links_json`, přenesou se do `journal_entry_document_links` při zaúčtování).
--
-- Zápis dokladu nejde smazat mimo JournalAction::delete (ten hlavičku předem vrátí do
-- konceptu), proto ON DELETE RESTRICT: jinak by doklad zůstal zaúčtovaný bez zápisu.
-- `origin = journal` = hlavička vzniklá nad existujícím ručním zápisem; její řádky jsou jen
-- náhled (bez dimenzí, měny a vazeb), takže se takový zápis v deníku smazat nedá.
-- Přílohy jdou přes Dokumenty (`document_links`, typ entity `internal_document`).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS internal_documents (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  kind                VARCHAR(32) NOT NULL DEFAULT 'other',
  title               VARCHAR(255) NOT NULL,
  document_date       DATE NOT NULL,
  entry_date          DATE NOT NULL,
  partner_id          BIGINT UNSIGNED NULL,
  partner_name        VARCHAR(190) NULL,
  amount              DECIMAL(14,2) NOT NULL DEFAULT 0,
  note                TEXT NULL,
  document_links_json LONGTEXT NULL,
  status              ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  origin              ENUM('draft','journal') NOT NULL DEFAULT 'draft',
  document_no         VARCHAR(50) NULL,
  journal_entry_id    BIGINT UNSIGNED NULL,
  reversal_entry_id   BIGINT UNSIGNED NULL,
  reversal_reason     VARCHAR(500) NULL,
  posted_at           DATETIME NULL,
  posted_by           BIGINT UNSIGNED NULL,
  reversed_at         DATETIME NULL,
  created_by          BIGINT UNSIGNED NULL,
  updated_by          BIGINT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  row_version         INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_internal_document_tenant (id, supplier_id),
  UNIQUE KEY uq_internal_document_no (supplier_id, document_no),
  UNIQUE KEY uq_internal_document_entry (supplier_id, journal_entry_id),
  KEY idx_internal_document_reversal (supplier_id, reversal_entry_id),
  KEY idx_internal_document_date (supplier_id, document_date, id),
  KEY idx_internal_document_status (supplier_id, status, document_date),
  KEY idx_internal_document_partner (supplier_id, partner_id, document_date),
  CONSTRAINT fk_internal_document_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_internal_document_partner FOREIGN KEY (partner_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_internal_document_entry FOREIGN KEY (supplier_id, journal_entry_id) REFERENCES journal_entries(supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_internal_document_reversal FOREIGN KEY (supplier_id, reversal_entry_id) REFERENCES journal_entries(supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_internal_document_posted_by FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_internal_document_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_internal_document_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_internal_document_links CHECK (document_links_json IS NULL OR JSON_VALID(document_links_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_document_lines (
  supplier_id          INT UNSIGNED NOT NULL,
  internal_document_id BIGINT UNSIGNED NOT NULL,
  position             SMALLINT UNSIGNED NOT NULL,
  account_code         VARCHAR(20) NOT NULL,
  side                 ENUM('debit','credit') NOT NULL,
  amount               DECIMAL(14,2) NOT NULL,
  is_red_storno        TINYINT(1) NOT NULL DEFAULT 0,
  cost_center          VARCHAR(50) NULL,
  dimensions_json      LONGTEXT NULL,
  PRIMARY KEY (supplier_id, internal_document_id, position),
  KEY idx_internal_document_line_doc (internal_document_id),
  CONSTRAINT fk_internal_document_line_doc FOREIGN KEY (internal_document_id, supplier_id)
    REFERENCES internal_documents(id, supplier_id) ON DELETE CASCADE,
  CONSTRAINT chk_internal_document_line_amount CHECK (amount > 0),
  CONSTRAINT chk_internal_document_line_dims CHECK (dimensions_json IS NULL OR JSON_VALID(dimensions_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE document_links
  MODIFY entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry','bank_transaction','cash_document','other_item','invoice_payment','internal_document') NOT NULL;

DELIMITER //

DROP TRIGGER IF EXISTS trg_document_links_internal_document_insert//

CREATE TRIGGER trg_document_links_internal_document_insert
BEFORE INSERT ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'internal_document' AND NOT EXISTS (
    SELECT 1 FROM internal_documents d
     WHERE d.id = NEW.entity_id
       AND d.supplier_id = NEW.supplier_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link internal document does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_document_links_internal_document_update//

CREATE TRIGGER trg_document_links_internal_document_update
BEFORE UPDATE ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'internal_document' AND NOT EXISTS (
    SELECT 1 FROM internal_documents d
     WHERE d.id = NEW.entity_id
       AND d.supplier_id = NEW.supplier_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link internal document does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_internal_documents_drop_document_links//

CREATE TRIGGER trg_internal_documents_drop_document_links
AFTER DELETE ON internal_documents
FOR EACH ROW
BEGIN
  DELETE FROM document_links
   WHERE supplier_id = OLD.supplier_id
     AND entity_type = 'internal_document'
     AND entity_id = OLD.id;
END//

DELIMITER ;
