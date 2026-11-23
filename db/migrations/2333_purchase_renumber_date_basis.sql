SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Přečíslování přijatých dokladů: podle kterého data se vybírají doklady měsíce a v jakém
-- pořadí dostanou čísla. issue = datum vystavení (výchozí), received = datum přijetí,
-- tax = DUZP. Uloží se při posledním přečíslování, náhled ho nabídne jako výchozí.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS purchase_renumber_date_basis ENUM('issue','received','tax') NOT NULL DEFAULT 'issue'
    COMMENT 'Přečíslování přijatých dokladů: datum pro výběr měsíce a pořadí (issue|received|tax).'
    AFTER purchase_invoice_numbering;
