-- MyÚčto.cz — MZ-21: exactní porovnání externí reference REGZEC.

SET NAMES utf8mb4;

ALTER TABLE payroll_registration_event_snapshots
  MODIFY source_reference VARCHAR(191)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
