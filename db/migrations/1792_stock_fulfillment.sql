-- SK-14: vychystani, zasilky a fyzicke vratky.
-- Vsechny skladove pohyby zustavaji ve stock_documents; tabulky nize drzi workflow,
-- immutable snapshoty a idempotencni operace ctecky.

SET NAMES utf8mb4;

ALTER TABLE warehouses
  ADD COLUMN IF NOT EXISTS is_sellable TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

CREATE TABLE IF NOT EXISTS fulfillment_tasks (
  id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id               INT UNSIGNED NOT NULL,
  source_type               VARCHAR(32) NOT NULL,
  source_id                 VARCHAR(191) NOT NULL,
  claimed_stock_document_id BIGINT UNSIGNED NULL,
  source_snapshot           JSON NOT NULL,
  status                    ENUM('picking','packing','partially_shipped','shipped','cancelled') NOT NULL DEFAULT 'picking',
  created_by                INT UNSIGNED NULL,
  created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ft_source (supplier_id, source_type, source_id),
  UNIQUE KEY uq_ft_claimed_document (claimed_stock_document_id),
  KEY idx_ft_supplier_status (supplier_id, status, id),
  CONSTRAINT fk_ft_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ft_claimed_document FOREIGN KEY (claimed_stock_document_id) REFERENCES stock_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role_id, permission_key, access_level)
SELECT r.id, p.permission_key, 2
FROM roles r
CROSS JOIN (
  SELECT 'stock.fulfillment.write' AS permission_key
  UNION ALL SELECT 'stock.fulfillment.override'
) p
WHERE r.system_key IN ('admin', 'admin_plus');

DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
WHERE r.system_key = 'superadmin' AND rp.permission_key IN ('stock.fulfillment.write', 'stock.fulfillment.override');

CREATE TABLE IF NOT EXISTS fulfillment_task_lines (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id               BIGINT UNSIGNED NOT NULL,
  supplier_id           INT UNSIGNED NOT NULL,
  source_line_id        VARCHAR(191) NOT NULL,
  stock_item_id         BIGINT UNSIGNED NOT NULL,
  warehouse_id          BIGINT UNSIGNED NOT NULL,
  component_snapshot    JSON NOT NULL,
  expected_qty          DECIMAL(14,3) NOT NULL,
  picked_qty            DECIMAL(14,3) NOT NULL DEFAULT 0,
  shipped_qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
  returned_qty          DECIMAL(14,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ftl_source (task_id, source_line_id),
  KEY idx_ftl_supplier_item (supplier_id, stock_item_id, warehouse_id),
  CONSTRAINT fk_ftl_task FOREIGN KEY (task_id) REFERENCES fulfillment_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_ftl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_ftl_item FOREIGN KEY (stock_item_id) REFERENCES stock_items(id),
  CONSTRAINT fk_ftl_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fulfillment_scan_operations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  task_id             BIGINT UNSIGNED NOT NULL,
  client_operation_id VARCHAR(64) NOT NULL,
  payload_hash        CHAR(64) NOT NULL,
  payload_json        JSON NOT NULL,
  result_json         JSON NOT NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fso_operation (supplier_id, task_id, client_operation_id),
  CONSTRAINT fk_fso_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_fso_task FOREIGN KEY (task_id) REFERENCES fulfillment_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fulfillment_shipments (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id       INT UNSIGNED NOT NULL,
  task_id           BIGINT UNSIGNED NOT NULL,
  carrier           VARCHAR(100) NULL,
  tracking_number   VARCHAR(150) NULL,
  status            ENUM('packing','shipped','cancelled') NOT NULL DEFAULT 'packing',
  issue_document_id BIGINT UNSIGNED NULL,
  shipped_at        TIMESTAMP NULL,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fs_supplier_task (supplier_id, task_id, id),
  UNIQUE KEY uq_fs_issue_document (issue_document_id),
  CONSTRAINT fk_fs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_task FOREIGN KEY (task_id) REFERENCES fulfillment_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_issue_document FOREIGN KEY (issue_document_id) REFERENCES stock_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fulfillment_shipment_items (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id            BIGINT UNSIGNED NOT NULL,
  supplier_id            INT UNSIGNED NOT NULL,
  task_line_id           BIGINT UNSIGNED NOT NULL,
  qty                    DECIMAL(14,3) NOT NULL,
  tracking_snapshot      JSON NOT NULL,
  issue_document_line_id BIGINT UNSIGNED NULL,
  unit_cost_snapshot     DECIMAL(15,6) NULL,
  returned_qty           DECIMAL(14,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_fsi_line (shipment_id, task_line_id),
  KEY idx_fsi_supplier_task_line (supplier_id, task_line_id),
  CONSTRAINT fk_fsi_shipment FOREIGN KEY (shipment_id) REFERENCES fulfillment_shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_fsi_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_fsi_task_line FOREIGN KEY (task_line_id) REFERENCES fulfillment_task_lines(id),
  CONSTRAINT fk_fsi_issue_line FOREIGN KEY (issue_document_line_id) REFERENCES stock_document_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fulfillment_returns (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  shipment_id         BIGINT UNSIGNED NOT NULL,
  disposition         ENUM('sellable','quarantine','scrap') NOT NULL,
  warehouse_id        BIGINT UNSIGNED NULL,
  receipt_document_id BIGINT UNSIGNED NULL,
  note                VARCHAR(500) NULL,
  received_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by          INT UNSIGNED NULL,
  KEY idx_fr_supplier_shipment (supplier_id, shipment_id, id),
  CONSTRAINT fk_fr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr_shipment FOREIGN KEY (shipment_id) REFERENCES fulfillment_shipments(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_fr_receipt_document FOREIGN KEY (receipt_document_id) REFERENCES stock_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fulfillment_return_items (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id           BIGINT UNSIGNED NOT NULL,
  supplier_id         INT UNSIGNED NOT NULL,
  shipment_item_id    BIGINT UNSIGNED NOT NULL,
  qty                 DECIMAL(14,3) NOT NULL,
  component_snapshot  JSON NOT NULL,
  UNIQUE KEY uq_fri_shipment_item (return_id, shipment_item_id),
  CONSTRAINT fk_fri_return FOREIGN KEY (return_id) REFERENCES fulfillment_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_fri_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_fri_shipment_item FOREIGN KEY (shipment_item_id) REFERENCES fulfillment_shipment_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
