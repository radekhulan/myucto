-- MyÚčto.cz — Kniha jízd: tankování navázané na doklad, kterým bylo zaplaceno.
--
-- PROČ
-- ---------------------------------------------------------------------------
-- Tankování šlo navázat jen na přijatou fakturu (`source_purchase_invoice_id`).
-- Účtenky za PHM ale bývají pokladní doklad (VPD) nebo platba kartou z bankovního
-- účtu, případně jen účetní zápis (převzatá data, ruční zápis). Tankování je dál
-- čistě EVIDENČNÍ vrstva (viz 0109) — náklad a DPH nese doklad, tady je jen odkaz.
--
-- CO PŘIBÝVÁ
-- ---------------------------------------------------------------------------
--   fuelings.source_cash_document_id    → cash_documents (pokladní doklad)
--   fuelings.source_bank_transaction_id → bank_transactions (pohyb na účtu)
--   fuelings.source_journal_entry_id    → journal_entries (obecně účetní zápis)
--   fuelings.source += 'cash'           (tankování vytěžené z pokladního dokladu)
--
-- TENANTOVÁ INTEGRITA
-- ---------------------------------------------------------------------------
-- Složený FK (supplier_id, x) → rodič(supplier_id, id) tu nejde: s ON DELETE SET NULL
-- by MariaDB nulovala i `supplier_id` (NOT NULL) a s RESTRICT by evidenční tankování
-- blokovalo smazání pokladního dokladu či výpisu (DeletionGuardRegistryTest). Vazby
-- jsou proto jednoduché FK s SET NULL a vlastnictví hlídají triggery níže — zápis
-- odkazu na doklad cizí firmy databáze odmítne i mimo Action vrstvu (CLI, import).
-- `bank_transactions` nemá `supplier_id`, vlastník se bere z `bank_statements`.
--
-- Idempotence: ADD COLUMN/INDEX IF NOT EXISTS, FK přes DROP IF EXISTS + ADD,
-- ENUM append-only, triggery DROP IF EXISTS + CREATE.

SET NAMES utf8mb4;

ALTER TABLE fuelings
  ADD COLUMN IF NOT EXISTS source_cash_document_id BIGINT UNSIGNED NULL
      COMMENT 'Pokladní doklad (VPD), kterým bylo tankování zaplaceno' AFTER source_item_id,
  ADD COLUMN IF NOT EXISTS source_bank_transaction_id BIGINT UNSIGNED NULL
      COMMENT 'Bankovní pohyb (platba kartou / převod), kterým bylo tankování zaplaceno' AFTER source_cash_document_id,
  ADD COLUMN IF NOT EXISTS source_journal_entry_id BIGINT UNSIGNED NULL
      COMMENT 'Obecná vazba na účetní zápis (převzatá data, ruční zápis)' AFTER source_bank_transaction_id;

ALTER TABLE fuelings
  ADD INDEX IF NOT EXISTS idx_fuelings_cash_document (supplier_id, source_cash_document_id),
  ADD INDEX IF NOT EXISTS idx_fuelings_bank_transaction (source_bank_transaction_id),
  ADD INDEX IF NOT EXISTS idx_fuelings_journal_entry (supplier_id, source_journal_entry_id);

ALTER TABLE fuelings DROP FOREIGN KEY IF EXISTS fk_fuelings_cash_document;
ALTER TABLE fuelings
  ADD CONSTRAINT fk_fuelings_cash_document
    FOREIGN KEY (source_cash_document_id) REFERENCES cash_documents (id) ON DELETE SET NULL;

ALTER TABLE fuelings DROP FOREIGN KEY IF EXISTS fk_fuelings_bank_transaction;
ALTER TABLE fuelings
  ADD CONSTRAINT fk_fuelings_bank_transaction
    FOREIGN KEY (source_bank_transaction_id) REFERENCES bank_transactions (id) ON DELETE SET NULL;

ALTER TABLE fuelings DROP FOREIGN KEY IF EXISTS fk_fuelings_journal_entry;
ALTER TABLE fuelings
  ADD CONSTRAINT fk_fuelings_journal_entry
    FOREIGN KEY (source_journal_entry_id) REFERENCES journal_entries (id) ON DELETE SET NULL;

