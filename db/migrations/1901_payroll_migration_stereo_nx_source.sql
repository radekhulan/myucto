-- Samostatný zdroj historických mezd ze Stereo NX.
--
-- `other` zůstává vyhrazený pro starší převody PREMIER a obecný tabulkový
-- import. Oddělená hodnota je nutná, aby se přehled převzatých mezd a
-- reconciliation nemíchaly mezi dvěma původními systémy.

SET NAMES utf8mb4;

ALTER TABLE payroll_migration_reference_totals
  MODIFY COLUMN source ENUM('pamica','pohoda','money_s3','other','jmhz','stereo_nx') NOT NULL;

ALTER TABLE payroll_posting_map_proposals
  MODIFY COLUMN source ENUM('pamica','pohoda','money_s3','other','stereo_nx') NOT NULL;

-- Původní číslo této větve mezitím obsadila jiná migrace v masteru.
DELETE FROM migrations WHERE filename IN ('1867_payroll_migration_stereo_nx_source.sql',
    '1893_payroll_migration_stereo_nx_source.sql',
    '1900_payroll_migration_stereo_nx_source.sql', '1892_journal_red_storno.sql');
