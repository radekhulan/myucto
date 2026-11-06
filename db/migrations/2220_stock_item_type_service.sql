-- Skladová karta typu služba: slouží k výběru na dokladech, cenám a přehledu prodejů, zásobu nevede.
ALTER TABLE stock_items
  MODIFY COLUMN item_type ENUM('material','goods','product','service') NOT NULL DEFAULT 'goods';
