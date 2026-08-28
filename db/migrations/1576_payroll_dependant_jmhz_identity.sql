-- Explicitní části jména dítěte pro roční JMHZ. Stávající full_name se záměrně
-- automaticky nerozděluje: u víceslovných jmen by vznikla právně chybná identita.

SET NAMES utf8mb4;

ALTER TABLE payroll_dependants
  ADD COLUMN IF NOT EXISTS given_name VARCHAR(100) NULL AFTER full_name,
  ADD COLUMN IF NOT EXISTS family_name VARCHAR(100) NULL AFTER given_name;
