-- MyÚčto.cz — MZ-07: auditovatelný převod schválené DPN do kanonického mzdového vstupu.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sickness_input_materializations (
  id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                INT UNSIGNED NOT NULL,
  sickness_event_id          BIGINT UNSIGNED NOT NULL,
  period_start               DATE NOT NULL,
  materialization_kind       ENUM('original','reversal') NOT NULL,
  input_id                   BIGINT UNSIGNED NOT NULL,
  reverses_materialization_id BIGINT UNSIGNED NULL,
  source_snapshot_json       LONGTEXT NOT NULL CHECK (JSON_VALID(source_snapshot_json)),
  source_snapshot_hash       BINARY(32) NOT NULL,
  created_by                 BIGINT UNSIGNED NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_sickness_input_materialization_scope
    (supplier_id, sickness_event_id, period_start, materialization_kind),
  UNIQUE KEY uq_payroll_sickness_input_materialization_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_sickness_input_materialization_input (supplier_id, input_id),
  KEY idx_payroll_sickness_input_materialization_reversal
    (supplier_id, reverses_materialization_id),
  CONSTRAINT fk_payroll_sickness_input_materialization_event
    FOREIGN KEY (supplier_id, sickness_event_id)
    REFERENCES payroll_sickness_events (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_input_materialization_input
    FOREIGN KEY (supplier_id, input_id)
    REFERENCES payroll_inputs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_input_materialization_reversal
    FOREIGN KEY (supplier_id, reverses_materialization_id)
    REFERENCES payroll_sickness_input_materializations (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_sickness_input_materialization_period
    CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_payroll_sickness_input_materialization_direction
    CHECK (
      (materialization_kind = 'original' AND reverses_materialization_id IS NULL)
      OR
      (materialization_kind = 'reversal' AND reverses_materialization_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

CREATE TRIGGER IF NOT EXISTS trg_payroll_sickness_input_materialization_immutable_update
BEFORE UPDATE ON payroll_sickness_input_materializations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll sickness input materializations are append-only';
END//

CREATE TRIGGER IF NOT EXISTS trg_payroll_sickness_input_materialization_immutable_delete
BEFORE DELETE ON payroll_sickness_input_materializations
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll sickness input materializations are append-only';
END//

DELIMITER ;
