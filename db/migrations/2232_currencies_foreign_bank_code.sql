-- Kód banky vlastního účtu pojme i zahraniční národní kód (britský sort code
-- 60-84-64, routing number). CHAR(4) stačil jen na český kód banky a uložení
-- britského účtu končilo chybou databáze. Šířka 10 odpovídá
-- payment_orders.payer_bank_code, kam se kód účtu plátce přenáší.
-- Rozšíření nemění uložené hodnoty a opakované spuštění nic nezmění.

ALTER TABLE currencies
  MODIFY COLUMN bank_code VARCHAR(10) NULL DEFAULT NULL;
