SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Číslování přijatých dokladů podle data: z kterého data se bere měsíc a rok interního
-- čísla nového dokladu a podle kterého se doklady přečíslují. issue = datum vystavení
-- (výchozí), received = datum přijetí, tax = DUZP. Jedno nastavení firmy: přejmenuje
-- volbu přečíslování z migrace 2333, aby nevznikla dvě nastavení téhož.
ALTER TABLE supplier
  CHANGE COLUMN IF EXISTS purchase_renumber_date_basis purchase_numbering_date_basis ENUM('issue','received','tax') NOT NULL DEFAULT 'issue'
    COMMENT 'Číslování přijatých dokladů: datum pro měsíc a rok čísla i přečíslování (issue|received|tax).';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS purchase_numbering_date_basis ENUM('issue','received','tax') NOT NULL DEFAULT 'issue'
    COMMENT 'Číslování přijatých dokladů: datum pro měsíc a rok čísla i přečíslování (issue|received|tax).'
    AFTER purchase_invoice_numbering;
