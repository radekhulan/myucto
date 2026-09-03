-- 1733: proč účetní období vzniklo.
--
-- Období se nově zakládá i automaticky — při zaúčtování dokladu s datem mimo
-- existující řadu a při importu historie z jiného systému (Pohoda, ISDOC), viz
-- MyInvoice\Service\Accounting\AccountingPeriodProvisioner. Bez téhle stopy by
-- účetní v seznamu období viděla roky, které nezaložila, a neměla by jak zjistit
-- odkud se vzaly; activity_log to sice zaznamená, ale k řádku období se z něj
-- nedostane jinak než hledáním.
--
-- NULL = založeno ručně (API / průvodce / uzávěrkový krok open_next), tedy stav
-- všech období existujících před touhle migrací.
ALTER TABLE accounting_periods
    ADD COLUMN IF NOT EXISTS created_reason VARCHAR(32) NULL DEFAULT NULL
        COMMENT 'posting|import|maintenance = automaticky; NULL = ručně';
