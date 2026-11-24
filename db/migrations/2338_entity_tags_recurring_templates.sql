-- Sdílený firemní číselník. Každý podporovaný druh záznamu má vazbu s vlastním FK.
CREATE TABLE IF NOT EXISTS entity_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  UNIQUE KEY uq_entity_tag_name (supplier_id, name),
  CONSTRAINT fk_entity_tag_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_template_tags (
  entity_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (entity_id, tag_id),
  KEY idx_recurring_tag_entity (tag_id, entity_id),
  CONSTRAINT fk_recurring_tag_template FOREIGN KEY (entity_id) REFERENCES recurring_invoice_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_recurring_tag_tag FOREIGN KEY (tag_id) REFERENCES entity_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
