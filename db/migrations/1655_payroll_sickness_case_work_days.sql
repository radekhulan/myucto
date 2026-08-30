-- MyUcto.cz - HZUPN: dny, ve kterých zaměstnanec v době neschopnosti pracoval.
--
-- `HZUPN20 v1.2.xsd` má v `ctFormularHZUPN` prvek `praceVeDnech` typu
-- `CtPraceVeDnech`, což je seznam `interval` s povinnou dvojicí `pracovalOd`
-- a `pracovalDo` (`maxOccurs="unbounded"`). Bez vlastní tabulky by šel do
-- hlášení nanejvýš jeden interval, což je právě ten případ, kdy zaměstnanec
-- během neschopnosti odpracoval několik oddělených dnů — a přesně o tom
-- hlášení podle § 97 odst. 3 zákona č. 187/2006 Sb. je: „Zaměstnavatel je
-- povinen územní správě sociálního zabezpečení neprodleně oznamovat též
-- všechny skutečnosti, které mohou mít vliv na výplatu dávek."
--
-- Interval je uložený jako pár dat, ne jako počet dnů: ČSSZ z něj počítá
-- vyloučené dny a náhradu, takže „3 dny" bez konkrétních datumů je údaj,
-- ze kterého se dávka spočítat nedá.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sickness_case_work_days (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  environment       ENUM('production','test') NOT NULL,
  case_id           BIGINT UNSIGNED NOT NULL,
  worked_from       DATE NOT NULL,
  worked_to         DATE NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_sickness_work_day_scope
    (supplier_id, environment, case_id, worked_from),
  KEY idx_payroll_sickness_work_day_case
    (supplier_id, environment, case_id, worked_from, worked_to),

  CONSTRAINT fk_payroll_sickness_work_day_case
    FOREIGN KEY (supplier_id, environment, case_id)
    REFERENCES payroll_sickness_cases (supplier_id, environment, id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se omezení nejdřív zahodí.

ALTER TABLE payroll_sickness_case_work_days
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_work_day_period;
ALTER TABLE payroll_sickness_case_work_days
  ADD CONSTRAINT chk_payroll_sickness_work_day_period
    CHECK (worked_to >= worked_from);
