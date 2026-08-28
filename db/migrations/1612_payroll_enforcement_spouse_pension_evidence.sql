-- MyÚčto.cz — E-01: čtvrtina na manžela/partnera jen při doloženém důchodu.
--
-- Nařízení vlády č. 441/2024 Sb. s účinností od 1. 1. 2025 změnilo § 1 nařízení
-- vlády č. 595/2006 Sb.: manžel ani partner povinného se do nezabavitelné
-- částky nezapočítává automaticky. Čtvrtina na něj náleží jen tehdy,
-- „doloží-li povinný plátci mzdy, že jemu nebo jeho manželovi nebo partnerovi
-- byl přiznán starobní důchod, invalidní důchod pro invaliditu druhého nebo
-- třetího stupně nebo sirotčí důchod". Podmínka se váže na důchod povinného
-- NEBO manžela — stačí jeden z nich. Vyživovaných dětí se změna netýká.
--
-- Evidence patří k záznamu vyživované osoby, protože se váže k jednomu
-- konkrétnímu manželství/partnerství a je stejně jako zbytek řádku verzovaná
-- přes (dependant_key, valid_from). Doložení nebo jeho zánik se tedy zapisuje
-- novou verzí, ne přepisem historie.
--
-- Tři stavy, ne příznak ano/ne. Existující řádky manželů vznikly dřív, než
-- evidence existovala, a jejich povinní neměli šanci důchod doložit. Dostávají
-- proto 'unknown': čtvrtina se jim nezapočítá (zákon jinou možnost nedává),
-- ale měsíc se srážkou skončí v ručním posouzení s blokátorem
-- `spouse_quarter_pension_evidence_unknown`, aby se výpočet účetní nezměnil
-- potichu. Výslovné 'not_documented' blokátor neshazuje.

SET NAMES utf8mb4;

ALTER TABLE payroll_enforcement_dependants
  ADD COLUMN IF NOT EXISTS quarter_pension_evidence
    ENUM('unknown','not_documented','documented') NOT NULL DEFAULT 'unknown'
    AFTER excluded_for_maintenance,
  ADD COLUMN IF NOT EXISTS quarter_pension_holder
    ENUM('debtor','spouse_partner') NULL
    AFTER quarter_pension_evidence,
  ADD COLUMN IF NOT EXISTS quarter_pension_kind
    ENUM(
      'old_age','invalidity_second_degree','invalidity_third_degree','orphan'
    ) NULL
    AFTER quarter_pension_holder,
  ADD COLUMN IF NOT EXISTS quarter_pension_documented_on DATE NULL
    AFTER quarter_pension_kind;

-- U vyživovaných dětí se čtvrtina počítá dál bez podmínky, příznak je pro ně
-- bezvýznamný a čtecí cesta (PayrollEnforcementRepository::assembleEvidence())
-- ho u nich vůbec nesbírá. Constraint na to záměrně není: vynutil by úpravu
-- každého zápisu vyživované osoby kvůli poli, které u ní nic neznamená.

-- MariaDB neumí IF NOT EXISTS u CHECK — nejdřív zahodit, pak přidat.
ALTER TABLE payroll_enforcement_dependants
  DROP CONSTRAINT IF EXISTS chk_payroll_enforcement_dependant_quarter_pension;

ALTER TABLE payroll_enforcement_dependants
  ADD CONSTRAINT chk_payroll_enforcement_dependant_quarter_pension
    CHECK (
      (
        quarter_pension_evidence = 'documented'
        AND quarter_pension_holder IS NOT NULL
        AND quarter_pension_kind IS NOT NULL
        AND quarter_pension_documented_on IS NOT NULL
      )
      OR (
        quarter_pension_evidence <> 'documented'
        AND quarter_pension_holder IS NULL
        AND quarter_pension_kind IS NULL
        AND quarter_pension_documented_on IS NULL
      )
    );

-- Měsíční evidence (payroll_enforcement_person_month_evidence) se záměrně
-- NEPŘEPISUJE. Blokátor vzniká z hodnoty 'unknown' ve výpočtu, ne ze zhozeného
-- příznaku spouse_evidence_complete — jen tak účetní uvidí konkrétní hlášku
-- `spouse_quarter_pension_evidence_unknown` místo obecného „doplňte evidenci",
-- a uzavřená období zůstanou beze změny zápisu.
