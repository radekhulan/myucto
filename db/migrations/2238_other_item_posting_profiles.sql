-- MyÚčto.cz: účetní profily druhů ostatních pohledávek a závazků a vlastní kontace položky
--
-- Ostatní položka se zaúčtovala vždy stejně: účet položky (výchozí 315/325) proti protiúčtu
-- zadanému na položce a úhrada z banky 221 proti účtu položky. Firma, která vede přes druhy
-- víc agend (nájem, půjčky, kauce), musela kontaci vyplňovat u každé položky znovu.
--
--   * other_item_posting_profiles: kontace druhu po událostech (`event`):
--       - origin        vznik: účet položky (strana položky) proti protiúčtu,
--       - payment_bank  úhrada z banky: účet banky proti účtu položky,
--       - payment_cash  úhrada z pokladny: účet pokladny proti účtu položky.
--     Další události (úroky, časové rozlišení, odpis, přeúčtování dlouhodobé části) přidá
--     aplikace jako nové hodnoty `event`, sloupec je proto VARCHAR, ne ENUM. Druh má vždy
--     jednu stranu, takže MD/D je jednoznačné. NULL na straně položky = účet položky
--     (u vzniku účet z položky, jinak výchozí 315/325); NULL na peněžní straně úhrady =
--     účet banky podle výpisu (221) nebo účet pokladny. Profil vestavěného druhu visí
--     na řádku nastavení druhu (vznikne při prvním uložení profilu, jako u vlastní řady).
--   * other_item_posting_overrides: vlastní kontace úhrad jedné položky (stejné sloupce).
--     Vlastní kontace VZNIKU zůstává ve sloupcích položky (other_items.account_code,
--     counter_account_code a other_item_posting_lines), jak ji položka nesla dosud. Stávající
--     položky proto nic nepřevádějí a účtují beze změny: prázdný účet položky a protiúčet
--     doplní profil druhu, a když druh profil nemá, platí dosavadní výchozí účty.
--     Opakování nese vlastní kontaci úhrad v šabloně (other_item_schedules.template_json,
--     klíč posting_overrides) a předává ji vygenerovaným konceptům.
--
-- Změna profilu nebo vlastní kontace platí jen pro budoucí zaúčtování; existující zápisy
-- se nepřeúčtovávají. Daňová evidence profily nepoužívá.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD INDEX IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE other_item_kinds ADD INDEX IF NOT EXISTS idx_other_item_kind_tenant (id, supplier_id);

CREATE TABLE IF NOT EXISTS other_item_posting_profiles (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  kind_id              INT UNSIGNED NOT NULL COMMENT 'other_item_kinds.id; vestavěný druh má řádek nastavení',
  event                VARCHAR(30) NOT NULL COMMENT 'origin, payment_bank, payment_cash (OtherItemPostingEvent)',
  debit_account_code   VARCHAR(20) NULL COMMENT 'MD; NULL = účet položky, u úhrady na peněžní straně účet banky/pokladny',
  credit_account_code  VARCHAR(20) NULL COMMENT 'D; NULL = účet položky, u úhrady na peněžní straně účet banky/pokladny',
  description_template VARCHAR(255) NULL COMMENT 'Popis zápisu se zástupnými znaky {title}, {document_no}, {partner}, {variable_symbol}',
  dimensions_json      JSON NULL COMMENT 'Výchozí dimenze hlavičky položky [{type_id, value_id}] (jen vznik)',
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_by           BIGINT UNSIGNED NULL,
  updated_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_other_item_posting_profile (supplier_id, kind_id, event),
  KEY idx_other_item_posting_profile_kind (kind_id, supplier_id),
  CONSTRAINT fk_other_item_posting_profile_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_other_item_posting_profile_kind FOREIGN KEY (kind_id, supplier_id)
    REFERENCES other_item_kinds(id, supplier_id) ON DELETE CASCADE,
  CONSTRAINT fk_other_item_posting_profile_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_other_item_posting_profile_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS other_item_posting_overrides (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  other_item_id        BIGINT UNSIGNED NOT NULL,
  event                VARCHAR(30) NOT NULL COMMENT 'payment_bank, payment_cash (vznik nese položka ve vlastních sloupcích)',
  debit_account_code   VARCHAR(20) NULL,
  credit_account_code  VARCHAR(20) NULL,
  description_template VARCHAR(255) NULL,
  dimensions_json      JSON NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_other_item_posting_override (other_item_id, event),
  KEY idx_other_item_posting_override_tenant (supplier_id, other_item_id),
  CONSTRAINT fk_other_item_posting_override_item FOREIGN KEY (other_item_id, supplier_id)
    REFERENCES other_items(id, supplier_id) ON DELETE CASCADE,
  CONSTRAINT fk_other_item_posting_override_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
