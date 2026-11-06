-- Doložení průměru automatického nároku při skončení pracovního vztahu.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS payroll_termination_floor_proofs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  input_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  source_snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash BINARY(32) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_termination_floor_proof_input (supplier_id, input_id),
  KEY idx_termination_floor_proof_employment (supplier_id, employment_id, period_start),
  CONSTRAINT fk_termination_floor_proof_input FOREIGN KEY (supplier_id, input_id) REFERENCES payroll_inputs (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_termination_floor_proof_employment FOREIGN KEY (supplier_id, employment_id) REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_termination_floor_proof_no_update BEFORE UPDATE ON payroll_termination_floor_proofs FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Důkaz nároku při skončení je neměnný';
END//
DELIMITER ;
