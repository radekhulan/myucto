-- MyÚčto.cz — DMS: přílohy k pokladním dokladům (document_links.entity_type = 'cash_document').
--
-- Sken účtenky nebo pokladního dokladu dosud šlo pověsit jen na účetní zápis,
-- a to až u zaúčtovaného dokladu. Daňová evidence zápis nemá vůbec.
--
-- Append-only ALTER — re-list VŠECH stávajících členů enumu (ověřeno proti 1103).
--
-- Tenantová integrita: `document_links.supplier_id` drží od 1575 složený FK na
-- dokument, cílová entita je ale polymorfní a FK mít nemůže. Pro pokladní
-- doklad proto hlídá vlastnictví trigger (defense-in-depth za
-- DocumentLinkRepository::entityBelongsToSupplier). Bez DECLARE — porovnává se
-- jen sloupec s literálem, takže se nemůže zapéct collation proměnné (viz 1738).
--
-- Smazání pokladního dokladu vazby uklidí. Osiřelá vazba by jinak navždy
-- blokovala vysypání dokumentu z koše (DocumentDeletionGuard `document_link`),
-- protože doklad, ve kterém by se dala odpojit, už neexistuje.

SET NAMES utf8mb4;

ALTER TABLE document_links
  MODIFY COLUMN entity_type ENUM('client','invoice','purchase_invoice','project','journal_entry','bank_transaction','cash_document') NOT NULL;

DELIMITER //

DROP TRIGGER IF EXISTS trg_document_links_cash_document_insert//

CREATE TRIGGER trg_document_links_cash_document_insert
BEFORE INSERT ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'cash_document' AND NOT EXISTS (
    SELECT 1
      FROM cash_documents cash
     WHERE cash.supplier_id = NEW.supplier_id
       AND cash.id = NEW.entity_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link cash document does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_document_links_cash_document_update//

CREATE TRIGGER trg_document_links_cash_document_update
BEFORE UPDATE ON document_links
FOR EACH ROW
BEGIN
  IF NEW.entity_type = 'cash_document' AND NOT EXISTS (
    SELECT 1
      FROM cash_documents cash
     WHERE cash.supplier_id = NEW.supplier_id
       AND cash.id = NEW.entity_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Document link cash document does not belong to supplier';
  END IF;
END//

DROP TRIGGER IF EXISTS trg_cash_documents_drop_document_links//

CREATE TRIGGER trg_cash_documents_drop_document_links
AFTER DELETE ON cash_documents
FOR EACH ROW
BEGIN
  DELETE FROM document_links
   WHERE supplier_id = OLD.supplier_id
     AND entity_type = 'cash_document'
     AND entity_id = OLD.id;
END//

DELIMITER ;
