-- Čerpání více záloh jednou konečnou fakturou: odpočtový řádek § 37a nese zálohu, kterou čerpá.
--
-- Dosud se vydaná konečná faktura vázala na zálohu jen hlavičkou (`invoices.parent_invoice_id`),
-- takže jedna faktura mohla čerpat nejvýš jednu proformu. Záporné odpočtové řádky faktury
-- jsou přitom už teď jediným zdrojem pravdy o tom, kolik faktura ze zálohy odečítá (bere je
-- zaúčtování i postupné čerpání). Sloupec `advance_invoice_id` k řádku doplní, ze které
-- proformy odpočet je. Řádek bez něj patří záloze z hlavičky faktury (dosavadní doklady
-- i vyúčtování vystavené z proformy), takže stávající data se nemění.
--
-- Mazání proformy odkaz jen vynuluje: vystavenou proformu smazat nejde a kaskáda celé firmy
-- nesmí záviset na pořadí, v jakém MariaDB maže doklady.
SET NAMES utf8mb4;

ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS advance_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'proforma, ze které odpočtový řádek § 37a čerpá; NULL = záloha z hlavičky faktury (parent_invoice_id)'
        AFTER asset_id;

CREATE INDEX IF NOT EXISTS ix_ii_advance_invoice ON invoice_items (advance_invoice_id, invoice_id);

ALTER TABLE invoice_items
    ADD CONSTRAINT fk_ii_advance_invoice FOREIGN KEY IF NOT EXISTS (advance_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL;
