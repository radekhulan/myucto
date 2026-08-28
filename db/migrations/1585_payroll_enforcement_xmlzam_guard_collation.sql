SET NAMES utf8mb4;

DELIMITER //

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

DELIMITER ;
