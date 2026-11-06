SET NAMES utf8mb4;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nonprofit_tax_immutable_update
BEFORE UPDATE ON nonprofit_tax_assessments FOR EACH ROW
BEGIN
    IF NOT (
        OLD.storage_version IN ('legacy_json_v1','legacy_json_v2')
        AND NEW.storage_version = 'paged_v2'
        AND OLD.source_manifest_id IS NULL AND OLD.funding_reference_manifest_id IS NULL
        AND OLD.funding_history_manifest_id IS NULL AND OLD.decision_manifest_id IS NULL
        AND OLD.result_manifest_id IS NULL AND OLD.coverage_manifest_id IS NULL AND OLD.usage_manifest_id IS NULL
        AND OLD.calculation_input_hash IS NULL AND OLD.result_root_hash IS NULL AND OLD.coverage_root_hash IS NULL
        AND OLD.summary_json IS NULL AND OLD.j6_receipt_json IS NULL
        AND OLD.legacy_storage_version IS NULL AND OLD.legacy_input_hash IS NULL
        AND OLD.legacy_snapshot_sha256 IS NULL AND OLD.legacy_assessment_sha256 IS NULL AND OLD.legacy_result_sha256 IS NULL
        AND OLD.sidecar_converted_at_utc IS NULL AND OLD.sidecar_converted_by IS NULL AND OLD.sidecar_conversion_reason IS NULL
        AND NEW.source_manifest_id IS NOT NULL AND NEW.funding_reference_manifest_id IS NOT NULL
        AND NEW.funding_history_manifest_id IS NOT NULL AND NEW.decision_manifest_id IS NOT NULL
        AND NEW.result_manifest_id IS NOT NULL AND NEW.coverage_manifest_id IS NOT NULL AND NEW.usage_manifest_id IS NOT NULL
        AND NEW.calculation_input_hash IS NOT NULL AND NEW.result_root_hash IS NOT NULL AND NEW.coverage_root_hash IS NOT NULL
        AND NEW.summary_json IS NOT NULL AND NEW.j6_receipt_json IS NOT NULL
        AND JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.schema')) = 'nonprofit-j6-assessment-graph.v1'
        AND NEW.input_hash = JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.snapshot.input_hash'))
        AND NEW.source_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.snapshot.manifest_id')) AS UNSIGNED)
        AND NEW.decision_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.manifest_ids.decision')) AS UNSIGNED)
        AND NEW.result_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.manifest_ids.result')) AS UNSIGNED)
        AND NEW.coverage_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.manifest_ids.coverage')) AS UNSIGNED)
        AND NEW.usage_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.manifest_ids.usage')) AS UNSIGNED)
        AND NEW.funding_reference_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.evidence.manifest_ids.funding_reference')) AS UNSIGNED)
        AND NEW.funding_history_manifest_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.evidence.manifest_ids.funding_history')) AS UNSIGNED)
        AND NEW.calculation_input_hash = JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.inputs.calculation_hash'))
        AND NEW.result_root_hash = JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.root_hashes.result'))
        AND NEW.coverage_root_hash = JSON_UNQUOTE(JSON_EXTRACT(NEW.j6_receipt_json,'$.calculation.root_hashes.coverage'))
        AND NEW.legacy_storage_version = OLD.storage_version AND NEW.legacy_input_hash = OLD.input_hash
        AND NEW.legacy_snapshot_sha256 = SHA2(OLD.snapshot_json,256)
        AND NEW.legacy_assessment_sha256 = SHA2(OLD.assessment_json,256)
        AND NEW.legacy_result_sha256 = SHA2(OLD.result_json,256)
        AND NEW.sidecar_converted_at_utc IS NOT NULL AND NEW.sidecar_converted_by IS NOT NULL
        AND NEW.sidecar_conversion_reason IS NOT NULL
        AND OLD.id = NEW.id AND OLD.supplier_id = NEW.supplier_id AND OLD.period_id = NEW.period_id
        AND OLD.revision_no = NEW.revision_no AND (OLD.previous_id <=> NEW.previous_id)
        AND OLD.status = NEW.status AND OLD.reason = NEW.reason AND OLD.ruleset = NEW.ruleset
        AND OLD.request_hash = NEW.request_hash AND OLD.idempotency_key = NEW.idempotency_key
        AND OLD.snapshot_json = NEW.snapshot_json AND OLD.assessment_json = NEW.assessment_json AND OLD.result_json = NEW.result_json
        AND OLD.created_by = NEW.created_by AND OLD.created_at = NEW.created_at
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_tax_assessment_immutable';
    END IF;
END$$
DELIMITER ;


