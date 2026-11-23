CREATE TABLE IF NOT EXISTS stock_sales_retired_skus (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  sku         VARCHAR(50) NOT NULL,
  UNIQUE KEY uq_ssrs_supplier_sku (supplier_id, sku),
  CONSTRAINT fk_ssrs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_stock_sales_register_previous_sku
AFTER UPDATE ON stock_items FOR EACH ROW
BEGIN
  IF OLD.sku <> NEW.sku OR OLD.supplier_id <> NEW.supplier_id THEN
    INSERT IGNORE INTO stock_sales_retired_skus (supplier_id, sku) VALUES (OLD.supplier_id, OLD.sku);
  END IF;
END$$
CREATE TRIGGER IF NOT EXISTS trg_stock_sales_register_deleted_sku
AFTER DELETE ON stock_items FOR EACH ROW
BEGIN
  INSERT IGNORE INTO stock_sales_retired_skus (supplier_id, sku) VALUES (OLD.supplier_id, OLD.sku);
END$$
DELIMITER ;

INSERT IGNORE INTO stock_sales_retired_skus (supplier_id, sku)
SELECT DISTINCT recorded.supplier_id, recorded.sku
  FROM (
    SELECT l.supplier_id, l.entity_id, CONVERT(JSON_VALUE(l.payload, '$.sku') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS sku
      FROM activity_log l
     WHERE l.action IN ('stock.item_created', 'stock.item_updated')
       AND l.entity_type = 'stock_item' AND l.entity_id IS NOT NULL
  ) recorded
  JOIN supplier s ON s.id = recorded.supplier_id
  LEFT JOIN stock_items si ON si.id = recorded.entity_id AND si.supplier_id = recorded.supplier_id AND si.sku = recorded.sku
 WHERE si.id IS NULL AND CHAR_LENGTH(recorded.sku) BETWEEN 1 AND 50;
