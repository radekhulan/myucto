-- Oblíbené stránky uživatele (hvězdička v horní liště).
--
-- Oblíbená stránka je osobní věc uživatele, platí napříč zařízeními. Odkaz si
-- pamatuje i firmu (supplier_id), ve které byl uložen, aby se při prokliku firma
-- přepnula stejně jako u oken. Smazání firmy odkaz odstraní.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_favorites (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED    NULL COMMENT 'firma, ve které byla stránka uložena; NULL = bez vazby na firmu',
  path        VARCHAR(500)    NOT NULL COMMENT 'interní cesta vč. query, začíná lomítkem',
  title       VARCHAR(190)    NOT NULL,
  position    INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_uf_user_position (user_id, position),
  CONSTRAINT fk_uf_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_uf_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
