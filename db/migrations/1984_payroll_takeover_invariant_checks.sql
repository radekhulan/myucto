-- Výsledek kontroly invariantů převzetí mezd (brána G2) po posledním převodu.
--
-- PROČ: převod z jiného mzdového programu (PAMICA, PREMIER, import hlášení JMHZ)
-- po dokončení ověří, že převzaté úhrny visí na vztazích, že každý zdrojový vztah
-- s výplatou má vztah v MyÚčtu a že součty po osobě a měsíci sedí na zdroj.
-- Poslední dvě kontroly potřebují zdrojová data, která po převodu nikdo po ruce
-- nemá; Kontrola převzetí je proto čte odsud. Kontroly nad vlastními daty
-- (osoba dvakrát, překryv verzí) počítá stránka znovu.
--
-- GRANULARITA: jeden řádek = firma × zdroj, opakovaný převod ho přepíše.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, CHECK jen uvnitř něj.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_takeover_invariant_checks (
  supplier_id      INT UNSIGNED NOT NULL,
  -- Zdroj převzatých mezd, stejné hodnoty jako payroll_migration_reference_totals.source.
  source           VARCHAR(32) NOT NULL,
  checked_at       DATETIME NOT NULL,
  violation_count  INT UNSIGNED NOT NULL DEFAULT 0,
  result_json      LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                   COMMENT 'porušení invariantů (PayrollTakeoverInvariants), bez jmen osob',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (supplier_id, source),
  CONSTRAINT fk_ptic_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_ptic_result_json
    CHECK (JSON_VALID(result_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
