-- MyÚčto.cz — vyřazení osoby z mzdového běhu.
--
-- PROČ
--
-- Zákonný výpočet ukládá výsledky jen za úplnou množinu osob snímku. Jedna osoba
-- v ruční kontrole (chybí zdravotní pojišťovna, hodnoty pro srážky) proto
-- zastavila schválení celého běhu: brigádník bez evidence držel výplatu pěti
-- stovkám lidí a z aplikace nevedla cesta ven.
--
-- CO TABULKA NESE
--
-- Rozhodnutí účetní „tuhle osobu teď nevyplácím" s důvodem a autorem. Příkaz
-- `exclude_employee` založí novou revizi bez této osoby; `include_employee`
-- osobu vrátí (další nová revize s ní). Při schválení se aktivní vyřazení
-- uzavře revizí, která šla bez osoby (`settled_revision_id`). Opravná revize
-- téhož běhu už osobu zase obsahuje, takže doplatek jde cestou „Vyžádat opravu“
-- a „Otevřít opravu“ za stejné období. Zrušení běhu aktivní vyřazení zruší
-- (`lifted_at`, `lift_reason = run_cancelled`).
--
-- Aktivní je řádek bez `lifted_at` i bez `settled_revision_id`; jedna osoba
-- má v běhu nejvýš jedno aktivní vyřazení (`active_key`).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_run_exclusions (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id           INT UNSIGNED NOT NULL,
  run_id                BIGINT UNSIGNED NOT NULL,
  employee_id           BIGINT UNSIGNED NOT NULL,
  reason                VARCHAR(500) NOT NULL,
  excluded_by           BIGINT UNSIGNED NULL,
  excluded_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  excluded_revision_no  INT UNSIGNED NOT NULL,
  lifted_at             DATETIME NULL,
  lifted_by             BIGINT UNSIGNED NULL,
  lift_reason           ENUM('included', 'run_cancelled') NULL,
  settled_revision_id   BIGINT UNSIGNED NULL,
  active_key            TINYINT UNSIGNED
    GENERATED ALWAYS AS (IF(lifted_at IS NULL AND settled_revision_id IS NULL, 1, NULL)) STORED,

  UNIQUE KEY uq_payroll_run_exclusion_active (supplier_id, run_id, employee_id, active_key),
  KEY idx_payroll_run_exclusion_employee (supplier_id, employee_id),
  CONSTRAINT fk_payroll_run_exclusion_run
    FOREIGN KEY (supplier_id, run_id)
    REFERENCES payroll_runs (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_exclusion_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_exclusion_revision
    FOREIGN KEY (supplier_id, settled_revision_id)
    REFERENCES payroll_run_revisions (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_run_exclusion_excluded_by
    FOREIGN KEY (excluded_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_run_exclusion_lifted_by
    FOREIGN KEY (lifted_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_run_exclusion_lift CHECK (
    (lifted_at IS NULL AND lift_reason IS NULL)
    OR (lifted_at IS NOT NULL AND lift_reason IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
