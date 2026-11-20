-- Výpis ostatních položek filtruje firmu a okno splatnosti (bez zadaného data rok zpět až rok
-- dopředu) a řadí podle splatnosti; počet, stránka i součty jdou po tomto indexu, ne přes celou historii.
ALTER TABLE other_items ADD INDEX IF NOT EXISTS idx_other_item_due (supplier_id, deleted_at, due_on);
