-- MyÚčto.cz — druhy ostatních pohledávek a závazků s volitelnou vlastní řadou
--
-- Ostatní položky měly jen pevné druhy (nájem, půjčka, kauce…) a dvě číselné řady
-- na firmu a rok (OP pro pohledávky, OZ pro závazky). Firma, která přes ně vede víc
-- agend (nájemné, nároky na dotace, registrace), je podle čísla nerozlišila.
--
--   * other_item_kinds — nastavení druhů firmy, vždy pro jednu stranu, takže je
--     jednoznačné, ze které řady (other_receivable / other_payable) druh čerpá:
--       - vestavěný druh (`builtin_code` = kód z other_items.kind, `name` NULL) má řádek,
--         až když mu firma zapne vlastní řadu nebo ho skryje; bez řádku platí výchozí
--         stav (nabízí se, společná řada);
--       - vlastní druh (`name`, `builtin_code` NULL) si firma zakládá sama.
--     `own_series` = druh čísluje vlastní řadou. Vypnutím se řada nemaže: vydaná čísla
--     zůstávají a nové doklady jdou zase do společné řady.
--   * other_items.kind_id — vlastní druh položky; NULL = vestavěný druh podle `kind`.
--     ON DELETE SET NULL: smazání firmy kaskáduje druhy i položky v libovolném pořadí
--     (RESTRICT by ho zastavil); použitý druh nejde smazat, hlídá to OtherItemKindService.
--     Vestavěné kódy zůstávají (API, MCP, AI asistent a importy s nimi pracují dál),
--     položka s vlastním druhem nese v `kind` hodnotu 'other'.
--
-- Vlastní řada druhu žije v accounting_document_series jako řada pokladny (1506):
-- `register_id` = id řádku druhu u řad other_receivable / other_payable. Význam sloupce
-- se řídí `series_code`, takže unikátní klíč ani existující řady se nemění.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN / KEY / FOREIGN KEY IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS other_item_kinds (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  side         ENUM('receivable','payable') NOT NULL,
  builtin_code VARCHAR(20) NULL COMMENT 'Vestavěný druh (other_items.kind); NULL = vlastní druh',
  name         VARCHAR(100) NULL COMMENT 'Název vlastního druhu; u vestavěného NULL (název je v překladech)',
  own_series   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Druh čísluje vlastní řadou (accounting_document_series.register_id = id)',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_other_item_kind_name (supplier_id, side, name),
  UNIQUE KEY uq_other_item_kind_builtin (supplier_id, side, builtin_code),
  CONSTRAINT chk_other_item_kind_type CHECK ((builtin_code IS NULL) <> (name IS NULL)),
  CONSTRAINT fk_other_item_kind_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_item_kind_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE other_items
  ADD COLUMN IF NOT EXISTS kind_id INT UNSIGNED NULL
    COMMENT 'Vlastní druh (other_item_kinds.id); NULL = vestavěný druh podle kind'
    AFTER kind;

ALTER TABLE other_items
  ADD KEY IF NOT EXISTS idx_other_item_kind (kind_id, supplier_id),
  ADD CONSTRAINT fk_other_item_kind FOREIGN KEY IF NOT EXISTS (kind_id) REFERENCES other_item_kinds(id) ON DELETE SET NULL;

ALTER TABLE accounting_document_series
  MODIFY COLUMN register_id INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = společná řada firmy; >0 = vlastní řada: pokladny (cash_registers.id) u cash_in/cash_out, druhu (other_item_kinds.id) u other_receivable/other_payable';
