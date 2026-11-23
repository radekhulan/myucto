-- Mapa převodu z programu Helios (Asseco Helios Inuvio).
--
-- helios_import_map: co ze zdrojové databáze Heliosu v MyÚčtu vzniklo (druh + zdrojový klíč
-- → id záznamu). Na ní stojí idempotence převodu a zároveň ochrana převzatých záznamů:
-- deník, doklady a úhrady zaúčtované Heliosem automatika MyÚčta nepřepisuje
-- (TakenOverRecord - dorovnání haléřů, přečíslování, doúčtování).
--
-- Převod Heliosu běží zatím jako dávkový můstek (skripty mimo aplikaci), které tabulku
-- zakládaly samy se stejnou strukturou; CREATE TABLE IF NOT EXISTS ji převezme beze změny
-- a cizí klíč na firmu se doplní.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS helios_import_map (
    supplier_id INT UNSIGNED NOT NULL,
    kind VARCHAR(40) NOT NULL,
    source_key VARCHAR(190) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, kind, source_key),
    KEY idx_helios_map_target (supplier_id, kind, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE helios_import_map
    ADD CONSTRAINT fk_helios_import_map_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE;
