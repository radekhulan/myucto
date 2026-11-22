SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sales_quote_counters (
  supplier_id INT UNSIGNED NOT NULL,
  series_year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL,
  PRIMARY KEY (supplier_id, series_year),
  CONSTRAINT fk_sales_quote_counter_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  quote_number VARCHAR(50) NOT NULL,
  title VARCHAR(190) NOT NULL,
  client_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  currency_id INT UNSIGNED NOT NULL,
  valid_until DATE NOT NULL,
  status ENUM('draft','sent','accepted','declined','withdrawn') NOT NULL DEFAULT 'draft',
  current_version_id BIGINT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  accepted_section_ids LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(accepted_section_ids)),
  total_without_vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_with_vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_quote_number (supplier_id, quote_number),
  KEY ix_sales_quote_recent (supplier_id, id),
  KEY ix_sales_quote_status (supplier_id, status, valid_until, id),
  KEY ix_sales_quote_client (supplier_id, client_id, id),
  KEY ix_sales_quote_project (supplier_id, project_id, id),
  CONSTRAINT fk_sales_quote_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_quote_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_sales_quote_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_quote_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quote_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  status ENUM('draft','sent','accepted','declined','withdrawn') NOT NULL DEFAULT 'draft',
  data_json LONGTEXT NOT NULL CHECK (JSON_VALID(data_json)),
  pdf_key VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_quote_revision (quote_id, revision),
  KEY ix_sales_quote_version_tenant (supplier_id, quote_id, id),
  CONSTRAINT fk_sales_quote_version_quote FOREIGN KEY (quote_id) REFERENCES sales_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quote_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(127) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  storage_key VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_sales_quote_storage (quote_id, storage_key),
  KEY ix_sales_quote_file_tenant (supplier_id, quote_id, id),
  CONSTRAINT fk_sales_quote_file_quote FOREIGN KEY (quote_id) REFERENCES sales_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quote_version_files (
  version_id BIGINT UNSIGNED NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('inline','append','email','internal') NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (version_id, file_id),
  KEY ix_sales_quote_file_versions (file_id),
  CONSTRAINT fk_sales_quote_vfile_version FOREIGN KEY (version_id) REFERENCES sales_quote_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_quote_vfile_file FOREIGN KEY (file_id) REFERENCES sales_quote_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quote_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  event VARCHAR(32) NOT NULL,
  data_json LONGTEXT NOT NULL CHECK (JSON_VALID(data_json)),
  delivery_status ENUM('pending','deferred','sent','failed') NULL,
  pdf_key VARCHAR(80) NULL,
  idempotency_key VARCHAR(128) NULL,
  request_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_quote_event_once (quote_id, idempotency_key),
  KEY ix_sales_quote_events (supplier_id, quote_id, id),
  KEY ix_sales_quote_delivery (quote_id, delivery_status, id),
  CONSTRAINT fk_sales_quote_event_version FOREIGN KEY (version_id) REFERENCES sales_quote_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_quote_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_quote_documents (
  supplier_id INT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  version_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (invoice_id),
  UNIQUE KEY uq_sales_quote_document_once (supplier_id, quote_id, idempotency_key),
  KEY ix_sales_quote_document_version (version_id),
  CONSTRAINT fk_sales_quote_doc_version FOREIGN KEY (version_id) REFERENCES sales_quote_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_quote_doc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS sales_quote_item_key CHAR(36) CHARACTER SET ascii NULL,
  ADD INDEX IF NOT EXISTS ix_invoice_item_sales_quote (sales_quote_item_key, invoice_id);
