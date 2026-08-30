-- MyÚčto.cz — zákonné pojištění odpovědnosti zaměstnavatele (vyhláška č. 125/1993 Sb.).
--
-- Sazba se podle přílohy č. 2 vyhlášky odvíjí od PŘEVAŽUJÍCÍ ČINNOSTI
-- zaměstnavatele. Tenhle katalog (číselník OKEČ/CZ-NACE × sazba) v aplikaci
-- VĚDOMĚ není — je to samostatný pozdější úkol. Účetní sazbu zná z výměru
-- pojišťovny (Kooperativa, u zaměstnavatelů registrovaných u ní před
-- 1. 1. 1993 Česká pojišťovna) a zadává ji ručně jako nastavení firmy.
--
-- Sazba (a případně i pojistitel, mění-li firma pojišťovnu) se v čase mění,
-- proto je to DATOVANÁ položka (append-only historie podle `effective_from`),
-- ne jeden sloupec u firmy — stejný vzor jako `payroll_office_registrations`.
-- Bankovní účet a variabilní symbol pojistitele se NEUKLÁDAJÍ tady: jedou přes
-- existující ověřený registr `payroll_institution_accounts`
-- (`institution_type = 'statutory_insurance'`, viz migrace 1190), přesně jako
-- u zdravotních pojišťoven a ČSSZ — `institution_code` tady je jen odkaz na
-- konkrétní řádek toho registru.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_accident_insurance_rates (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  institution_code  VARCHAR(32) NOT NULL,
  rate_per_mille    DECIMAL(6,2) NOT NULL,
  effective_from    DATE NOT NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_accident_insurance_rate_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_accident_insurance_rate_effective (
    supplier_id, effective_from
  ),
  CONSTRAINT fk_payroll_accident_insurance_rate_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_accident_insurance_rate_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_accident_insurance_rate_value CHECK (
    rate_per_mille > 0 AND rate_per_mille <= 1000
  ),
  CONSTRAINT chk_payroll_accident_insurance_rate_code CHECK (
    institution_code REGEXP '^[A-Z0-9][A-Z0-9._-]{0,31}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
