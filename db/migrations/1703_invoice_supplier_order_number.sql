-- Strukturovane cislo objednavky dodavatele na vydanem dokladu.

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS supplier_order_number VARCHAR(80) NULL AFTER varsymbol,
  ADD KEY IF NOT EXISTS idx_invoices_supplier_order (supplier_id, supplier_order_number);

UPDATE invoices
   SET supplier_order_number = TRIM(
       SUBSTRING_INDEX(
           SUBSTRING(
               note_below_items,
               LOCATE('Objednávka:', note_below_items) + CHAR_LENGTH('Objednávka:')
           ),
           CHAR(10),
           1
       )
   )
 WHERE supplier_order_number IS NULL
   AND note_below_items LIKE '%Objednávka:%'
   AND TRIM(
       SUBSTRING_INDEX(
           SUBSTRING(
               note_below_items,
               LOCATE('Objednávka:', note_below_items) + CHAR_LENGTH('Objednávka:')
           ),
           CHAR(10),
           1
       )
   ) REGEXP '^[[:alnum:]_-]{1,80}$';
