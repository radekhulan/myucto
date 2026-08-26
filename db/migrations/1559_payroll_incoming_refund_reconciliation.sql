-- MyÚčto.cz — skutečně přijaté vratky opravných mzdových závazků bez odchozí dávky.

SET NAMES utf8mb4;

ALTER TABLE payroll_payment_matches
  ADD COLUMN IF NOT EXISTS liability_id BIGINT UNSIGNED NULL AFTER allocation_id;

DROP TRIGGER IF EXISTS trg_payroll_payment_match_immutable_update;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_match_immutable_update
BEFORE UPDATE ON payroll_payment_matches
FOR EACH ROW
BEGIN
  IF NOT (
    OLD.id <=> NEW.id
    AND OLD.supplier_id <=> NEW.supplier_id
    AND OLD.allocation_id <=> NEW.allocation_id
    AND OLD.liability_id IS NULL
    AND NEW.liability_id IS NOT NULL
    AND OLD.event_kind <=> NEW.event_kind
    AND OLD.source_match_id <=> NEW.source_match_id
    AND OLD.amount_minor <=> NEW.amount_minor
    AND OLD.bank_statement_id <=> NEW.bank_statement_id
    AND OLD.bank_transaction_id <=> NEW.bank_transaction_id
    AND OLD.cash_document_id <=> NEW.cash_document_id
    AND OLD.actual_payment_date <=> NEW.actual_payment_date
    AND OLD.evidence_amount_minor <=> NEW.evidence_amount_minor
    AND OLD.evidence_currency_code <=> NEW.evidence_currency_code
    AND OLD.evidence_fact_hash <=> NEW.evidence_fact_hash
    AND OLD.idempotency_key_hash <=> NEW.idempotency_key_hash
    AND OLD.matched_by <=> NEW.matched_by
    AND OLD.created_at <=> NEW.created_at
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment matches are immutable';
  END IF;
END//

DELIMITER ;

UPDATE payroll_payment_matches payment_match
JOIN payroll_payment_allocations allocation
  ON allocation.supplier_id = payment_match.supplier_id
 AND allocation.id = payment_match.allocation_id
SET payment_match.liability_id = allocation.liability_id
WHERE payment_match.liability_id IS NULL;

ALTER TABLE payroll_payment_matches
  DROP INDEX IF EXISTS uq_payroll_payment_match_direct_bank_event,
  DROP INDEX IF EXISTS uq_payroll_payment_match_direct_cash_event;

ALTER TABLE payroll_payment_matches
  MODIFY COLUMN allocation_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN liability_id BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_payroll_payment_match_liability (
    supplier_id, liability_id, actual_payment_date
  ),
  ADD CONSTRAINT fk_payroll_payment_match_liability
    FOREIGN KEY IF NOT EXISTS (supplier_id, liability_id)
    REFERENCES payroll_payment_liabilities (supplier_id, id) ON DELETE RESTRICT;

DROP TRIGGER IF EXISTS trg_payroll_payment_match_validate_insert;

DELIMITER //

CREATE TRIGGER trg_payroll_payment_match_validate_insert
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  DECLARE settlement_limit BIGINT UNSIGNED DEFAULT NULL;
  DECLARE allocation_liability_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_direction VARCHAR(16) DEFAULT NULL;
  DECLARE liability_currency CHAR(3) DEFAULT NULL;
  DECLARE evidence_date DATE DEFAULT NULL;
  DECLARE evidence_amount_decimal DECIMAL(15,2) DEFAULT NULL;
  DECLARE evidence_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE evidence_currency CHAR(3) DEFAULT NULL;
  DECLARE evidence_direction VARCHAR(16) DEFAULT NULL;
  DECLARE evidence_state VARCHAR(16) DEFAULT NULL;
  DECLARE evidence_fingerprint VARCHAR(191) DEFAULT NULL;
  DECLARE liability_matched BIGINT DEFAULT 0;
  DECLARE evidence_used BIGINT UNSIGNED DEFAULT 0;
  DECLARE source_amount BIGINT DEFAULT NULL;
  DECLARE source_event VARCHAR(16) DEFAULT NULL;
  DECLARE source_allocation_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE source_liability_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE source_reversed BIGINT DEFAULT 0;

  IF NEW.allocation_id IS NOT NULL THEN
    SELECT allocation.amount_minor, allocation.liability_id,
           liability.direction, liability.currency_code
      INTO settlement_limit, allocation_liability_id,
           liability_direction, liability_currency
      FROM payroll_payment_allocations allocation
      JOIN payroll_payment_liabilities liability
        ON liability.supplier_id = allocation.supplier_id
       AND liability.id = allocation.liability_id
     WHERE allocation.supplier_id = NEW.supplier_id
       AND allocation.id = NEW.allocation_id
     FOR UPDATE;

    IF settlement_limit IS NULL OR allocation_liability_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment match allocation is missing';
    END IF;
    IF NEW.liability_id IS NULL THEN
      SET NEW.liability_id = allocation_liability_id;
    ELSEIF allocation_liability_id <> NEW.liability_id THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment match allocation differs from liability';
    END IF;
  ELSE
    IF NEW.liability_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Direct payroll evidence requires liability';
    END IF;
    SELECT liability.amount_minor, liability.direction,
           liability.currency_code
      INTO settlement_limit, liability_direction, liability_currency
      FROM payroll_payment_liabilities liability
     WHERE liability.supplier_id = NEW.supplier_id
       AND liability.id = NEW.liability_id
     FOR UPDATE;

    IF settlement_limit IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment match liability is missing';
    END IF;
    IF liability_direction <> 'incoming' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Direct payroll evidence requires incoming liability';
    END IF;
  END IF;

  IF NEW.bank_transaction_id IS NOT NULL THEN
    SELECT bank_tx.posted_at, bank_tx.amount,
           COALESCE(bank_tx.currency, statement.currency),
           bank_tx.import_fingerprint
      INTO evidence_date, evidence_amount_decimal,
           evidence_currency, evidence_fingerprint
      FROM bank_statements statement
      JOIN bank_transactions bank_tx
        ON bank_tx.statement_id = statement.id
       AND bank_tx.id = NEW.bank_transaction_id
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
    SELECT cash.issue_date, cash.total_amount, cash.currency_code,
           cash.doc_type, cash.status,
           COALESCE(cash.doc_number, CONCAT('cash-document:', cash.id))
      INTO evidence_date, evidence_amount_decimal, evidence_currency,
           evidence_direction, evidence_state, evidence_fingerprint
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

  IF NEW.allocation_id IS NOT NULL THEN
    SELECT COALESCE(SUM(payment_match.amount_minor), 0)
      INTO liability_matched
      FROM payroll_payment_matches payment_match
     WHERE payment_match.supplier_id = NEW.supplier_id
       AND payment_match.allocation_id = NEW.allocation_id;
  ELSE
    SELECT COALESCE(SUM(payment_match.amount_minor), 0)
      INTO liability_matched
      FROM payroll_payment_matches payment_match
     WHERE payment_match.supplier_id = NEW.supplier_id
       AND payment_match.liability_id = NEW.liability_id
       AND payment_match.allocation_id IS NULL;
  END IF;
  IF liability_matched + NEW.amount_minor < 0
     OR liability_matched + NEW.amount_minor > settlement_limit
  THEN
    IF NEW.allocation_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment liability settlement is outside bounds';
    ELSE
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment allocation settlement is outside bounds';
    END IF;
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
    SELECT source_match.amount_minor, source_match.event_kind,
           source_match.allocation_id, source_match.liability_id
      INTO source_amount, source_event, source_allocation_id,
           source_liability_id
      FROM payroll_payment_matches source_match
     WHERE source_match.supplier_id = NEW.supplier_id
       AND source_match.id = NEW.source_match_id;

    IF source_amount IS NULL
       OR source_event <> 'matched'
       OR NOT (source_allocation_id <=> NEW.allocation_id)
       OR source_liability_id <> NEW.liability_id
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

DROP TRIGGER IF EXISTS trg_payroll_payment_match_immutable_update//

CREATE TRIGGER trg_payroll_payment_match_immutable_update
BEFORE UPDATE ON payroll_payment_matches
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment matches are immutable';
END//

DELIMITER ;
