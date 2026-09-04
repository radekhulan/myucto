-- Lokální proměnné v triggerech dostaly natvrdo collation, se kterou se porovnávají.
--
-- CO SE STALO
-- ---------------------------------------------------------------------------
-- Export mzdových plateb spadl v provozu na
--   „Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT)
--    and (utf8mb4_general_ci,IMPLICIT) for operation '<>'".
-- Tabulky mají sloupce v `utf8mb4_unicode_ci`, ale proměnná deklarovaná
-- v triggeru jako `DECLARE x VARCHAR(16)` žádnou collation neurčuje — zdědí
-- VÝCHOZÍ COLLATION DATABÁZE. Když databáze vznikla bez výslovné collation,
-- MariaDB jí dá `utf8mb4_general_ci`, a první porovnání proměnné se sloupcem
-- pak skončí chybou 1267. Ověřeno pokusem: v databázi `general_ci` to padá,
-- s výslovným `COLLATE` u deklarace projde.
--
-- PROČ TO NEJDE OPRAVIT JINDE
-- ---------------------------------------------------------------------------
-- Ani `SET collation_connection`, ani `ALTER DATABASE` s tím nehnou: collation
-- proměnné se zapeče do uložené definice triggeru v okamžiku, kdy vznikne.
-- Existující trigger se proto musí VYTVOŘIT ZNOVU — proto tahle migrace.
-- Samotné deklarace jsou opravené i ve svých původních migracích, aby nová
-- instalace nevznikla rozbitá; tady jsou tytéž definice ještě jednou, aby se
-- dorovnaly instalace, které původní verzi už spustily.
--
-- Není to kosmetika: dokud trigger platí ve staré podobě, je celá jeho tabulka
-- na takové instalaci nezapisovatelná. U mzdových plateb to znamená, že nejde
-- vygenerovat platební příkaz.
--
-- Aby se stejná mina nevrátila, hlídá deklarace bez `COLLATE`
-- api/tests/Architecture/MigrationDeclaredCollationTest.php.

-- Táž mina o patro níž: `activity_log_chain_head` vznikla bez výslovné collation,
-- takže na serveru s výchozím `general_ci` má `last_hash` jinou collation než
-- `activity_log.hash`, se kterým se řetěz porovnává. Převod je idempotentní.
ALTER TABLE activity_log_chain_head
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET NAMES utf8mb4;

DELIMITER //

-- trg_payroll_run_revision_immutable_update (definice z 1722_payroll_run_revision_abandon_on_cancel.sql)
DROP TRIGGER IF EXISTS trg_payroll_run_revision_immutable_update//

