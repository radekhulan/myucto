-- Placené náhradní volno za svátek (§ 115) se nesmí zaměnit za volno za přesčas.
ALTER TABLE payroll_absences
  MODIFY COLUMN absence_type ENUM(
    'vacation','dpn','quarantine','ocr','long_term_care','ppm','paternity',
    'parental','unpaid_leave','employee_obstacle','employer_obstacle',
    'compensatory_time_off','unexcused','other',
    'public_function','employee_obstacle_unpaid','invalid_termination',
    'holiday_compensatory_time_off'
  ) NOT NULL,
  MODIFY COLUMN compensation_policy ENUM(
    'none','average_100','average_custom','dpn','statutory_manual_review','hourly_claim'
  ) NOT NULL DEFAULT 'statutory_manual_review';
