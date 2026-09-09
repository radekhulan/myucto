-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález M-8:
-- opakované faktury zapomínaly ručně zvolenou klasifikaci DPH
--
-- `recurring_invoice_template_items` nesl sazbu, ale ne klasifikační kód, takže cron
-- při každém generování kód znovu DERIVOVAL ze SSOT podle sazby a měrné jednotky.
-- Ručně zvolený kód se tím ztratil: dodání zboží do jiného členského státu (`20`,
-- ř. 20 + souhrnné hlášení kód plnění 0) se u jednotky „ks" každý měsíc přepsalo na
-- `22` (poskytnutí služby, ř. 21 + SH kód 3). Stejně tak `1m`/`2m` (prodej dlouhodobého
-- majetku vyloučený z koeficientu § 76 odst. 4) — u opakovaného plnění sice vzácné,
-- ale derivace ho nemá z čeho poznat.
--
-- Šířka i sémantika sloupce zrcadlí `invoice_items.vat_classification_code` (migrace
-- 0037): NULL = derivovat ze SSOT, vyplněno = rozhodnutí člověka, které derivace
-- nesmí přebít.

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_template_items
    ADD COLUMN IF NOT EXISTS vat_classification_code VARCHAR(8) NULL
        COMMENT 'Klasifikace DPH přenesená na generovanou fakturu; NULL = derivovat ze SSOT'
        AFTER vat_rate_id;
