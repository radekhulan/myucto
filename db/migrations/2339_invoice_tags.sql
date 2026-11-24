-- Samostatné přiřazení zůstává faktuře i po odstranění zdrojové šablony.
CREATE TABLE IF NOT EXISTS invoice_tags (
  entity_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (entity_id, tag_id),
  KEY idx_invoice_tag_entity (tag_id, entity_id),
  CONSTRAINT fk_invoice_tag_invoice FOREIGN KEY (entity_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_invoice_tag_tag FOREIGN KEY (tag_id) REFERENCES entity_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
