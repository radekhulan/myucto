-- Explicitně nastavený začátek číselné řady (PUT /api/settings/supplier/invoice-counter,
-- „příští faktura bude č. 100").
--
-- VarsymbolGenerator::next() srovnává počítadlo, které utíká před skutečně vydanými
-- čísly (díra na konci řady), dolů na nejvyšší vydané číslo. Záměrné přeskočení na
-- vyšší číslo ale díra není. floor_number drží hodnotu last_number z posledního
-- ručního nastavení a srovnání pod ni nikdy nejde. 0 = řada nemá ruční začátek.

SET NAMES utf8mb4;

ALTER TABLE invoice_counters
  ADD COLUMN IF NOT EXISTS floor_number INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'last_number z ručního nastavení řady; pojistka proti dírám pod něj počítadlo nesnižuje'
    AFTER last_number;
