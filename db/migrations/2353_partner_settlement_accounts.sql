SET NAMES utf8mb4;

-- Účet závazku a pohledávky podle partnera: karta partnera může vést závazky
-- přijatých dokladů a pohledávky vydaných dokladů na vlastní analytice (např. 321.200
-- tuzemské faktury, 311.200 pachtovné). Firma může mít vlastní účet závazku pro
-- zahraniční dodavatele (např. 321.300), použije se u dodavatele z jiné země, než je
-- země firmy, když karta účet nenese (PartnerSettlementAccount).
--
-- Výchozí NULL = beze změny, platí předkontace dokladu.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS payable_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Účet závazku přijatých dokladů dodavatele (třída 3), NULL = předkontace dokladu.',
  ADD COLUMN IF NOT EXISTS receivable_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Účet pohledávky vydaných dokladů odběratele (třída 3), NULL = předkontace dokladu.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS foreign_payable_account_code VARCHAR(10) NULL DEFAULT NULL
    COMMENT 'Účet závazku pro dodavatele z jiné země než firma, NULL = předkontace dokladu.';