-- Zdroj 'cash' = tankování vytěžené z pokladního dokladu (append-only, hodnota na konci).
ALTER TABLE fuelings
  MODIFY COLUMN source ENUM('manual','invoice','axigon','axigon_ai','import','cash') NOT NULL DEFAULT 'manual';

DELIMITER //

DROP TRIGGER IF EXISTS trg_fuelings_tenant_links_insert//

CREATE TRIGGER trg_fuelings_tenant_links_insert
BEFORE INSERT ON fuelings
FOR EACH ROW
BEGIN
  DECLARE owner_id BIGINT UNSIGNED DEFAULT NULL;

  IF NEW.car_id IS NOT NULL THEN
    SET owner_id = (SELECT c.supplier_id FROM cars c WHERE c.id = NEW.car_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling car belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_purchase_invoice_id IS NOT NULL THEN
    SET owner_id = (SELECT pi.supplier_id FROM purchase_invoices pi WHERE pi.id = NEW.source_purchase_invoice_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling purchase invoice belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_cash_document_id IS NOT NULL THEN
    SET owner_id = (SELECT cd.supplier_id FROM cash_documents cd WHERE cd.id = NEW.source_cash_document_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling cash document belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_bank_transaction_id IS NOT NULL THEN
    SET owner_id = (SELECT bs.supplier_id
                      FROM bank_transactions bt
                      JOIN bank_statements bs ON bs.id = bt.statement_id
                     WHERE bt.id = NEW.source_bank_transaction_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling bank transaction belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_journal_entry_id IS NOT NULL THEN
    SET owner_id = (SELECT je.supplier_id FROM journal_entries je WHERE je.id = NEW.source_journal_entry_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling journal entry belongs to another supplier';
    END IF;
  END IF;
END//

DROP TRIGGER IF EXISTS trg_fuelings_tenant_links_update//

-- Kontroluje jen ZMĚNĚNÉ vazby — historický řádek se tím nestane nezapisovatelným.
CREATE TRIGGER trg_fuelings_tenant_links_update
BEFORE UPDATE ON fuelings
FOR EACH ROW
BEGIN
  DECLARE owner_id BIGINT UNSIGNED DEFAULT NULL;

  IF NEW.supplier_id <> OLD.supplier_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling cannot move to another supplier';
  END IF;

  IF NEW.car_id IS NOT NULL AND NOT (NEW.car_id <=> OLD.car_id) THEN
    SET owner_id = (SELECT c.supplier_id FROM cars c WHERE c.id = NEW.car_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling car belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_purchase_invoice_id IS NOT NULL
     AND NOT (NEW.source_purchase_invoice_id <=> OLD.source_purchase_invoice_id) THEN
    SET owner_id = (SELECT pi.supplier_id FROM purchase_invoices pi WHERE pi.id = NEW.source_purchase_invoice_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling purchase invoice belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_cash_document_id IS NOT NULL
     AND NOT (NEW.source_cash_document_id <=> OLD.source_cash_document_id) THEN
    SET owner_id = (SELECT cd.supplier_id FROM cash_documents cd WHERE cd.id = NEW.source_cash_document_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling cash document belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_bank_transaction_id IS NOT NULL
     AND NOT (NEW.source_bank_transaction_id <=> OLD.source_bank_transaction_id) THEN
    SET owner_id = (SELECT bs.supplier_id
                      FROM bank_transactions bt
                      JOIN bank_statements bs ON bs.id = bt.statement_id
                     WHERE bt.id = NEW.source_bank_transaction_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling bank transaction belongs to another supplier';
    END IF;
  END IF;

  IF NEW.source_journal_entry_id IS NOT NULL
     AND NOT (NEW.source_journal_entry_id <=> OLD.source_journal_entry_id) THEN
    SET owner_id = (SELECT je.supplier_id FROM journal_entries je WHERE je.id = NEW.source_journal_entry_id);
    IF owner_id IS NULL OR owner_id <> NEW.supplier_id THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fueling journal entry belongs to another supplier';
    END IF;
  END IF;
END//

DELIMITER ;
