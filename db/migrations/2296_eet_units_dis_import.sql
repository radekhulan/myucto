-- MyÚčto — EET 2.0: evidenční jednotky s údaji pro oznámení v DIS+ a hromadný import.
--
-- Finanční správa od 3. 11. 2026 přijímá oznámení evidenčních jednotek hromadným importem
-- CSV (Import evidenčních jednotek EET – popis struktury CSV souboru). Jednotka se proto
-- může v MyÚčtu připravit dřív, než ji DIS+ očísluje: `unit_number` (id_jednotky) je
-- nepovinné a doplní se po importu. Jednotka bez čísla nejde přiřadit zařízení ani jí
-- evidovat tržbu (hlídá aplikace).
--
-- Druhy jednotek odpovídají číselníku DIS+ (S, M, A, I, D, P): přibyl prodejní automat
-- nebo samoobslužné zařízení (`vending`) a poplatník mimo provozovnu (`taxpayer`).
-- Původní `other` zůstává kvůli existujícím záznamům; export ho odmítne.
--
-- Idempotence: MODIFY se stejnou definicí je opakovatelný, ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE eet_units
    MODIFY COLUMN unit_number INT UNSIGNED NULL,
    MODIFY COLUMN kind ENUM('premises','online','vehicle','mobile','other','vending','taxpayer') NOT NULL,
    ADD COLUMN IF NOT EXISTS nace_code VARCHAR(6) NULL AFTER kind,
    ADD COLUMN IF NOT EXISTS activity_note VARCHAR(255) NULL AFTER nace_code,
    ADD COLUMN IF NOT EXISTS records_sales TINYINT(1) NOT NULL DEFAULT 1 AFTER activity_note,
    ADD COLUMN IF NOT EXISTS indirect_representation TINYINT(1) NOT NULL DEFAULT 0 AFTER records_sales,
    ADD COLUMN IF NOT EXISTS ruian_code INT UNSIGNED NULL AFTER indirect_representation,
    ADD COLUMN IF NOT EXISTS street VARCHAR(32) NULL AFTER ruian_code,
    ADD COLUMN IF NOT EXISTS house_number VARCHAR(10) NULL AFTER street,
    ADD COLUMN IF NOT EXISTS orientation_number VARCHAR(10) NULL AFTER house_number,
    ADD COLUMN IF NOT EXISTS municipality VARCHAR(48) NULL AFTER orientation_number,
    ADD COLUMN IF NOT EXISTS municipality_part VARCHAR(48) NULL AFTER municipality,
    ADD COLUMN IF NOT EXISTS postal_code CHAR(5) NULL AFTER municipality_part,
    ADD COLUMN IF NOT EXISTS location_text VARCHAR(255) NULL AFTER postal_code,
    ADD COLUMN IF NOT EXISTS vehicle_kind CHAR(1) NULL AFTER location_text,
    ADD COLUMN IF NOT EXISTS vehicle_kind_note VARCHAR(150) NULL AFTER vehicle_kind;
