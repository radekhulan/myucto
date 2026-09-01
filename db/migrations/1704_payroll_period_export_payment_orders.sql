-- Platební příkazy do měsíčního archivu mezd.
--
-- Archiv období dosud obsahoval pásky a dokumenty, artefakty podání a protokoly
-- ČSSZ. Chyběly ale soubory platebních příkazů, kterými se odvody skutečně
-- platily — a právě ty účetní u kontroly potřebuje vedle předpisu doložit.
-- Rozšiřuje se proto výčet druhů částí exportu o `payment_export`.
--
-- MariaDB neumí u ENUM „IF NOT EXISTS", takže se hodnota přidává bezpodmínečně;
-- opakované spuštění migrace vede na tentýž výsledný výčet.
ALTER TABLE payroll_period_export_job_parts
    MODIFY COLUMN part_kind ENUM(
        'document',
        'submission_artifact',
        'submission_protocol',
        'payment_export',
        'archive'
    ) NOT NULL;
