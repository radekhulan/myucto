-- MyÚčto.cz: pravidla výnosů pro položky vydaných faktur.
--
-- PROČ: importované vydané faktury (ISDOC) nesou směs výrobků, zboží a služeb, ale
-- položky nejsou navázané na skladové karty, takže celý výnos padal na předkontaci
-- dokladu (602). Pravidlo podle kódu položky, textu nebo sazby DPH určí účet položky
-- (601/602/604 nebo rozvahový účet mimo saldokonto) při importu i při zaúčtování.
--
-- VZOR = `expense_classification_rules` (1093): kritéria nullable s AND přes vyplněná,
-- priority ASC first-match-wins, hit_count, is_active. Pravidlo bez kritéria by chytilo
-- všechno, proto CHECK.
--
-- `invoice_items.seller_item_code` = kód položky prodávajícího (ISDOC
-- Item/SellersItemIdentification/ID), podle něj pravidlo páruje nejpřesněji.

SET NAMES utf8mb4;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS seller_item_code VARCHAR(64) NULL DEFAULT NULL AFTER revenue_account_code;

CREATE TABLE IF NOT EXISTS revenue_classification_rules (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  name                 VARCHAR(120) NOT NULL,
  item_code            VARCHAR(64) NULL,                -- přesná shoda kódu položky (bez ohledu na velikost písmen)
  description_contains VARCHAR(120) NULL,               -- fragment textu položky
  vat_rate             DECIMAL(5,2) NULL,               -- sazba DPH položky v procentech
  target_account_code  VARCHAR(10) NOT NULL,
  priority             SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  hit_count            INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at          DATETIME NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_rcr_supplier (supplier_id, is_active, priority),
  CONSTRAINT fk_rcr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_rcr_user     FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT chk_rcr_criteria CHECK (
    item_code IS NOT NULL
    OR description_contains IS NOT NULL
    OR vat_rate IS NOT NULL
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
