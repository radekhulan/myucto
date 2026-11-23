-- Výpis ostatních položek filtruje firmu a okno splatnosti (bez zadaného data rok zpět až rok
-- dopředu) a řadí podle splatnosti; počet, stránka i součty jdou po tomto indexu a čtou jen okno.
-- Výchozí pohled Otevřené dolní mez nemá (stav „uhrazeno“ se neukládá), čte historii do konce okna.
ALTER TABLE other_items ADD INDEX IF NOT EXISTS idx_other_item_due (supplier_id, deleted_at, due_on);
