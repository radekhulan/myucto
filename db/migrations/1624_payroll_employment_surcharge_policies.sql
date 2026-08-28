-- MyÚčto.cz — W16: sjednané zásady zákonných příplatků § 114 až § 118 ZP.
--
-- CO SEM PATŘÍ A CO NE
--
-- Ruleset drží ZÁKON (sazby, základy, lhůty pro náhradní volno). Tahle tabulka
-- drží SMLOUVU — tedy to, co si zaměstnavatel se zaměstnancem sjednal nad rámec
-- zákonného minima nebo místo něj. Rozdělení není kosmetické: sjednaná sazba
-- z kolektivní smlouvy není legislativa a kdyby se dostala do dodané sady,
-- otisk sady by se lišil firmu od firmy a přestala by být poznána jako dodaná
-- (`VendorRulesetManifest::CONTENT_HASHES`).
--
-- PROČ NA ÚROVNI PRACOVNÍHO VZTAHU, NE FIRMY
--
-- § 114 odst. 3 („mzda sjednána už s přihlédnutím k práci přesčas") se běžně
-- vztahuje jen na vedoucí zaměstnance, ne na celou firmu. Náhradní volno za
-- svátek podle § 115 se sjednává v pracovní smlouvě. Zásada na úrovni firmy by
-- tedy jednomu zaměstnanci upírala příplatek, na který mu vzniká nárok — a to
-- je přesně ten druh vady, který se pozná až po kontrole.
--
-- VÝCHOZÍ CHOVÁNÍ BEZ ŘÁDKU
--
-- Řádek se NEZAKLÁDÁ automaticky a jeho absence NEZNAMENÁ nulu:
--
--   * přesčas — bez řádku platí § 114 odst. 1, tedy PŘÍPLATEK. Zákon ho
--     přiznává bez jakékoli dohody, takže chybějící evidence výpočtu nebrání.
--   * svátek  — bez řádku platí § 115 odst. 1, tedy NÁHRADNÍ VOLNO. Vyplatit
--     příplatek bez dohody podle odst. 2 by znamenalo zaplatit něco, na co bez
--     dohody nárok není. Aplikace proto u evidované práce ve svátek bez řádku
--     FAIL-CLOSED odmítne počítat, viz
--     `PayrollSurchargeEvidence::HOLIDAY_ARRANGEMENT_MISSING`.
--
-- SAZBA V BÁZOVÝCH BODECH
--
-- Ne DECIMAL: desetinné číslo se z ovladače vrací řetězcem, jehož tvar závisí
-- na nastavení, a `DecimalRate` je na kanonický tvar citlivá. Celé číslo
-- desetitisícin je jednoznačné a bezztrátové (25 % = 2500, 100 % = 10000).
--
-- Podlézt zákonné minimum smí jen noční práce a víkend, protože jen § 116
-- a § 118 obsahují větu „Je možné sjednat jinou minimální výši a způsob určení
-- příplatku". § 114, § 115 a § 117 mají kogentní „nejméně". Databáze to hlídat
-- neumí (minimum je v rulesetu, ne ve sloupci), takže to hlídá
-- `PayrollSurchargePolicy::assertAgreedRateIsLawful()`; CHECK tu drží jen mez
-- rozsahu, aby se do sloupce nedostal nesmysl.
--
-- VERZOVÁNÍ
--
-- Zásada se v čase mění (nová kolektivní smlouva) a mzdy zpětně přepočítat
-- nesmí. Verzuje se proto stejně jako podmínky vztahu a mzdové složky:
-- `valid_from`/`valid_to`, nová verze vedle staré, historie se nepřepisuje.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_employment_surcharge_policies (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  employment_id                 BIGINT UNSIGNED NOT NULL,
  valid_from                    DATE NOT NULL,
  valid_to                      DATE NULL,
  -- § 114 odst. 1 a 3.
  overtime_mode                 ENUM('surcharge','compensatory_time_off','included_in_wage')
                                  NOT NULL DEFAULT 'surcharge',
  -- § 115 odst. 1 a 2. `included_in_wage` se tu záměrně NENABÍZÍ: mzda sjednaná
  -- s přihlédnutím k práci ve svátek neexistuje, odst. 3 § 114 se týká jen přesčasu.
  holiday_mode                  ENUM('compensatory_time_off','surcharge')
                                  NOT NULL DEFAULT 'compensatory_time_off',
  -- § 117 — počet ztěžujících vlivů podle nařízení vlády č. 443/2024 Sb.
  -- NULL = není doloženo; § 117 se pak nepočítá a hlásí chybějící podklad.
  -- Jednotlivý zápis docházky smí tenhle výchozí počet přebít
  -- (`payroll_time_entries.difficulty_factor_count`, migrace 1625).
  difficult_environment_factors TINYINT UNSIGNED NULL,
  overtime_rate_bp              SMALLINT UNSIGNED NULL,
  holiday_rate_bp               SMALLINT UNSIGNED NULL,
  night_rate_bp                 SMALLINT UNSIGNED NULL,
  weekend_rate_bp               SMALLINT UNSIGNED NULL,
  difficult_environment_rate_bp SMALLINT UNSIGNED NULL,
  agreement_reference           VARCHAR(191) NULL,
  note                          VARCHAR(500) NULL,
  row_version                   INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                    BIGINT UNSIGNED NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_surcharge_policy_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_surcharge_policy_version
    (supplier_id, employment_id, valid_from),
  KEY idx_payroll_surcharge_policy_effective
    (supplier_id, employment_id, valid_from, valid_to),
  CONSTRAINT fk_payroll_surcharge_policy_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id)
    ON DELETE CASCADE,
  CONSTRAINT fk_payroll_surcharge_policy_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí u CHECK `IF NOT EXISTS`, takže se nejdřív zahazuje.
ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_interval;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_interval
  CHECK (valid_to IS NULL OR valid_to >= valid_from);

ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_factors;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_factors
  CHECK (difficult_environment_factors IS NULL OR difficult_environment_factors >= 1);

-- Horní mez je vědomě velkorysá. Sazba NAD 100 % je legitimní: § 115 má zákonné
-- minimum rovných 100 % průměrného výdělku, takže cokoli sjednaného nad rámec
-- zákona musí být vyšší, a kdyby CHECK končil na 10000, nešel by sjednat vůbec.
-- Mez je jen proti překlepu o řád, spodní hranu (kogentní „nejméně" u § 114,
-- § 115 a § 117) hlídá aplikace proti rulesetu, ne tenhle sloupec.
ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_rates;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_rates
  CHECK (
        (overtime_rate_bp IS NULL OR overtime_rate_bp BETWEEN 1 AND 50000)
    AND (holiday_rate_bp IS NULL OR holiday_rate_bp BETWEEN 1 AND 50000)
    AND (night_rate_bp IS NULL OR night_rate_bp BETWEEN 1 AND 50000)
    AND (weekend_rate_bp IS NULL OR weekend_rate_bp BETWEEN 1 AND 50000)
    AND (difficult_environment_rate_bp IS NULL
         OR difficult_environment_rate_bp BETWEEN 1 AND 50000)
  );

ALTER TABLE payroll_employment_surcharge_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_surcharge_policy_row_version;
ALTER TABLE payroll_employment_surcharge_policies
  ADD CONSTRAINT chk_payroll_surcharge_policy_row_version
  CHECK (row_version > 0);
