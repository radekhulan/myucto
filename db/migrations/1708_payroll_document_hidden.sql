-- Skrytí nahrazené verze dokumentu ze seznamu.
--
-- Táž potíž jako u platebních exportů (migrace 1707): jakmile vznikne nová
-- revize výplatní pásky, ta stará v seznamu jen překáží. Jmenuje se stejně,
-- liší se číslem revize a časem, a účetní se u ní musí pokaždé znovu
-- rozhodovat, co je platné.
--
-- Řádek dokumentu se přitom SMAZAT NEMŮŽE a nemá. `payroll_generated_documents`
-- je záměrně neměnná (triggery zakazují UPDATE i DELETE) — dokument je doklad
-- o tom, co zaměstnanec dostal, a ten se maže nejmíň ze všeho. Skrytí se proto
-- vede vedle, tady: neměnnost zůstává netknutá a seznam ukazuje jen platné.
--
-- Skrytí jde vzít zpět odstraněním řádku z TÉHLE tabulky, ne z dokumentů.
CREATE TABLE IF NOT EXISTS payroll_generated_document_hidden (
    supplier_id  INT UNSIGNED    NOT NULL,
    document_id  BIGINT UNSIGNED NOT NULL,
    hidden_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hidden_by    BIGINT UNSIGNED NULL,
    PRIMARY KEY (supplier_id, document_id),
    CONSTRAINT fk_payroll_document_hidden_document
        FOREIGN KEY (supplier_id, document_id)
        REFERENCES payroll_generated_documents (supplier_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_payroll_document_hidden_user
        FOREIGN KEY (hidden_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
