-- MyUcto.cz - NEMPRI a HZUPN: evidence případu dávky nemocenského pojištění.
--
-- Do teď byly obě agendy v `PayrollStatutoryAgendaCatalog` vedené jako
-- `manual_review` s `transport_capability = not_supported`: připnuté XSD
-- NEMPRI25 a HZUPN20 sice v repozitáři ležela (`CsszSchemaCatalog`), ale nikdo
-- je nevolal a workflow znělo „podej v oficiálním kanálu a ulož potvrzení".
-- Povinnost přitom NENÍ volitelná a NENÍ nahrazená měsíčním hlášením:
--
--   § 97 odst. 1 zák. č. 187/2006 Sb.: „Zaměstnavatel je povinen přijímat
--   žádosti podle § 109 odst. 1 písm. b) bodu 1 svých zaměstnaných osob
--   o dávky, s výjimkou nemocenského, a další podklady potřebné pro stanovení
--   nároku na dávky a jejich výplatu a neprodleně je spolu s údaji potřebnými
--   pro výpočet dávek předávat územní správě sociálního zabezpečení."
--
--   § 97 odst. 2 věta druhá: „Podklady pro výpočet nemocenského a údaje
--   o způsobu výplaty mzdy, platu nebo odměny zaměstnavatel zasílá územní
--   správě sociálního zabezpečení NEPRODLENĚ PO UPLYNUTÍ PRVNÍCH 14 DNŮ trvání
--   dočasné pracovní neschopnosti nebo trvání nařízené karantény v elektronické
--   podobě (…)."
--
--   § 97 odst. 3: „Zaměstnavatel je povinen územní správě sociálního
--   zabezpečení neprodleně oznamovat též všechny skutečnosti, které mohou mít
--   vliv na výplatu dávek." — tohle je právní základ HZUPN, tedy hlášení
--   zaměstnavatele při ukončení pracovní neschopnosti.
--
--   § 97 odst. 5 (vyrovnávací příspěvek): údaje podle § 44 se předávají
--   „nejpozději v následující pracovní den po dni, který je určen pro výplatu
--   mezd a platů". Jediná z lhůt téhle agendy, která je vyjádřená dnem.
--
-- Nesplnění je přestupek podle § 130 odst. 1 písm. c) a d) téhož zákona.
--
-- ## Proč vlastní tabulka a ne sloupce u absence
--
-- Absence v `payroll_absences` je MZDOVÝ fakt (co se proplácí, co se krátí).
-- Případ dávky je PODÁNÍ: má vlastní kód OSSZ, vlastní číslo rozhodnutí,
-- vlastní stav u ČSSZ a vlastní lhůtu, která běží i tehdy, když se za měsíc
-- ještě nepočítala mzda. Kdyby seděl v absenci, zmizel by při každé opravě
-- mzdy — a lhůta podle § 97 odst. 2 s ním.
--
-- ## Proč `rozhodneObdobi` v tabulce NENÍ
--
-- Od 1. 4. 2026 se údaje potřebné pro výpočet dávek předávají VÝHRADNĚ
-- jednotným měsíčním hlášením (§ 97 odst. 4 věta první). Vyplňovat započitatelný
-- příjem a vyloučené dny do NEMPRI by znamenalo vykázat tutéž věc dvakrát
-- a rozejít se s JMHZ. `CtRozhodneObdobi` je v XSD `minOccurs="0"` právě proto;
-- serializer ho vědomě nikdy nestaví.
--
-- ## Podporované druhy dávky
--
-- `benefit_kind` drží celý číselník `StDruhDavky` (NEM, VPM, OPP, PPM, OSE,
-- DLO), protože evidence případu má smysl u všech. Sestavit datovou větu ale
-- aplikace umí jen u NEM a VPM: `CtNem` i `CtVpm` obsahují VÝHRADNĚ
-- `potvrzeniZamestnavatele`, tedy údaje, které zaměstnavatel opravdu má.
-- OPP, PPM, OSE a DLO navíc vyžadují `zadostODavku` — údaje o dítěti,
-- o ošetřované osobě, o důvodu péče a o vztahu k ní, které zaměstnavatel
-- nedrží a odhadnout je nesmí. Ty proto končí fail-closed s vlastním
-- důvodovým kódem, ne vymyšlenou datovou větou.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sickness_cases (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 INT UNSIGNED NOT NULL,
  environment                 ENUM('production','test') NOT NULL,
  employee_id                 BIGINT UNSIGNED NOT NULL,
  employment_id               BIGINT UNSIGNED NOT NULL,

  -- StDruhDavky z NEMPRI25.xsd. Uloženo velkými písmeny přesně tak, jak to
  -- vyžaduje enumerace v XSD — překlad by znamenal druhé místo, kde se dá
  -- splést druh dávky.
  benefit_kind                ENUM('NEM','VPM','OPP','PPM','OSE','DLO') NOT NULL,

  -- Kód pracoviště z CIS_PRACOVIST; `dokument/kodOSSZ` je v obou XSD omezený
  -- na 100-999.
  ossz_code                   SMALLINT UNSIGNED NOT NULL,

  -- `dokument/cisloRozhodnuti` (NEMPRI) resp. `dokument/cisloPotvrzeni`
  -- (HZUPN): číslo rozhodnutí o dočasné pracovní neschopnosti, u eNeschopenky
  -- číslo ČÍPE. Obě XSD ho mají na maxLength 18.
  decision_number             VARCHAR(18) NULL,

  -- `dokument/zahranicni`. HZUPN k tomu má dokumentaci: „N" je výchozí (CZ, SK),
  -- „A" znamená zahraniční mimo Česka a Slovenska.
  foreign_case                TINYINT(1) NOT NULL DEFAULT 0,

  -- `dokument/opravnePodani`. Opravné podání nahrazuje dřívější; ČSSZ ho páruje
  -- podle čísla rozhodnutí, takže bez něj opravu podat nelze.
  correction                  TINYINT(1) NOT NULL DEFAULT 0,

  incapacity_from             DATE NOT NULL,
  incapacity_to               DATE NULL,

  -- `dokument/datumVystaveni` v HZUPN. Den vystavení hlášení, ne den ukončení
  -- neschopnosti — ta dvě data se běžně liší.
  issued_on                   DATE NULL,

  -- Den určený pro výplatu mezd a platů. Používá se JEN u vyrovnávacího
  -- příspěvku v těhotenství a mateřství: § 97 odst. 5 od něj odvozuje jedinou
  -- lhůtu téhle agendy, která je v zákoně vyjádřená dnem („nejpozději
  -- v následující pracovní den po dni, který je určen pro výplatu mezd
  -- a platů"). U ostatních dávek zůstává prázdný — dosazovat sem výplatní
  -- termín z politiky zaměstnavatele by znamenalo tvrdit lhůtu tam, kde ji
  -- zákon neváže na výplatu.
  payroll_payment_date        DATE NULL,

  -- --- potvrzení zaměstnavatele, společná část (CtPotvrzeniZamestnavateleBaseType)
  worked_on_decisive_day      TINYINT(1) NOT NULL DEFAULT 0,
  hours_worked                DECIMAL(7,2) NULL,
  daily_working_hours         DECIMAL(4,2) NULL,
  small_scope_income_minor    BIGINT UNSIGNED NULL,

  -- --- potvrzení zaměstnavatele, část NEM/VPM
  receives_pension            TINYINT(1) NOT NULL DEFAULT 0,
  pension_kind                VARCHAR(3) NULL,
  is_student                  TINYINT(1) NOT NULL DEFAULT 0,
  within_school_holidays      TINYINT(1) NULL,
  first_employment_free_time  TINYINT(1) NOT NULL DEFAULT 0,
  -- Pracovní volno bez náhrady příjmu je jen v NEM; VPM prvek `volnoBezNahrady`
  -- v XSD vůbec nemá, takže se u něj nesmí serializovat.
  unpaid_leave                TINYINT(1) NOT NULL DEFAULT 0,
  unpaid_leave_from           DATE NULL,
  unpaid_leave_to             DATE NULL,
  starts_maternity            TINYINT(1) NULL,
  child_birth_date            DATE NULL,
  transferred_other_work      TINYINT(1) NOT NULL DEFAULT 0,
  transferred_on              DATE NULL,
  enforcement                 TINYINT(1) NOT NULL DEFAULT 0,
  insolvency                  TINYINT(1) NOT NULL DEFAULT 0,

  -- --- HZUPN: potvrzeniZamestnavatele
  returned_to_work            TINYINT(1) NULL,
  return_reason               VARCHAR(200) NULL,
  returned_on                 DATE NULL,
  hours_worked_last_day       DECIMAL(4,2) NULL,
  shift_hours_last_day        DECIMAL(4,2) NULL,

  -- Volitelné sdělení pro OSSZ, `datovaVeta/dalsiSdeleni`, maxLength 200.
  additional_note             VARCHAR(200) NULL,

  status                      ENUM(
                                'draft',
                                'prepared',
                                'submitted',
                                'accepted',
                                'rejected',
                                'cancelled'
                              ) NOT NULL DEFAULT 'draft',
  accepted_on                 DATE NULL,
  rejection_reason            VARCHAR(190) NULL,

  nempri_submission_id        BIGINT UNSIGNED NULL,
  hzupn_submission_id         BIGINT UNSIGNED NULL,

  row_version                 INT UNSIGNED NOT NULL DEFAULT 1,
  created_by                  BIGINT UNSIGNED NOT NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_sickness_case_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_sickness_case_environment_id
    (supplier_id, environment, id),
  -- Jeden případ na vztah a den vzniku sociální události. Druhý řádek o téže
  -- neschopnosti by znamenal dvě podání za tutéž věc; ČSSZ je spáruje podle
  -- čísla rozhodnutí a druhé odmítne.
  UNIQUE KEY uq_payroll_sickness_case_scope
    (supplier_id, environment, employment_id, benefit_kind, incapacity_from),
  KEY idx_payroll_sickness_case_employee
    (supplier_id, environment, employee_id, incapacity_from),
  KEY idx_payroll_sickness_case_status
    (supplier_id, environment, status, incapacity_from),

  CONSTRAINT fk_payroll_sickness_case_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_case_employment
    FOREIGN KEY (supplier_id, employment_id)
    REFERENCES payroll_employments (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_case_nempri_submission
    FOREIGN KEY (supplier_id, environment, nempri_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_case_hzupn_submission
    FOREIGN KEY (supplier_id, environment, hzupn_submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_sickness_case_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB neumí IF NOT EXISTS u CHECK, takže se každé omezení nejdřív zahodí.

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_period;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_period
    CHECK (incapacity_to IS NULL OR incapacity_to >= incapacity_from);

-- Kód OSSZ podle CIS_PRACOVIST; `kodOSSZ` je v NEMPRI25.xsd i v HZUPN20 v1.2
-- omezený na 100 až 999 včetně.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_ossz;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_ossz
    CHECK (ossz_code BETWEEN 100 AND 999);

-- Opravné podání se páruje podle čísla rozhodnutí. Bez něj by ČSSZ neměla co
-- nahradit a oprava by se zpracovala jako nové podání.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_correction;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_correction
    CHECK (correction = 0 OR decision_number IS NOT NULL);

-- Pracovní volno bez náhrady příjmu musí mít období; jinak nelze posoudit,
-- které dny se z rozhodného období vylučují.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_unpaid_leave;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_unpaid_leave
    CHECK (
      (unpaid_leave = 1 AND unpaid_leave_from IS NOT NULL
        AND (unpaid_leave_to IS NULL OR unpaid_leave_to >= unpaid_leave_from))
      OR (unpaid_leave = 0 AND unpaid_leave_from IS NULL
        AND unpaid_leave_to IS NULL)
    );

-- Převedení na jinou práci bez data je údaj, ze kterého se nedá spočítat
-- vyrovnávací příspěvek (§ 42 odst. 1 až 3).
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_transfer;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_transfer
    CHECK (
      (transferred_other_work = 1 AND transferred_on IS NOT NULL)
      OR (transferred_other_work = 0 AND transferred_on IS NULL)
    );

-- Přijatý případ nesmí existovat bez dne doručení ČSSZ: lhůta podle
-- § 97 odst. 2 je splněná DORUČENÍM, ne kliknutím na Připravit.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_accepted;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_accepted
    CHECK (
      (status = 'accepted' AND accepted_on IS NOT NULL)
      OR (status <> 'accepted' AND accepted_on IS NULL)
    );

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_rejected;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_rejected
    CHECK (
      (status = 'rejected' AND rejection_reason IS NOT NULL)
      OR (status <> 'rejected' AND rejection_reason IS NULL)
    );

-- Návrat do práce z HZUPN. `datumNavratDoPrace` bez příznaku návratu je údaj,
-- který si protiřečí sám se sebou.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_return;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_return
    CHECK (
      returned_to_work IS NULL
      OR (returned_to_work = 1 AND returned_on IS NOT NULL)
      OR (returned_to_work = 0 AND returned_on IS NULL)
    );

-- `pracovniDoba` a `pocetOdpracHodinPoslDenPD` jsou v obou XSD typu
-- StDoublePracDoba, tedy 0 až 24 hodin.
-- Vyrovnávací příspěvek je jediná dávka, u které § 97 odst. 5 váže lhůtu na
-- výplatní den; u ostatních by vyplněný den tvrdil lhůtu, která pro ně
-- neplatí.
ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_payment_date;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_payment_date
    CHECK (payroll_payment_date IS NULL OR benefit_kind = 'VPM');

ALTER TABLE payroll_sickness_cases
  DROP CONSTRAINT IF EXISTS chk_payroll_sickness_case_hours;
ALTER TABLE payroll_sickness_cases
  ADD CONSTRAINT chk_payroll_sickness_case_hours
    CHECK (
      (daily_working_hours IS NULL
        OR (daily_working_hours >= 0 AND daily_working_hours <= 24))
      AND (hours_worked_last_day IS NULL
        OR (hours_worked_last_day >= 0 AND hours_worked_last_day <= 24))
      AND (shift_hours_last_day IS NULL
        OR (shift_hours_last_day >= 0 AND shift_hours_last_day <= 24))
      AND (hours_worked IS NULL
        OR (hours_worked >= 0 AND hours_worked <= 99999))
    );
