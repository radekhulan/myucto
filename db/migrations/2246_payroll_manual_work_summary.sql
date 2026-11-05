-- Ručně doložený souhrn pracovního měsíce; jednotka je 1/3000 hodiny.
ALTER TABLE payroll_time_months
  MODIFY COLUMN work_source ENUM('entries','import_summary','manual_summary') NOT NULL DEFAULT 'entries';

CREATE TABLE IF NOT EXISTS payroll_time_month_manual_summaries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  time_month_id BIGINT UNSIGNED NOT NULL,
  time_month_revision_no SMALLINT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_manual_summary_tenant_id (supplier_id, id),
  UNIQUE KEY uq_manual_summary_revision (supplier_id, time_month_id, time_month_revision_no),
  KEY idx_manual_summary_employment (supplier_id, employment_id, period_start),
  CONSTRAINT fk_manual_summary_month FOREIGN KEY (supplier_id, time_month_id)
    REFERENCES payroll_time_months (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_manual_summary_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_manual_summary_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_manual_summary_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_manual_summary_hash CHECK (content_sha256 REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_time_month_manual_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  summary_id BIGINT UNSIGNED NOT NULL,
  sequence_no SMALLINT UNSIGNED NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  worked_duration_units BIGINT UNSIGNED NOT NULL,
  overtime_duration_units BIGINT UNSIGNED NULL,
  worked_days TINYINT UNSIGNED NULL,
  UNIQUE KEY uq_manual_segment_sequence (supplier_id, summary_id, sequence_no),
  CONSTRAINT fk_manual_segment_summary FOREIGN KEY (supplier_id, summary_id)
    REFERENCES payroll_time_month_manual_summaries (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_manual_segment_dates CHECK (date_to >= date_from),
  CONSTRAINT chk_manual_segment_overtime CHECK (overtime_duration_units IS NULL OR overtime_duration_units <= worked_duration_units)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_manual_summary_no_update BEFORE UPDATE ON payroll_time_month_manual_summaries
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll manual work summaries are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_manual_summary_no_delete BEFORE DELETE ON payroll_time_month_manual_summaries
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll manual work summaries are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_manual_segment_no_update BEFORE UPDATE ON payroll_time_month_manual_segments
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll manual work segments are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_manual_segment_no_delete BEFORE DELETE ON payroll_time_month_manual_segments
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll manual work segments are immutable'; END//
DELIMITER ;
