CREATE TABLE IF NOT EXISTS nonprofit_tax_saving_cost_bindings (
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NOT NULL,
 cost_line_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(supplier_id,event_id),
 KEY ix_ntscb_cost(supplier_id,cost_line_id),
 FOREIGN KEY(supplier_id,source_id,event_id) REFERENCES nonprofit_tax_saving_events(supplier_id,source_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,cost_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO nonprofit_tax_saving_cost_bindings(supplier_id,source_id,event_id,cost_line_id)
 SELECT e.supplier_id,e.source_id,e.id,e.cost_line_id FROM nonprofit_tax_saving_events e
 WHERE e.operation='use'
 AND NOT EXISTS(SELECT 1 FROM nonprofit_tax_saving_events r WHERE r.supplier_id=e.supplier_id AND r.source_id=e.source_id AND r.original_id=e.id AND r.operation='reverse_use')
 AND NOT EXISTS(SELECT 1 FROM nonprofit_tax_saving_cost_bindings b WHERE b.supplier_id=e.supplier_id AND b.event_id=e.id);
-- Vazbu aktivního čerpání na nákladový řádek přebírá nonprofit_tax_saving_cost_bindings.
-- FK má explicitní jméno z 2061, takže se nespoléhá na automatické číslování ibfk_N.
ALTER TABLE nonprofit_tax_saving_events DROP FOREIGN KEY IF EXISTS fk_ntse_cost_line;
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_ntscb_native_insert BEFORE INSERT ON nonprofit_tax_saving_cost_bindings FOR EACH ROW BEGIN
 IF NOT EXISTS(SELECT 1 FROM nonprofit_tax_saving_events e WHERE e.supplier_id=NEW.supplier_id AND e.source_id=NEW.source_id AND e.id=NEW.event_id AND e.operation='use' AND e.cost_line_id=NEW.cost_line_id
 AND NOT EXISTS(SELECT 1 FROM nonprofit_tax_saving_events r WHERE r.supplier_id=e.supplier_id AND r.source_id=e.source_id AND r.original_id=e.id AND r.operation='reverse_use')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_saving_binding_invalid'; END IF;
END$$
CREATE OR REPLACE TRIGGER trg_ntscb_immutable_update BEFORE UPDATE ON nonprofit_tax_saving_cost_bindings FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntscb_reverse_delete BEFORE DELETE ON nonprofit_tax_saving_cost_bindings FOR EACH ROW BEGIN
 IF NOT EXISTS(SELECT 1 FROM nonprofit_tax_saving_events e JOIN nonprofit_tax_saving_events r ON r.supplier_id=e.supplier_id AND r.source_id=e.source_id AND r.original_id=e.id AND r.operation='reverse_use' AND r.cost_line_id=e.cost_line_id AND r.period_id=e.period_id AND r.amount_cents=e.amount_cents
 WHERE e.supplier_id=OLD.supplier_id AND e.source_id=OLD.source_id AND e.id=OLD.event_id AND e.operation='use' AND e.cost_line_id=OLD.cost_line_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_tax_saving_binding_in_use'; END IF;
END$$
DELIMITER ;
