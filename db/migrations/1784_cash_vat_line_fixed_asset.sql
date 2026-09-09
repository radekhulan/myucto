-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález L-4:
-- hotovostní pořízení dlouhodobého majetku se nikdy nedostalo na ř. 47 přiznání
--
-- `VatLedgerService::fetchCash()` měl v SELECTu natvrdo `0 AS is_fixed_asset`, protože
-- pokladní řádek DPH příznak majetku vůbec neměl. Přijatá faktura ho nese na hlavičce
-- i na položce (migrace 0044) a `VatClassificationMapper` z něj plní ř. 47 („hodnota
-- pořízeného dlouhodobého majetku", doplňující údaj k odpočtu na ř. 40–45). Stroj
-- koupený za hotové tam tedy chyběl — přiznání bylo neúplné, i když daň seděla.
--
-- Příznak patří na ŘÁDEK DPH, ne na doklad: jeden výdajový doklad běžně kombinuje
-- pořízení majetku s drobným nákupem v jiné sazbě, a ř. 47 sumuje jen tu část základu,
-- která na majetek připadá. Zrcadlí to `purchase_invoice_items.is_fixed_asset`.

SET NAMES utf8mb4;

ALTER TABLE cash_document_vat_lines
    ADD COLUMN IF NOT EXISTS is_fixed_asset TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Pořízení dlouhodobého majetku (§ 4 odst. 4) — doplňující ř. 47 DPHDP3'
        AFTER tax_treatment;