CREATE TRIGGER trg_payroll_run_revision_immutable_update
BEFORE UPDATE ON payroll_run_revisions
FOR EACH ROW
BEGIN
  DECLARE run_status VARCHAR(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

  -- Odsunutá i zahozená revize je konečná: doklad o tom, co kdysi platilo
  -- (resp. co se zahodilo), se už nemění.
  IF OLD.status IN ('superseded','abandoned') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Superseded payroll run revision is immutable';
  END IF;

  IF OLD.status = 'approved' THEN
    IF NEW.status <> 'superseded' THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Approved payroll run revision is immutable';
    END IF;
    IF NEW.superseded_at IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Superseding a payroll run revision must stamp superseded_at';
    END IF;
  END IF;

  -- Nová revize nikdy nevzniká rovnou jako odsunutá a stopu po odsunutí
  -- nesmí nést nic, co odsunuté není.
  IF OLD.status <> 'approved' AND NEW.status = 'superseded' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Only an approved payroll run revision can be superseded';
  END IF;

  IF NEW.status = 'abandoned' THEN
    IF OLD.status NOT IN ('snapshot','calculated','reviewed') THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Only an unapproved payroll run revision can be abandoned';
    END IF;
    IF NEW.superseded_at IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Abandoning a payroll run revision must stamp superseded_at';
    END IF;
    -- Nástupce je povinný jen tam, kde nějaký je. Zrušený běh revizi
    -- nenahrazuje, ruší ji spolu se sebou.
    IF NEW.superseded_by_revision_id IS NULL THEN
      SELECT status INTO run_status
        FROM payroll_runs
       WHERE supplier_id = NEW.supplier_id
         AND id = NEW.run_id;
      IF run_status IS NULL OR run_status <> 'cancelled' THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Abandoning a payroll run revision must stamp its successor';
      END IF;
    END IF;
  END IF;

  -- Odsunutí i zahození je JEN změna stavu. Kdyby se pod ním dal přepsat
  -- snapshot, hash nebo schvalovatel, byla by z povolené výjimky díra
  -- do neměnnosti.
  IF NEW.status IN ('superseded','abandoned') THEN
    IF NOT (OLD.id <=> NEW.id)
      OR NOT (OLD.supplier_id <=> NEW.supplier_id)
      OR NOT (OLD.run_id <=> NEW.run_id)
      OR NOT (OLD.revision_no <=> NEW.revision_no)
      OR NOT (OLD.previous_revision_id <=> NEW.previous_revision_id)
      OR NOT (OLD.revision_kind <=> NEW.revision_kind)
      OR NOT (OLD.schema_version <=> NEW.schema_version)
      OR NOT (OLD.ruleset_manifest_hash <=> NEW.ruleset_manifest_hash)
      OR NOT (OLD.input_snapshot_json <=> NEW.input_snapshot_json)
      OR NOT (OLD.input_snapshot_hash <=> NEW.input_snapshot_hash)
      OR NOT (OLD.result_snapshot_json <=> NEW.result_snapshot_json)
      OR NOT (OLD.result_snapshot_hash <=> NEW.result_snapshot_hash)
      OR NOT (OLD.idempotency_key_hash <=> NEW.idempotency_key_hash)
      OR NOT (OLD.calculated_by <=> NEW.calculated_by)
      OR NOT (OLD.reviewed_by <=> NEW.reviewed_by)
      OR NOT (OLD.approved_by <=> NEW.approved_by)
      OR NOT (OLD.calculated_at <=> NEW.calculated_at)
      OR NOT (OLD.reviewed_at <=> NEW.reviewed_at)
      OR NOT (OLD.approved_at <=> NEW.approved_at)
      OR NOT (OLD.created_at <=> NEW.created_at)
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Superseding a payroll run revision must not change anything else';
    END IF;
  END IF;
END//

-- trg_payroll_enforcement_ledger_consistency_insert (definice z 1571_payroll_enforcement_deposit_release.sql)
DROP TRIGGER IF EXISTS trg_payroll_enforcement_ledger_consistency_insert//

CREATE TRIGGER trg_payroll_enforcement_ledger_consistency_insert
BEFORE INSERT ON payroll_enforcement_ledger
FOR EACH ROW
BEGIN
  DECLARE allocation_total BIGINT DEFAULT NULL;
  DECLARE held_total BIGINT DEFAULT 0;
  DECLARE released_total BIGINT DEFAULT 0;
  DECLARE remitted_total BIGINT DEFAULT 0;
  DECLARE returned_total BIGINT DEFAULT 0;
  DECLARE result_fee BIGINT DEFAULT NULL;
  DECLARE decision_case_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_command VARCHAR(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE decision_document_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE decision_evidence_hash CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

  IF NEW.entry_kind = 'released_for_remittance' THEN
    SELECT decision.case_id, decision.command_name,
           decision.decision_document_id, decision.decision_evidence_hash
      INTO decision_case_id, decision_command, decision_document_id,
           decision_evidence_hash
      FROM payroll_enforcement_events decision
     WHERE decision.supplier_id = NEW.supplier_id
       AND decision.id = NEW.decision_event_id;

    IF decision_case_id IS NULL
       OR decision_case_id <> NEW.case_id
       OR decision_command IS NULL
       OR decision_command NOT IN ('authorize_remittance','resume_remittance')
       OR decision_document_id IS NULL
       OR decision_evidence_hash IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement release has no matching decision';
    END IF;
  END IF;

  IF NEW.entry_kind = 'employer_fee' THEN
    SELECT employer_fee_minor_units
      INTO result_fee
      FROM payroll_enforcement_month_results
     WHERE supplier_id = NEW.supplier_id
       AND id = NEW.month_result_id;

    IF result_fee IS NULL OR NEW.amount_minor_units <> result_fee THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement employer fee does not match result';
    END IF;
  ELSEIF NEW.entry_kind IN (
    'withheld','held','released_for_remittance','remitted','released_to_employee'
  ) THEN
    SELECT total_minor_units
      INTO allocation_total
      FROM payroll_enforcement_allocations
     WHERE supplier_id = NEW.supplier_id
       AND month_result_id = NEW.month_result_id
       AND case_id <=> NEW.case_id
       AND claim_id <=> NEW.claim_id
     LIMIT 1;

    IF allocation_total IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement ledger has no matching allocation';
    END IF;

    IF NEW.entry_kind IN ('withheld','held')
       AND NEW.amount_minor_units <> allocation_total THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll enforcement calculation entry differs from allocation';
    END IF;

    IF NEW.entry_kind IN ('released_for_remittance','released_to_employee') THEN
      SELECT COALESCE(SUM(CASE WHEN entry_kind = 'held'
             THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_for_remittance'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'remitted'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_to_employee'
               THEN amount_minor_units ELSE 0 END), 0)
        INTO held_total, released_total, remitted_total, returned_total
        FROM payroll_enforcement_ledger
       WHERE supplier_id = NEW.supplier_id
         AND month_result_id = NEW.month_result_id
         AND case_id <=> NEW.case_id
         AND claim_id <=> NEW.claim_id;

      IF returned_total
           + IF(NEW.entry_kind = 'released_to_employee', NEW.amount_minor_units, 0)
           + GREATEST(
               released_total
                 + IF(NEW.entry_kind = 'released_for_remittance',
                      NEW.amount_minor_units, 0),
               remitted_total
             ) > held_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement held amount is over-disposed';
      END IF;
    ELSEIF NEW.entry_kind = 'remitted' THEN
      SELECT COALESCE(SUM(CASE WHEN entry_kind = 'remitted'
               THEN amount_minor_units ELSE 0 END), 0),
             COALESCE(SUM(CASE WHEN entry_kind = 'released_to_employee'
               THEN amount_minor_units ELSE 0 END), 0)
        INTO remitted_total, returned_total
        FROM payroll_enforcement_ledger
       WHERE supplier_id = NEW.supplier_id
         AND month_result_id = NEW.month_result_id
         AND case_id <=> NEW.case_id
         AND claim_id <=> NEW.claim_id;

      IF remitted_total + returned_total + NEW.amount_minor_units
           > allocation_total THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'Payroll enforcement allocation is over-remitted';
      END IF;
    END IF;
  ELSEIF NEW.entry_kind = 'adjustment' AND NEW.actor_user_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll enforcement adjustment requires an actor';
  END IF;
END//

-- trg_payroll_payment_allocation_validate_insert (definice z 1269_payroll_payment_ledger.sql)
DROP TRIGGER IF EXISTS trg_payroll_payment_allocation_validate_insert//

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

-- trg_payroll_payment_match_validate_insert (definice z 1559_payroll_incoming_refund_reconciliation.sql)
DROP TRIGGER IF EXISTS trg_payroll_payment_match_validate_insert//

CREATE TRIGGER trg_payroll_payment_match_validate_insert
BEFORE INSERT ON payroll_payment_matches
FOR EACH ROW
BEGIN
  DECLARE settlement_limit BIGINT UNSIGNED DEFAULT NULL;
  DECLARE allocation_liability_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE liability_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_date DATE DEFAULT NULL;
  DECLARE evidence_amount_decimal DECIMAL(15,2) DEFAULT NULL;
  DECLARE evidence_amount BIGINT UNSIGNED DEFAULT NULL;
  DECLARE evidence_currency CHAR(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_direction VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_state VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE evidence_fingerprint VARCHAR(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE liability_matched BIGINT DEFAULT 0;
  DECLARE evidence_used BIGINT UNSIGNED DEFAULT 0;
  DECLARE source_amount BIGINT DEFAULT NULL;
  DECLARE source_event VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
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

-- trg_payroll_payment_export_validate_insert (definice z 1705_payroll_payment_export_document.sql)
DROP TRIGGER IF EXISTS trg_payroll_payment_export_validate_insert//

CREATE TRIGGER trg_payroll_payment_export_validate_insert
BEFORE INSERT ON payroll_payment_exports
FOR EACH ROW
BEGIN
  DECLARE batch_snapshot_hash CHAR(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE batch_export_format VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE previous_batch_id BIGINT UNSIGNED DEFAULT NULL;
  DECLARE previous_export_format VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL;
  DECLARE previous_revision_no INT UNSIGNED DEFAULT NULL;

  SELECT batch.snapshot_hash, batch.export_format
    INTO batch_snapshot_hash, batch_export_format
    FROM payroll_payment_batches batch
   WHERE batch.supplier_id = NEW.supplier_id
     AND batch.id = NEW.batch_id;

  IF batch_snapshot_hash IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export batch does not belong to supplier';
  END IF;
  IF NEW.source_snapshot_hash <> batch_snapshot_hash THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export source snapshot differs from batch';
  END IF;
  IF NEW.export_format <> batch_export_format AND NEW.export_format <> 'pdf' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export format differs from batch';
  END IF;
  IF NEW.storage_key <> NEW.file_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll payment export content address is invalid';
  END IF;

  IF NEW.export_revision_no = 1 THEN
    IF NEW.supersedes_export_id IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export first revision cannot supersede another export';
    END IF;
  ELSE
    IF NEW.supersedes_export_id IS NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision requires its predecessor';
    END IF;

    SELECT previous.batch_id,
           previous.export_format,
           previous.export_revision_no
      INTO previous_batch_id,
           previous_export_format,
           previous_revision_no
      FROM payroll_payment_exports previous
     WHERE previous.supplier_id = NEW.supplier_id
       AND previous.id = NEW.supersedes_export_id;

    IF previous_batch_id IS NULL
      OR previous_batch_id <> NEW.batch_id
      OR previous_export_format <> NEW.export_format
      OR previous_revision_no + 1 <> NEW.export_revision_no
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Payroll payment export revision chain is inconsistent';
    END IF;
  END IF;
END//

-- trg_payroll_employment_dimension_overlap_insert (definice z 1307_payroll_dimensions.sql)
DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_insert//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_insert
BEFORE INSERT ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

-- trg_payroll_employment_dimension_overlap_update (definice z 1307_payroll_dimensions.sql)
DROP TRIGGER IF EXISTS trg_payroll_employment_dimension_overlap_update//

CREATE TRIGGER trg_payroll_employment_dimension_overlap_update
BEFORE UPDATE ON payroll_employment_dimensions
FOR EACH ROW
BEGIN
  DECLARE new_type VARCHAR(20) COLLATE utf8mb4_unicode_ci;
  IF NEW.supplier_id <> OLD.supplier_id OR NEW.id <> OLD.id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension ownership is immutable';
  END IF;

  IF NEW.row_version <= OLD.row_version THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension row version must increase';
  END IF;

  SET new_type = (
    SELECT d.dimension_type
      FROM payroll_dimensions d
     WHERE d.supplier_id = NEW.supplier_id AND d.id = NEW.dimension_id
  );
  IF new_type IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll dimension not found for assignment';
  END IF;

  IF EXISTS (
    SELECT 1
      FROM payroll_employment_dimensions ed
      JOIN payroll_dimensions d
        ON d.supplier_id = ed.supplier_id AND d.id = ed.dimension_id
     WHERE ed.supplier_id = NEW.supplier_id
       AND ed.employment_id = NEW.employment_id
       AND ed.id <> NEW.id
       AND d.dimension_type = new_type
       AND ed.valid_from <= COALESCE(NEW.valid_to, '9999-12-31')
       AND COALESCE(ed.valid_to, '9999-12-31') >= NEW.valid_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employment dimension intervals overlap';
  END IF;
END//

-- trg_submission_outbox_inbox_privacy_insert (definice z 1580_submission_inbox_verified_privacy_purge.sql)
DROP TRIGGER IF EXISTS trg_submission_outbox_inbox_privacy_insert//

CREATE TRIGGER trg_submission_outbox_inbox_privacy_insert
BEFORE INSERT ON submission_outbox
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16) COLLATE utf8mb4_unicode_ci;
  IF NEW.receipt_inbox_message_id IS NOT NULL THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.receipt_inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze navázat jako doručenku.';
    END IF;
  END IF;
END//

-- trg_submission_outbox_inbox_privacy_update (definice z 1580_submission_inbox_verified_privacy_purge.sql)
DROP TRIGGER IF EXISTS trg_submission_outbox_inbox_privacy_update//

CREATE TRIGGER trg_submission_outbox_inbox_privacy_update
BEFORE UPDATE ON submission_outbox
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16) COLLATE utf8mb4_unicode_ci;
  IF NEW.receipt_inbox_message_id IS NOT NULL
     AND NOT (NEW.receipt_inbox_message_id <=> OLD.receipt_inbox_message_id) THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.receipt_inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze navázat jako doručenku.';
    END IF;
  END IF;
END//

-- trg_submission_defect_notice_inbox_privacy (definice z 1581_submission_defect_notice_optional_inbox.sql)
DROP TRIGGER IF EXISTS trg_submission_defect_notice_inbox_privacy//

CREATE TRIGGER trg_submission_defect_notice_inbox_privacy
BEFORE INSERT ON submission_defect_notices
FOR EACH ROW
BEGIN
  DECLARE inbox_state VARCHAR(16) COLLATE utf8mb4_unicode_ci;
  IF NEW.inbox_message_id IS NOT NULL THEN
    SET inbox_state = (
      SELECT local_content_state
        FROM submission_inbox_messages
       WHERE supplier_id = NEW.supplier_id AND id = NEW.inbox_message_id
    );
    IF inbox_state IS NULL OR inbox_state <> 'available' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Příchozí zprávu během mazání nelze zaevidovat jako výzvu.';
    END IF;
  END IF;
END//

DELIMITER ;
