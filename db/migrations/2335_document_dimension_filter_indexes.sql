-- Filtr seznamů vydaných a přijatých faktur podle hodnoty dimenze: doklad vyhovuje,
-- nese-li hodnotu na hlavičce, na položce nebo v rozpadu. Dotaz vybírá id dokladů
-- jedné firmy a druhu dokladu s danou hodnotou typu; index ho pokryje celý bez
-- čtení řádků tabulky (dosavadní indexy začínají hodnotou bez druhu dokladu).
ALTER TABLE document_dimensions
  ADD INDEX IF NOT EXISTS idx_docdim_doc_filter (supplier_id, doc_type, dimension_type_id, dimension_value_id, doc_id);

ALTER TABLE document_dimension_splits
  ADD INDEX IF NOT EXISTS idx_docdims_doc_filter (supplier_id, doc_type, dimension_type_id, dimension_value_id, doc_id);
