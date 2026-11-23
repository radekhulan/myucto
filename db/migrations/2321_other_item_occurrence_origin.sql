-- Zrušení opakování nesmaže původ už vytvořených položek. Výskyt si drží zdrojový
-- doklad sám a vazba na zrušený rozvrh se jen vynuluje (dřív ji smazal ON DELETE CASCADE).
ALTER TABLE other_item_schedule_occurrences
  ADD COLUMN IF NOT EXISTS source_item_id BIGINT UNSIGNED NULL AFTER item_id;

UPDATE other_item_schedule_occurrences o
  JOIN other_item_schedules s ON s.id = o.schedule_id AND s.supplier_id = o.supplier_id
   SET o.source_item_id = s.source_item_id
 WHERE o.source_item_id IS NULL;

ALTER TABLE other_item_schedule_occurrences
  DROP FOREIGN KEY IF EXISTS fk_other_occurrence_schedule;

ALTER TABLE other_item_schedule_occurrences
  MODIFY COLUMN schedule_id BIGINT UNSIGNED NULL;

ALTER TABLE other_item_schedule_occurrences
  ADD CONSTRAINT fk_other_occurrence_schedule FOREIGN KEY IF NOT EXISTS (schedule_id)
    REFERENCES other_item_schedules(id) ON DELETE SET NULL;

ALTER TABLE other_item_schedule_occurrences
  ADD KEY IF NOT EXISTS idx_other_occurrence_source (source_item_id);

ALTER TABLE other_item_schedule_occurrences
  ADD CONSTRAINT fk_other_occurrence_source FOREIGN KEY IF NOT EXISTS (source_item_id)
    REFERENCES other_items(id) ON DELETE SET NULL;
