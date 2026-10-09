-- MyÚčto.cz — pravidla klasifikace výdajů v daňové evidenci.
--
-- Daňová evidence nemá účty: z pravidla ji zajímá druh výdaje (majetek se do výdajů
-- při úhradě nepočítá, drobný majetek se eviduje) a to, zda jde o daňový výdaj.
-- NULL = pravidlo daňovou uznatelnost dokladu nemění, 0 = nedaňový výdaj (§ 25 ZDP),
-- 1 = daňový výdaj. V podvojném účetnictví se sloupec nepoužívá (uznatelnost tam
-- určuje účet nákladu).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE expense_classification_rules
  ADD COLUMN IF NOT EXISTS tax_deductible TINYINT(1) NULL DEFAULT NULL
    COMMENT 'daňová evidence: NULL = neměnit, 0 = nedaňový výdaj, 1 = daňový výdaj'
    AFTER target_account_code;
