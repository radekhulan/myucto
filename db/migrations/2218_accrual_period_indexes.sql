-- MyÚčto.cz: indexy pro řádky dokladů s obdobím časového rozlišení.
--
-- PROČ: výkazy s průběžným časovým rozlišením mimo deník a krok uzávěrky „Časové
-- rozlišení" hledají řádky přijatých a vydaných faktur, jejichž období končí za
-- rozvahovým dnem (`accrual_to > ?`). Takových řádků je málo, ale bez indexu se
-- procházely všechny doklady firmy za celou historii a jejich řádky. S indexem dotaz
-- začne od řádků s obdobím a firmu dohledá přes primární klíč dokladu.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items ADD INDEX IF NOT EXISTS idx_pii_accrual_to (accrual_to);

ALTER TABLE invoice_items ADD INDEX IF NOT EXISTS idx_ii_accrual_to (accrual_to);
