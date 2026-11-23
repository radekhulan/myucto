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

-- Ruční souhrn v10 připouští neznámé dny a přesčasy stejně jako souhrn importu.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_conditional_confirmation;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_conditional_confirmation CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND conditional_blocks_confirmed IS NULL
      AND unworked_hours_occurred IS NULL
      AND work_obstacles_occurred IS NULL
      AND unworked_total_millihours IS NULL
      AND unworked_paid_millihours IS NULL
      AND dpn_without_employer_compensation_millihours IS NULL
      AND dpn_with_employer_compensation_millihours IS NULL
      AND vacation_millihours IS NULL
      AND care_millihours IS NULL
      AND employee_obstacle_paid_millihours IS NULL
      AND employer_obstacle_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND conditional_blocks_confirmed = 1
      AND unworked_hours_occurred IS NOT NULL
      AND unworked_hours_occurred IN (0, 1)
      AND work_obstacles_occurred IS NOT NULL
      AND work_obstacles_occurred IN (0, 1)
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_absence_evidence;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_absence_evidence CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6', 'jmhz-work-month.v7', 'jmhz-work-month.v8',
        'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND maternity_millihours IS NULL
      AND paternity_millihours IS NULL
      AND parental_millihours IS NULL
      AND unpaid_leave_millihours IS NULL
      AND unexcused_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v3', 'jmhz-work-month.v4', 'jmhz-work-month.v5',
        'jmhz-work-month.v6', 'jmhz-work-month.v7', 'jmhz-work-month.v8',
        'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND (
        unworked_hours_occurred = 1
        OR (
          maternity_millihours IS NULL
          AND paternity_millihours IS NULL
          AND parental_millihours IS NULL
          AND unpaid_leave_millihours IS NULL
          AND unexcused_millihours IS NULL
        )
      )
      AND (maternity_millihours IS NULL OR maternity_millihours <= 99999999)
      AND (paternity_millihours IS NULL OR paternity_millihours <= 99999999)
      AND (parental_millihours IS NULL OR parental_millihours <= 99999999)
      AND (unpaid_leave_millihours IS NULL OR unpaid_leave_millihours <= 99999999)
      AND (unexcused_millihours IS NULL OR unexcused_millihours <= 99999999)
    )
  );

-- Intervalové souhrny mají dny povinné, importované a ruční je nemusí znát.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_worked_breakdown;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_worked_breakdown CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v6',
        'jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND worked_days IS NULL
      AND overtime_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v4', 'jmhz-work-month.v5', 'jmhz-work-month.v7',
        'jmhz-work-month.v9'
      )
      AND worked_days IS NOT NULL
      AND worked_days <= 31
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    ) OR (
      derivation_version IN ('jmhz-work-month.v6', 'jmhz-work-month.v8', 'jmhz-work-month.v10')
      AND (worked_days IS NULL OR worked_days <= 31)
      AND (
        overtime_millihours IS NULL
        OR overtime_millihours <= worked_millihours
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_compensatory_time_off;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_compensatory_time_off CHECK (
    (
      derivation_version NOT IN (
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND compensatory_time_off_millihours IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND (unworked_hours_occurred = 1 OR compensatory_time_off_millihours IS NULL)
      AND (
        compensatory_time_off_millihours IS NULL
        OR compensatory_time_off_millihours <= 99999999
      )
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_holidays;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_holidays CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10')
      AND holiday_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10')
      AND (unworked_hours_occurred = 1 OR holiday_millihours IS NULL)
      AND (holiday_millihours IS NULL OR holiday_millihours <= 99999999)
    )
  );

-- Svátek uvnitř nepřítomnosti bez mzdy nesou intervalové v9 při IN07.
ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_unpaid_holidays;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_unpaid_holidays CHECK (
    (
      derivation_version NOT IN ('jmhz-work-month.v9')
      AND holiday_unpaid_millihours IS NULL
    ) OR (
      derivation_version IN ('jmhz-work-month.v9')
      AND (unworked_hours_occurred = 1 OR holiday_unpaid_millihours IS NULL)
      AND (holiday_unpaid_millihours IS NULL OR holiday_unpaid_millihours <= 99999999)
    )
  );

ALTER TABLE payroll_jmhz_work_month_revisions
  DROP CONSTRAINT IF EXISTS chk_payroll_jmhz_work_month_control_binding;

ALTER TABLE payroll_jmhz_work_month_revisions
  ADD CONSTRAINT chk_payroll_jmhz_work_month_control_binding CHECK (
    (
      derivation_version = 'jmhz-work-month-core.v1'
      AND control_catalog_key IS NULL
      AND control_manifest_sha256 IS NULL
    ) OR (
      derivation_version IN (
        'jmhz-work-month.v2', 'jmhz-work-month.v3', 'jmhz-work-month.v4',
        'jmhz-work-month.v5', 'jmhz-work-month.v6', 'jmhz-work-month.v7',
        'jmhz-work-month.v8', 'jmhz-work-month.v9', 'jmhz-work-month.v10'
      )
      AND control_catalog_key IS NOT NULL
      AND CHAR_LENGTH(control_catalog_key) > 0
      AND control_manifest_sha256 IS NOT NULL
      AND control_manifest_sha256 REGEXP '^[0-9a-f]{64}$'
    )
  );
