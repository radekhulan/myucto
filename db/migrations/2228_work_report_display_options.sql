-- Zobrazení výkazu: výchozí nastavení firmy a volitelná výjimka konkrétní faktury.
-- NULL zachovává původní podobu; neovlivňuje sazby ani výpočet faktury.
ALTER TABLE supplier ADD COLUMN IF NOT EXISTS work_report_display_options JSON NULL;
ALTER TABLE work_reports ADD COLUMN IF NOT EXISTS display_options JSON NULL;
