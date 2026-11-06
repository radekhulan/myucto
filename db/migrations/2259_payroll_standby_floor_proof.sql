-- Neměnný důkaz u hodinové pohotovosti; samotná předpona není důkaz původu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_standby_floor_proofs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  input_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  source_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash BINARY(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_standby_floor_proof_input (supplier_id, input_id),
  KEY idx_standby_floor_proof_employment (supplier_id, employment_id, period_start),
  CONSTRAINT fk_standby_floor_proof_input FOREIGN KEY (supplier_id, input_id) REFERENCES payroll_inputs (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_standby_floor_proof_employment FOREIGN KEY (supplier_id, employment_id) REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_inputs DROP CONSTRAINT IF EXISTS chk_payroll_input_source_snapshot;
ALTER TABLE payroll_inputs ADD CONSTRAINT chk_payroll_input_source_snapshot CHECK (
  (recurring_component_id IS NOT NULL AND source_kind = 'recurring' AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json) AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32)
  OR (recurring_component_id IS NULL AND source_kind = 'travel' AND external_id LIKE 'travel:%' AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json) AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32)
  OR (recurring_component_id IS NULL AND source_kind NOT IN ('recurring', 'travel') AND (
    (source_snapshot_json IS NULL AND source_snapshot_hash IS NULL AND NOT (source_kind = 'time' AND COALESCE(external_id, '') LIKE 'hourly-wage:%'))
    OR (((source_kind = 'manual' AND external_id LIKE 'quick-monthly:%') OR (source_kind IN ('absence', 'correction') AND external_id LIKE 'leave:%') OR (source_kind = 'time' AND (external_id LIKE 'hourly-wage:%' OR external_id LIKE 'standby:%')))
      AND source_snapshot_json IS NOT NULL AND JSON_VALID(source_snapshot_json) AND source_snapshot_hash IS NOT NULL AND OCTET_LENGTH(source_snapshot_hash) = 32)
  ))
);

DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_standby_floor_proof_no_update BEFORE UPDATE ON payroll_standby_floor_proofs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Důkaz pohotovosti je neměnný';
END//
DELIMITER ;
