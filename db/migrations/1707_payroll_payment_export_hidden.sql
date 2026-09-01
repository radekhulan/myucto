-- Skrytí staré revize platebního exportu ze seznamu.
--
-- Účetní má u dávky vidět, co platí. Jakmile vznikne nová revize dokladu,
-- ta stará jen překáží: obě se jmenují stejně, liší se jen číslem revize
-- a datem, a z toho se špatně pozná, kterou vzít.
--
-- Řádek exportu se přitom SMAZAT NEMŮŽE a nemá. Tabulka `payroll_payment_exports`
-- je záměrně neměnná (triggery zakazují UPDATE i DELETE) — je to doklad o tom,
-- co se skutečně poslalo do banky, a ten se maže nejmíň ze všeho. Skrytí se
-- proto vede vedle, v téhle tabulce: neměnnost zůstává netknutá a seznam
-- přesto ukazuje jen to, co je platné.
--
-- Jen zápis, žádné mazání: skrytí jde vzít zpět tím, že se řádek odstraní
-- (to je běžná operace nad TOUHLE tabulkou, ne nad exportem samotným).
CREATE TABLE IF NOT EXISTS payroll_payment_export_hidden (
    supplier_id  INT UNSIGNED    NOT NULL,
    export_id    BIGINT UNSIGNED NOT NULL,
    hidden_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hidden_by    BIGINT UNSIGNED NULL,
    PRIMARY KEY (supplier_id, export_id),
    CONSTRAINT fk_payroll_payment_export_hidden_export
        FOREIGN KEY (supplier_id, export_id)
        REFERENCES payroll_payment_exports (supplier_id, id)
        ON DELETE CASCADE,
    CONSTRAINT fk_payroll_payment_export_hidden_user
        FOREIGN KEY (hidden_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
