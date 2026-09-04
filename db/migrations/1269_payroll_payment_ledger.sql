-- MyÚčto.cz — MZ-17: neměnné platební závazky, dávky a skutečné úhrady.

SET NAMES utf8mb4;

ALTER TABLE bank_statements
  ADD UNIQUE KEY IF NOT EXISTS uq_bank_statement_supplier_id (supplier_id, id);

ALTER TABLE bank_transactions
  ADD UNIQUE KEY IF NOT EXISTS uq_bank_transaction_statement_id (statement_id, id);

ALTER TABLE cash_documents
  ADD UNIQUE KEY IF NOT EXISTS uq_cash_document_supplier_id (supplier_id, id);

CREATE TABLE IF NOT EXISTS payroll_payment_liabilities (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  revision_id           BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NULL,
  liability_reference   VARCHAR(96) NOT NULL,
  liability_kind        ENUM(
    'net_wage','social_insurance','health_insurance',
    'advance_tax','withholding_tax','deduction',
    'enforcement','insolvency','benefit',
    'statutory_insurance','other'
  ) NOT NULL,
  direction             ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
  recipient_reference   VARCHAR(190) NOT NULL,
  due_on                DATE NOT NULL,
  currency_code         CHAR(3) NOT NULL DEFAULT 'CZK',
  amount_minor          BIGINT UNSIGNED NOT NULL,
  previous_liability_id BIGINT UNSIGNED NULL,
  source_snapshot_json  LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash  CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_liability_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_liability_reference (
    supplier_id, revision_id, liability_reference
  ),
  UNIQUE KEY uq_payroll_payment_liability_idempotency (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_liability_due (
    supplier_id, due_on, liability_kind
  ),
  KEY idx_payroll_payment_liability_employee (
    supplier_id, employee_id, due_on
  ),
  CONSTRAINT fk_payroll_payment_liability_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_liability_revision
    FOREIGN KEY (supplier_id, revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_liability_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_liability_previous
    FOREIGN KEY (supplier_id, previous_liability_id)
    REFERENCES payroll_payment_liabilities (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_liability_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_payment_liability_amount CHECK (amount_minor > 0),
  CONSTRAINT chk_payroll_payment_liability_currency CHECK (
    currency_code REGEXP '^[A-Z]{3}$'
  ),
  CONSTRAINT chk_payroll_payment_liability_reference CHECK (
    liability_reference REGEXP '^[a-z0-9][a-z0-9._:-]{0,95}$'
  ),
  CONSTRAINT chk_payroll_payment_liability_employee CHECK (
    liability_kind <> 'net_wage' OR employee_id IS NOT NULL
  ),
  CONSTRAINT chk_payroll_payment_liability_hash CHECK (
    source_snapshot_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payment_batches (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_reference       VARCHAR(96) NOT NULL,
  channel               ENUM('bank','cash') NOT NULL,
  export_format         ENUM('abo','sepa','csv','pdf','manual') NOT NULL,
  direction             ENUM('outgoing','incoming') NOT NULL DEFAULT 'outgoing',
  planned_payment_date  DATE NOT NULL,
  currency_code         CHAR(3) NOT NULL DEFAULT 'CZK',
  payer_reference       VARCHAR(190) NOT NULL,
  declared_total_minor  BIGINT UNSIGNED NOT NULL,
  declared_item_count   INT UNSIGNED NOT NULL,
  snapshot_ciphertext   LONGTEXT NOT NULL,
  snapshot_hash         CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_batch_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_batch_reference (supplier_id, batch_reference),
  UNIQUE KEY uq_payroll_payment_batch_idempotency (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_batch_planned (
    supplier_id, planned_payment_date, channel
  ),
  CONSTRAINT fk_payroll_payment_batch_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_batch_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_payment_batch_shape CHECK (
    declared_total_minor > 0 AND declared_item_count > 0
  ),
  CONSTRAINT chk_payroll_payment_batch_currency CHECK (
    currency_code REGEXP '^[A-Z]{3}$'
  ),
  CONSTRAINT chk_payroll_payment_batch_reference CHECK (
    batch_reference REGEXP '^[a-z0-9][a-z0-9._:-]{0,95}$'
  ),
  CONSTRAINT chk_payroll_payment_batch_encryption CHECK (
    snapshot_ciphertext LIKE 'enc:v2:%'
  ),
  CONSTRAINT chk_payroll_payment_batch_hash CHECK (
    snapshot_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payment_items (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  batch_id              BIGINT UNSIGNED NOT NULL,
  item_reference        VARCHAR(96) NOT NULL,
  recipient_reference   VARCHAR(190) NOT NULL,
  amount_minor          BIGINT UNSIGNED NOT NULL,
  instruction_ciphertext LONGTEXT NOT NULL,
  instruction_hash      CHAR(64) NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_item_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_item_reference (
    supplier_id, batch_id, item_reference
  ),
  UNIQUE KEY uq_payroll_payment_item_idempotency (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_item_batch (supplier_id, batch_id, id),
  CONSTRAINT fk_payroll_payment_item_batch
    FOREIGN KEY (supplier_id, batch_id)
    REFERENCES payroll_payment_batches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_payment_item_amount CHECK (amount_minor > 0),
  CONSTRAINT chk_payroll_payment_item_reference CHECK (
    item_reference REGEXP '^[a-z0-9][a-z0-9._:-]{0,95}$'
  ),
  CONSTRAINT chk_payroll_payment_item_encryption CHECK (
    instruction_ciphertext LIKE 'enc:v2:%'
  ),
  CONSTRAINT chk_payroll_payment_item_hash CHECK (
    instruction_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payment_allocations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  item_id               BIGINT UNSIGNED NOT NULL,
  liability_id          BIGINT UNSIGNED NOT NULL,
  amount_minor          BIGINT UNSIGNED NOT NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_allocation_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_allocation_pair (
    supplier_id, item_id, liability_id
  ),
  UNIQUE KEY uq_payroll_payment_allocation_idempotency (
    supplier_id, idempotency_key_hash
  ),
  KEY idx_payroll_payment_allocation_liability (
    supplier_id, liability_id, id
  ),
  CONSTRAINT fk_payroll_payment_allocation_item
    FOREIGN KEY (supplier_id, item_id)
    REFERENCES payroll_payment_items (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_allocation_liability
    FOREIGN KEY (supplier_id, liability_id)
    REFERENCES payroll_payment_liabilities (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_payment_allocation_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_payment_matches (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  allocation_id         BIGINT UNSIGNED NOT NULL,
  event_kind            ENUM('matched','reversed') NOT NULL,
  source_match_id       BIGINT UNSIGNED NULL,
  amount_minor          BIGINT NOT NULL,
  bank_statement_id     BIGINT UNSIGNED NULL,
  bank_transaction_id   BIGINT UNSIGNED NULL,
  cash_document_id      BIGINT UNSIGNED NULL,
  actual_payment_date   DATE NULL,
  evidence_amount_minor BIGINT UNSIGNED NULL,
  evidence_currency_code CHAR(3) NULL,
  evidence_fact_hash    CHAR(64) NULL,
  idempotency_key_hash  BINARY(32) NOT NULL,
  matched_by            BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_payment_match_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_payment_match_idempotency (
    supplier_id, idempotency_key_hash
  ),
  UNIQUE KEY uq_payroll_payment_match_bank_event (
    supplier_id, allocation_id, bank_transaction_id, event_kind
  ),
  UNIQUE KEY uq_payroll_payment_match_cash_event (
    supplier_id, allocation_id, cash_document_id, event_kind
  ),
  KEY idx_payroll_payment_match_allocation (
    supplier_id, allocation_id, actual_payment_date
  ),
  KEY idx_payroll_payment_match_bank (
    bank_statement_id, bank_transaction_id
  ),
  KEY idx_payroll_payment_match_cash (supplier_id, cash_document_id),
  CONSTRAINT fk_payroll_payment_match_allocation
    FOREIGN KEY (supplier_id, allocation_id)
    REFERENCES payroll_payment_allocations (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_source
    FOREIGN KEY (supplier_id, source_match_id)
    REFERENCES payroll_payment_matches (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_bank_statement
    FOREIGN KEY (supplier_id, bank_statement_id)
    REFERENCES bank_statements (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_bank_transaction
    FOREIGN KEY (bank_statement_id, bank_transaction_id)
    REFERENCES bank_transactions (statement_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_cash_document
    FOREIGN KEY (supplier_id, cash_document_id)
    REFERENCES cash_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_payment_match_user
    FOREIGN KEY (matched_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_payment_match_evidence CHECK (
    (
      bank_statement_id IS NOT NULL
      AND bank_transaction_id IS NOT NULL
      AND cash_document_id IS NULL
    )
    OR
    (
      bank_statement_id IS NULL
      AND bank_transaction_id IS NULL
      AND cash_document_id IS NOT NULL
    )
  ),
  CONSTRAINT chk_payroll_payment_match_event CHECK (
    (
      event_kind = 'matched'
      AND amount_minor > 0
      AND source_match_id IS NULL
    )
    OR
    (
      event_kind = 'reversed'
      AND amount_minor < 0
      AND source_match_id IS NOT NULL
    )
  ),
  CONSTRAINT chk_payroll_payment_match_derived_evidence CHECK (
    actual_payment_date IS NOT NULL
    AND evidence_amount_minor IS NOT NULL
    AND evidence_amount_minor > 0
    AND evidence_currency_code REGEXP '^[A-Z]{3}$'
    AND evidence_fact_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_payroll_payment_liability_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_payment_allocation_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_payment_match_validate_insert;
DROP TRIGGER IF EXISTS trg_payroll_payment_liability_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_liability_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payment_batch_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_batch_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payment_item_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_item_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payment_allocation_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_allocation_immutable_delete;
DROP TRIGGER IF EXISTS trg_payroll_payment_match_immutable_update;
DROP TRIGGER IF EXISTS trg_payroll_payment_match_immutable_delete;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_liability_validate_insert
BEFORE INSERT ON payroll_payment_liabilities
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_run_revisions revision
     WHERE revision.supplier_id = NEW.supplier_id
       AND revision.id = NEW.revision_id
       AND revision.status IN ('approved', 'superseded')
       AND revision.result_snapshot_hash IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability requires an approved revision';
  END IF;

  IF NEW.employee_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_run_persons person_result
     WHERE person_result.supplier_id = NEW.supplier_id
       AND person_result.revision_id = NEW.revision_id
       AND person_result.employee_id = NEW.employee_id
       AND person_result.status = 'calculated'
       AND person_result.result_hash IS NOT NULL
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Employee liability requires a calculated person result';
  END IF;

  IF NEW.previous_liability_id IS NOT NULL AND NOT EXISTS (
    SELECT 1
      FROM payroll_payment_liabilities previous_liability
      JOIN payroll_run_revisions previous_revision
        ON previous_revision.supplier_id = previous_liability.supplier_id
       AND previous_revision.id = previous_liability.revision_id
      JOIN payroll_run_revisions current_revision
        ON current_revision.supplier_id = NEW.supplier_id
       AND current_revision.id = NEW.revision_id
     WHERE previous_liability.supplier_id = NEW.supplier_id
       AND previous_liability.id = NEW.previous_liability_id
       AND previous_liability.liability_reference = NEW.liability_reference
       AND previous_liability.liability_kind = NEW.liability_kind
       AND previous_revision.run_id = current_revision.run_id
       AND current_revision.revision_no > previous_revision.revision_no
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability correction chain is inconsistent';
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_allocation_validate_insert
BEFORE INSERT ON payroll_payment_allocations
FOR EACH ROW
BEGIN
  DECLARE item_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE item_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE item_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE allocated_to_item BIGINT UNSIGNED DEFAULT 0;
  DECLARE allocated_to_liability BIGINT UNSIGNED DEFAULT 0;

  SELECT item.amount_minor, batch.direction, batch.currency_code
    INTO item_amount, item_direction, item_currency
    FROM payroll_payment_items item
    JOIN payroll_payment_batches batch
      ON batch.supplier_id = item.supplier_id
     AND batch.id = item.batch_id
   WHERE item.supplier_id = NEW.supplier_id
     AND item.id = NEW.item_id
   FOR UPDATE;

  SELECT liability.amount_minor, liability.direction, liability.currency_code
    INTO liability_amount, liability_direction, liability_currency
    FROM payroll_payment_liabilities liability
   WHERE liability.supplier_id = NEW.supplier_id
     AND liability.id = NEW.liability_id
   FOR UPDATE;

  IF item_amount IS NULL OR liability_amount IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment allocation target is missing';
  END IF;
  IF item_direction <> liability_direction OR item_currency <> liability_currency THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment allocation direction or currency differs';
  END IF;

  SELECT COALESCE(SUM(allocation.amount_minor), 0)
    INTO allocated_to_item
    FROM payroll_payment_allocations allocation
   WHERE allocation.supplier_id = NEW.supplier_id
     AND allocation.item_id = NEW.item_id;
  SELECT COALESCE(SUM(allocation.amount_minor), 0)
    INTO allocated_to_liability
    FROM payroll_payment_allocations allocation
   WHERE allocation.supplier_id = NEW.supplier_id
     AND allocation.liability_id = NEW.liability_id;

  IF allocated_to_item + NEW.amount_minor > item_amount THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment item is overallocated';
  END IF;
  IF allocated_to_liability + NEW.amount_minor > liability_amount THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment liability is overallocated';
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_match_validate_insert
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  DECLARE allocation_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_date DATE DEFAULT NULL;
  DECLARE evidence_amount_decimal DECIMAL(15,2) DEFAULT NULL;
  DECLARE evidence_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE evidence_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_state VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_fingerprint VARCHAR(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE allocation_matched BIGINT DEFAULT 0;
  DECLARE evidence_used BIGINT UNSIGNED DEFAULT 0;
  DECLARE source_amount BIGINT DEFAULT NULL;
  DECLARE source_event VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE source_allocation_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE source_reversed BIGINT DEFAULT 0;

  SELECT allocation.amount_minor, liability.direction, liability.currency_code
    INTO allocation_amount, liability_direction, liability_currency
    FROM payroll_payment_allocations allocation
    JOIN payroll_payment_liabilities liability
      ON liability.supplier_id = allocation.supplier_id
     AND liability.id = allocation.liability_id
   WHERE allocation.supplier_id = NEW.supplier_id
     AND allocation.id = NEW.allocation_id
   FOR UPDATE;

  IF allocation_amount IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment match allocation is missing';
  END IF;

  IF NEW.bank_transaction_id IS NOT NULL THEN
    SELECT transaction.posted_at,
           transaction.amount,
           COALESCE(transaction.currency, statement.currency),
           transaction.import_fingerprint
      INTO evidence_date,
           evidence_amount_decimal,
           evidence_currency,
           evidence_fingerprint
      FROM bank_statements statement
      JOIN bank_transactions transaction
        ON transaction.statement_id = statement.id
       AND transaction.id = NEW.bank_transaction_id
     WHERE statement.supplier_id = NEW.supplier_id
       AND statement.id = NEW.bank_statement_id
     FOR UPDATE;

    IF evidence_date IS NULL OR evidence_amount_decimal IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll bank evidence does not belong to supplier';
    END IF;
    SET evidence_direction = IF(evidence_amount_decimal < 0, 'outgoing', 'incoming');
    SET evidence_state = 'posted';
    SET evidence_fingerprint = COALESCE(
      evidence_fingerprint,
      CONCAT('bank-transaction:', NEW.bank_transaction_id)
    );
  ELSE
    SELECT cash.issue_date,
           cash.total_amount,
           cash.currency_code,
           cash.doc_type,
           cash.status,
           COALESCE(cash.doc_number, CONCAT('cash-document:', cash.id))
      INTO evidence_date,
           evidence_amount_decimal,
           evidence_currency,
           evidence_direction,
           evidence_state,
           evidence_fingerprint
      FROM cash_documents cash
     WHERE cash.supplier_id = NEW.supplier_id
       AND cash.id = NEW.cash_document_id
     FOR UPDATE;

    IF evidence_date IS NULL OR evidence_amount_decimal IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll cash evidence does not belong to supplier';
    END IF;
    SET evidence_direction = IF(evidence_direction = 'out', 'outgoing', 'incoming');
    IF (
      (NEW.event_kind = 'matched' AND evidence_state <> 'posted')
      OR (NEW.event_kind = 'reversed' AND evidence_state <> 'reversed')
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll cash evidence has incompatible status';
    END IF;
  END IF;

  IF evidence_currency IS NULL OR evidence_currency <> liability_currency THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment evidence currency differs';
  END IF;
  IF (
    NEW.event_kind = 'matched'
    AND evidence_direction <> liability_direction
  ) OR (
    NEW.event_kind = 'reversed'
    AND NEW.bank_transaction_id IS NOT NULL
    AND evidence_direction = liability_direction
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment evidence direction differs';
  END IF;

  SET evidence_amount = CAST(ROUND(ABS(evidence_amount_decimal) * 100) AS UNSIGNED);
  IF evidence_amount = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment evidence amount must be positive';
  END IF;

  SET NEW.actual_payment_date = evidence_date;
  SET NEW.evidence_amount_minor = evidence_amount;
  SET NEW.evidence_currency_code = evidence_currency;
  SET NEW.evidence_fact_hash = SHA2(CONCAT_WS(
    '|',
    'payroll-payment-evidence.v1',
    NEW.supplier_id,
    IF(NEW.bank_transaction_id IS NULL, 'cash', 'bank'),
    COALESCE(NEW.bank_transaction_id, NEW.cash_document_id),
    evidence_date,
    evidence_amount,
    evidence_currency,
    evidence_direction,
    evidence_state,
    evidence_fingerprint
  ), 256);

  SELECT COALESCE(SUM(payment_match.amount_minor), 0)
    INTO allocation_matched
    FROM payroll_payment_matches payment_match
   WHERE payment_match.supplier_id = NEW.supplier_id
     AND payment_match.allocation_id = NEW.allocation_id;
  IF allocation_matched + NEW.amount_minor < 0
     OR allocation_matched + NEW.amount_minor > allocation_amount
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment allocation settlement is outside bounds';
  END IF;

  IF NEW.bank_transaction_id IS NOT NULL THEN
    SELECT COALESCE(SUM(ABS(payment_match.amount_minor)), 0)
      INTO evidence_used
      FROM payroll_payment_matches payment_match
     WHERE payment_match.supplier_id = NEW.supplier_id
       AND payment_match.bank_statement_id = NEW.bank_statement_id
       AND payment_match.bank_transaction_id = NEW.bank_transaction_id
       AND payment_match.event_kind = NEW.event_kind;
  ELSE
    SELECT COALESCE(SUM(ABS(payment_match.amount_minor)), 0)
      INTO evidence_used
      FROM payroll_payment_matches payment_match
     WHERE payment_match.supplier_id = NEW.supplier_id
       AND payment_match.cash_document_id = NEW.cash_document_id
       AND payment_match.event_kind = NEW.event_kind;
  END IF;
  IF evidence_used + ABS(NEW.amount_minor) > evidence_amount THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment evidence is overallocated';
  END IF;

  IF NEW.event_kind = 'reversed' THEN
    -- BEZ `FOR UPDATE`: tenhle SELECT čte tabulku, NAD KTEROU trigger sám visí.
    -- Obyčejné čtení vlastní tabulky MariaDB v triggeru dovolí, zamykající ne —
    -- skončilo by chybou 1442 („Can't update table … already used by statement
    -- which invoked this trigger"), takže by KAŽDÝ zápis reverzace spadl.
    -- Ostatní `FOR UPDATE` v tomhle triggeru zůstávají: míří na jiné tabulky
    -- (allocations, liabilities, bank_statements, cash_documents), kde je to legální.
    -- Serializaci souběžných reverzací téhož source_match drží kontrola součtu níž
    -- spolu s unikátním indexem, ne zámek řádku.
    SELECT source_match.amount_minor,
           source_match.event_kind,
           source_match.allocation_id
      INTO source_amount, source_event, source_allocation_id
      FROM payroll_payment_matches source_match
     WHERE source_match.supplier_id = NEW.supplier_id
       AND source_match.id = NEW.source_match_id;

    IF source_amount IS NULL
       OR source_event <> 'matched'
       OR source_allocation_id <> NEW.allocation_id
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment reversal source is incompatible';
    END IF;

    SELECT COALESCE(SUM(payment_reversal.amount_minor), 0)
      INTO source_reversed
      FROM payroll_payment_matches payment_reversal
     WHERE payment_reversal.supplier_id = NEW.supplier_id
       AND payment_reversal.source_match_id = NEW.source_match_id
       AND payment_reversal.event_kind = 'reversed';
    IF ABS(NEW.amount_minor) > source_amount + source_reversed THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment reversal exceeds source match';
    END IF;
  END IF;
END//

CREATE TRIGGER trg_payroll_payment_liability_immutable_update
BEFORE UPDATE ON payroll_payment_liabilities
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment liabilities are immutable';
END//

CREATE TRIGGER trg_payroll_payment_liability_immutable_delete
BEFORE DELETE ON payroll_payment_liabilities
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment liabilities are append-only';
END//

CREATE TRIGGER trg_payroll_payment_batch_immutable_update
BEFORE UPDATE ON payroll_payment_batches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment batches are immutable';
END//

CREATE TRIGGER trg_payroll_payment_batch_immutable_delete
BEFORE DELETE ON payroll_payment_batches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment batches are append-only';
END//

CREATE TRIGGER trg_payroll_payment_item_immutable_update
BEFORE UPDATE ON payroll_payment_items
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment items are immutable';
END//

CREATE TRIGGER trg_payroll_payment_item_immutable_delete
BEFORE DELETE ON payroll_payment_items
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment items are append-only';
END//

CREATE TRIGGER trg_payroll_payment_allocation_immutable_update
BEFORE UPDATE ON payroll_payment_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment allocations are immutable';
END//

CREATE TRIGGER trg_payroll_payment_allocation_immutable_delete
BEFORE DELETE ON payroll_payment_allocations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment allocations are append-only';
END//

CREATE TRIGGER trg_payroll_payment_match_immutable_update
BEFORE UPDATE ON payroll_payment_matches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment matches are immutable';
END//

CREATE TRIGGER trg_payroll_payment_match_immutable_delete
BEFORE DELETE ON payroll_payment_matches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment matches are append-only';
END//

DELIMITER ;
