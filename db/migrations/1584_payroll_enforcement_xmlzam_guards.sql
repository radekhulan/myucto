SET NAMES utf8mb4;

DELIMITER //

CREATE OR REPLACE TRIGGER trg_xmlzam_request_source_guard
BEFORE INSERT ON payroll_enforcement_xmlzam_requests
FOR EACH ROW
BEGIN
  DECLARE valid_source_count INT DEFAULT 0;

  SELECT COUNT(*) INTO valid_source_count
    FROM submission_inbox_messages inbox
    JOIN documents child
      ON child.supplier_id = inbox.supplier_id
     AND child.id = NEW.source_document_id
     AND child.parent_document_id = inbox.document_id
    JOIN document_files file_row
      ON file_row.supplier_id = inbox.supplier_id
     AND file_row.id = NEW.source_document_file_id
     AND file_row.document_id = child.id
   WHERE inbox.supplier_id = NEW.supplier_id
     AND inbox.environment = NEW.environment
     AND inbox.channel = 'isds'
     AND inbox.id = NEW.inbox_message_id
     AND inbox.hidden_at IS NULL
     AND inbox.local_content_state = 'available'
     AND child.source = 'zfo_extract'
     AND child.deleted_at IS NULL
     AND file_row.deleted_at IS NULL
     AND file_row.sha256 = NEW.source_xml_sha256
     AND LOWER(inbox.sender_box_id) = LOWER(NEW.executor_box_id);

  IF valid_source_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM request source must be one verified ISDS child attachment';
  END IF;
END//

CREATE OR REPLACE TRIGGER trg_xmlzam_response_source_guard
BEFORE INSERT ON payroll_enforcement_xmlzam_responses
FOR EACH ROW
BEGIN
  DECLARE valid_binding_count INT DEFAULT 0;
  DECLARE manifest_rows INT DEFAULT 0;
  DECLARE approved_rows INT DEFAULT 0;

  SELECT COUNT(*) INTO valid_binding_count
    FROM payroll_enforcement_xmlzam_requests request_row
    JOIN payroll_enforcement_cases case_row
      ON case_row.supplier_id = request_row.supplier_id
     AND case_row.id = NEW.case_id
     AND case_row.employee_id = request_row.employee_id
   WHERE request_row.supplier_id = NEW.supplier_id
     AND request_row.environment = NEW.environment
     AND request_row.id = NEW.request_id;

  IF valid_binding_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM response request, environment, employee and case do not match';
  END IF;

  IF BINARY SHA2(NEW.source_manifest_json, 256) <> BINARY NEW.source_manifest_sha256 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM response source manifest hash does not match';
  END IF;

  SELECT COUNT(*) INTO manifest_rows
    FROM JSON_TABLE(
      NEW.source_manifest_json,
      '$[*]' COLUMNS (
        period_start VARCHAR(10) PATH '$.period',
        revision_id BIGINT UNSIGNED PATH '$.revision_id',
        input_hash CHAR(64) PATH '$.input_hash',
        result_hash CHAR(64) PATH '$.result_hash',
        enforcement_input_hash CHAR(64) PATH '$.enforcement_input_hash'
      )
    ) AS manifest;

  SELECT COUNT(*) INTO approved_rows
    FROM JSON_TABLE(
      NEW.source_manifest_json,
      '$[*]' COLUMNS (
        period_start VARCHAR(10) PATH '$.period',
        revision_id BIGINT UNSIGNED PATH '$.revision_id',
        input_hash CHAR(64) PATH '$.input_hash',
        result_hash CHAR(64) PATH '$.result_hash',
        enforcement_input_hash CHAR(64) PATH '$.enforcement_input_hash'
      )
    ) AS manifest
    JOIN payroll_run_revisions revision
      ON revision.supplier_id = NEW.supplier_id
     AND revision.id = manifest.revision_id
     AND revision.status = 'approved'
     AND BINARY revision.input_snapshot_hash = BINARY manifest.input_hash
     AND BINARY revision.result_snapshot_hash = BINARY manifest.result_hash
    JOIN payroll_runs run_row
      ON run_row.supplier_id = revision.supplier_id
     AND run_row.id = revision.run_id
     AND BINARY DATE_FORMAT(run_row.period_start, '%Y-%m') = BINARY manifest.period_start
    JOIN payroll_enforcement_month_results enforcement
      ON enforcement.supplier_id = revision.supplier_id
     AND enforcement.revision_id = revision.id
     AND enforcement.employee_id = (
       SELECT request_row.employee_id
         FROM payroll_enforcement_xmlzam_requests request_row
        WHERE request_row.supplier_id = NEW.supplier_id
          AND request_row.id = NEW.request_id
     )
     AND BINARY enforcement.input_snapshot_hash = BINARY manifest.enforcement_input_hash;

  IF manifest_rows < 1 OR manifest_rows <> approved_rows THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM response requires exact approved immutable payroll revision sources';
  END IF;
END//

CREATE OR REPLACE TRIGGER trg_xmlzam_dispatch_outbox_guard
BEFORE INSERT ON payroll_enforcement_xmlzam_dispatches
FOR EACH ROW
BEGIN
  DECLARE valid_dispatch_count INT DEFAULT 0;

  SELECT COUNT(*) INTO valid_dispatch_count
    FROM payroll_enforcement_xmlzam_responses response_row
    JOIN payroll_enforcement_xmlzam_requests request_row
      ON request_row.supplier_id = response_row.supplier_id
     AND request_row.id = response_row.request_id
    JOIN submission_outbox outbox_row
      ON outbox_row.supplier_id = response_row.supplier_id
     AND outbox_row.id = NEW.outbox_id
   WHERE response_row.supplier_id = NEW.supplier_id
     AND response_row.environment = NEW.environment
     AND response_row.id = NEW.response_id
     AND outbox_row.environment = response_row.environment
     AND outbox_row.channel = 'isds'
     AND outbox_row.agenda_code = 'XMLZAM'
     AND outbox_row.artifact_kind = 'payroll_xmlzam'
     AND outbox_row.artifact_id = response_row.id
     AND LOWER(outbox_row.recipient_box_id) = LOWER(request_row.executor_box_id)
     AND outbox_row.dispatch_state = 'ready'
     AND outbox_row.confirmed_by IS NULL
     AND outbox_row.confirmed_at IS NULL;

  IF valid_dispatch_count <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'XMLZAM dispatch must point to an unconfirmed matching outbox artifact and executor';
  END IF;
END//

DELIMITER ;
