-- MyÚčto.cz — kategorie a pravidla příchozích zpráv datové schránky.
--
-- Zprávy se řadí do kategorií podle odesílatele a typu zprávy (finanční správa,
-- sociální zabezpečení, zdravotní pojišťovny, soudy a exekutoři, ostatní úřady,
-- obchodní partneři, systém ISDS, vlastní podání, ostatní). Systémové kategorie
-- mají `code` a jejich název se překládá v UI, dokud ho uživatel nepřejmenuje.
-- Vlastní kategorie mají `code` NULL a povinný název.
--
-- Pravidlo míří na kategorii podle ID schránky odesílatele, části jména
-- odesílatele nebo části věci. Pravidla `auto` zakládá aplikace sama u nového
-- odesílatele, `user` jsou ruční. Ruční zařazení zprávy (`category_source =
-- 'manual'`) má přednost před pravidly a přepočet ho nepřepíše.
--
-- Zařazení existujících zpráv neprovádí migrace: dělá ho idempotentně služba
-- při prvním načtení seznamu (zprávy s `category_id IS NULL`), protože pravidla
-- se opírají o číselník adresátů a metadata obálky z DMS.
--
-- Shodu firmy kategorie a zprávy drží aplikace (zařazení ověřuje kategorii
-- v rámci firmy). Kompozitní FK by tu nešel s `ON DELETE SET NULL`, protože
-- `supplier_id` je NOT NULL; smazání kategorie musí zprávy vrátit k přepočtu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS submission_inbox_categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  code         VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
                 COMMENT 'Systémová kategorie; NULL = vlastní kategorie uživatele',
  name         VARCHAR(100) NULL COMMENT 'Vlastní název; NULL = výchozí překlad podle code',
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_inbox_category_code (supplier_id, code),
  KEY idx_submission_inbox_category_order (supplier_id, sort_order),

  CONSTRAINT fk_submission_inbox_category_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT chk_submission_inbox_category_named
    CHECK (code IS NOT NULL OR name IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_inbox_category_rules (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  match_field  ENUM('sender_box','sender_name','subject') NOT NULL,
  pattern      VARCHAR(190) NOT NULL COMMENT 'ID schránky (přesná shoda) nebo část textu (bez ohledu na velikost písmen)',
  origin       ENUM('auto','user') NOT NULL DEFAULT 'user',
  created_by   BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_submission_inbox_category_rule (supplier_id, match_field, pattern),
  KEY idx_submission_inbox_category_rule_category (category_id),

  CONSTRAINT fk_submission_inbox_category_rule_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_submission_inbox_category_rule_category
    FOREIGN KEY (category_id) REFERENCES submission_inbox_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE submission_inbox_messages
  ADD COLUMN IF NOT EXISTS category_id INT UNSIGNED NULL
    COMMENT 'Kategorie zprávy; NULL = čeká na zařazení',
  ADD COLUMN IF NOT EXISTS category_source ENUM('auto','rule','manual') NULL
    COMMENT 'auto = rozpoznání nebo automatické pravidlo, rule = ruční pravidlo, manual = ručně přeřazeno',
  ADD COLUMN IF NOT EXISTS direction ENUM('received','sent') NULL
    COMMENT 'sent = doručenka nebo kopie vlastní odeslané zprávy',
  ADD COLUMN IF NOT EXISTS read_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS read_by BIGINT UNSIGNED NULL;

ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT fk_submission_inbox_category
    FOREIGN KEY IF NOT EXISTS (category_id)
    REFERENCES submission_inbox_categories (id)
    ON DELETE SET NULL;

ALTER TABLE submission_inbox_messages
  ADD CONSTRAINT fk_submission_inbox_read_by
    FOREIGN KEY IF NOT EXISTS (read_by)
    REFERENCES users (id)
    ON DELETE SET NULL;

-- Seznam se filtruje vždy v rámci firmy a prostředí a řadí podle doručení.
ALTER TABLE submission_inbox_messages
  ADD INDEX IF NOT EXISTS idx_submission_inbox_list (supplier_id, environment, delivered_at),
  ADD INDEX IF NOT EXISTS idx_submission_inbox_category (supplier_id, environment, category_id),
  ADD INDEX IF NOT EXISTS idx_submission_inbox_sender (supplier_id, environment, sender_box_id);
