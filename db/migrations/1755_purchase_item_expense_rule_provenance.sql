-- MyÚčto.cz — provenience klasifikace nákladu na řádku přijaté faktury.
--
-- Klasifikátor ({@see ExpenseKindClassifier}) rozhoduje z pěti zdrojů — firemního
-- pravidla (`expense_classification_rules`), katalogu frází, vestavěných klíčových
-- slov, prahu §26/2 ZDP a AI. Do teď se na položku uložil jen VÝSLEDEK
-- (`expense_kind` + `expense_account_code`), takže se zpětně nedalo zjistit, PODLE
-- ČEHO se účtovalo. Detail dokladu proto nemohl ukázat „podle pravidla X" a nemohl
-- ani nabídnout jeho opravu; uživatel viděl jen účet a musel hádat, jestli za ním
-- stojí pravidlo, katalog, nebo dohad z klíčových slov.
--
-- `expense_rule_id` je ZÁMĚRNĚ BEZ cizího klíče: FK s ON DELETE SET NULL by při
-- smazání pravidla umazal i historickou stopu (a to je právě ten okamžik, kdy je
-- potřeba — „účtovalo se podle pravidla, které už neexistuje"). Čtecí cesta si
-- název dohledá LEFT JOINem a chybějící řádek zobrazí jako smazané pravidlo.
--
-- Ruční editace položek doklad přeukládá celé (PurchaseInvoiceRepository), takže
-- se provenience při ručním zásahu sama vyprázdní — což je správně: pak už za
-- účtem nestojí pravidlo, ale účetní.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS expense_rule_id INT UNSIGNED NULL
    COMMENT 'expense_classification_rules.id, které klasifikaci určilo (bez FK — stopa přežije smazání pravidla)'
    AFTER expense_account_code,
  ADD COLUMN IF NOT EXISTS expense_classification_source
    ENUM('rule','catalog','keyword','threshold','ai') NULL
    COMMENT 'zdroj klasifikace; NULL = ruční volba účetní'
    AFTER expense_rule_id;

CREATE INDEX IF NOT EXISTS idx_pii_expense_rule
  ON purchase_invoice_items (expense_rule_id);
