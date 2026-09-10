SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_masters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  manufacturer_id BIGINT UNSIGNED NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_product_master_tenant_id (supplier_id, id),
  KEY ix_product_master_list (supplier_id, status, name, id),
  CONSTRAINT fk_product_master_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_master_manufacturer FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_master_i18n (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  master_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(5) NOT NULL,
  name VARCHAR(255) NOT NULL,
  short_desc VARCHAR(500) NULL,
  description MEDIUMTEXT NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(320) NULL,
  UNIQUE KEY uq_product_master_i18n (master_id, locale),
  KEY ix_product_master_i18n_tenant (supplier_id, master_id),
  CONSTRAINT fk_product_master_i18n_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_master_i18n_master FOREIGN KEY (master_id) REFERENCES product_masters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_master_axes (
  supplier_id INT UNSIGNED NOT NULL,
  master_id BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (master_id, attribute_id),
  KEY ix_product_master_axes_tenant (supplier_id, master_id),
  CONSTRAINT fk_product_master_axis_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_master_axis_master FOREIGN KEY (master_id) REFERENCES product_masters(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_master_axis_attribute FOREIGN KEY (attribute_id) REFERENCES stock_attributes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
  supplier_id INT UNSIGNED NOT NULL,
  master_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  inherit_manufacturer TINYINT(1) NOT NULL DEFAULT 1,
  option_signature BINARY(32) NULL,
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (master_id, stock_item_id),
  UNIQUE KEY uq_product_variant_item (stock_item_id),
  UNIQUE KEY uq_product_variant_options (master_id, option_signature),
  KEY ix_product_variant_tenant_item (supplier_id, stock_item_id),
  CONSTRAINT fk_product_variant_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_variant_master FOREIGN KEY (master_id) REFERENCES product_masters(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_variant_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE product_variants
  MODIFY COLUMN option_signature BINARY(32) NULL;

CREATE TABLE IF NOT EXISTS product_variant_options (
  supplier_id INT UNSIGNED NOT NULL,
  master_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (master_id, stock_item_id, attribute_id),
  KEY ix_product_variant_options_tenant (supplier_id, stock_item_id),
  CONSTRAINT fk_product_variant_option_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_variant_option_variant FOREIGN KEY (master_id, stock_item_id) REFERENCES product_variants(master_id, stock_item_id) ON DELETE CASCADE,
  CONSTRAINT fk_product_variant_option_attribute FOREIGN KEY (attribute_id) REFERENCES stock_attributes(id),
  CONSTRAINT fk_product_variant_option_value FOREIGN KEY (option_id) REFERENCES stock_attribute_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variant_i18n_inheritance (
  supplier_id INT UNSIGNED NOT NULL,
  master_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(5) NOT NULL,
  inherit_name TINYINT(1) NOT NULL DEFAULT 1,
  inherit_short_desc TINYINT(1) NOT NULL DEFAULT 1,
  inherit_description TINYINT(1) NOT NULL DEFAULT 1,
  inherit_seo_title TINYINT(1) NOT NULL DEFAULT 1,
  inherit_seo_description TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (master_id, stock_item_id, locale),
  KEY ix_product_variant_inheritance_tenant (supplier_id, stock_item_id),
  CONSTRAINT fk_product_variant_inheritance_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_variant_inheritance_variant FOREIGN KEY (master_id, stock_item_id) REFERENCES product_variants(master_id, stock_item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_item_relations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  source_stock_item_id BIGINT UNSIGNED NOT NULL,
  target_stock_item_id BIGINT UNSIGNED NOT NULL,
  relation_type ENUM('accessory','replacement','related') NOT NULL,
  display_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stock_item_relation (source_stock_item_id, target_stock_item_id, relation_type),
  KEY ix_stock_item_relation_tenant_source (supplier_id, source_stock_item_id, relation_type, display_order),
  KEY ix_stock_item_relation_target (target_stock_item_id),
  CONSTRAINT chk_stock_item_relation_not_self CHECK (source_stock_item_id <> target_stock_item_id),
  CONSTRAINT fk_stock_item_relation_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_item_relation_source FOREIGN KEY (source_stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_item_relation_target FOREIGN KEY (target_stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
