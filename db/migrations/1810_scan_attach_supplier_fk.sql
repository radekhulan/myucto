-- Dávky skenů, uložená vytěžení a kontrola příloh nesou údaje protistran
-- (jména, IČO, čísla dokladů, částky). Při smazání firmy musí zmizet s ní —
-- bez cizího klíče na `supplier` by po smazání zůstaly osiřelé.
--
-- Nejdřív se uklidí řádky firem, které už neexistují, jinak by se cizí klíč
-- nepřidal. Opakovaný běh nic nezmění.

SET NAMES utf8mb4;

DELETE FROM scan_matches WHERE supplier_id NOT IN (SELECT id FROM supplier);
DELETE FROM scan_batch_items WHERE supplier_id NOT IN (SELECT id FROM supplier);
DELETE FROM document_extractions WHERE supplier_id NOT IN (SELECT id FROM supplier);
DELETE FROM attachment_checks WHERE supplier_id NOT IN (SELECT id FROM supplier);

ALTER TABLE scan_batch_items
    ADD CONSTRAINT fk_scanitem_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE scan_matches
    ADD CONSTRAINT fk_scanmatch_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE document_extractions
    ADD CONSTRAINT fk_docext_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE attachment_checks
    ADD CONSTRAINT fk_attcheck_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
