-- Doložené příplatkové vrstvy a volby náhrad; původní revize se nepřepisují.
CREATE TABLE IF NOT EXISTS payroll_hourly_evidence_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  employment_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  revision_no INT UNSIGNED NOT NULL,
  work_source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_json LONGTEXT NOT NULL CHECK (JSON_VALID(payload_json)),
  content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hourly_evidence_id (supplier_id, id),
  UNIQUE KEY uq_hourly_evidence_revision (supplier_id, employment_id, period_start, revision_no),
  CONSTRAINT fk_hourly_evidence_employment FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_hourly_evidence_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_hourly_evidence_period CHECK (DAY(period_start) = 1),
  CONSTRAINT chk_hourly_evidence_revision CHECK (revision_no > 0),
  CONSTRAINT chk_hourly_evidence_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_hourly_evidence_no_update BEFORE UPDATE ON payroll_hourly_evidence_revisions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly evidence revisions are immutable'; END//
CREATE TRIGGER IF NOT EXISTS trg_hourly_evidence_no_delete BEFORE DELETE ON payroll_hourly_evidence_revisions
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payroll hourly evidence revisions are immutable'; END//
DELIMITER ;
