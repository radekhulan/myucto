-- MyÚčto.cz — REGZEC A1: rozpracovaný profil vedle ověřeného.
--
-- Formulář A1 má přes stovku polí a část z nich se bere z evidence osoby.
-- Dokud uložení vyžadovalo úplnost, jediné prázdné pole odmítlo celý zápis
-- a hodina práce zůstala jen v prohlížeči. Uložení proto přijímá i neúplný
-- profil; úplnost se vynucuje až tam, kde z profilu vzniká podání.
--
-- Řádky zůstávají neměnné a append-only (triggery z migrace 1609). Stav
-- rozlišuje, která verze prošla přísnou kontrolou — ověřenou verzi tedy
-- koncept nepřepíše, jen nad ni přibude novější řádek.
ALTER TABLE payroll_registration_a1_profiles
  ADD COLUMN IF NOT EXISTS status ENUM('draft','verified') NOT NULL DEFAULT 'verified'
    COMMENT 'draft = rozpracovaný, verified = prošel přísnou kontrolou A1'
    AFTER effective_on;

-- Koncept se běžně vrátí k obsahu, který už jednou uložený byl (účetní zkusí
-- variantu a vzápětí ji vrátí zpět). Jedinečnost otisku napříč verzemi by
-- takový návrat odmítla chybou duplicitního klíče, a „Uložit projde vždy" je
-- tvrdší požadavek než úspora jednoho řádku historie.
ALTER TABLE payroll_registration_a1_profiles
  DROP INDEX IF EXISTS uq_payroll_registration_a1_profile_reference;

ALTER TABLE payroll_registration_a1_profiles
  ADD INDEX IF NOT EXISTS idx_payroll_registration_a1_profile_reference
    (supplier_id, employment_id, reference_hash);
