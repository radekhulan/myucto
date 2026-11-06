-- EET: tržba v cizí měně.
--
-- Částka evidované tržby je vždy v CZK (payment_events.amount, currency = 'CZK',
-- eet_records.total_amount). Platba přijatá v cizí měně si drží původní měnu,
-- původní částku, kurz a jeho zdroj, aby šlo doložit přepočet a opravu (vratku)
-- přepočítat stejným kurzem jako původní tržbu. U CZK platby zůstávají NULL.

ALTER TABLE payment_events
    ADD COLUMN IF NOT EXISTS original_currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER currency,
    ADD COLUMN IF NOT EXISTS original_amount DECIMAL(14,2) NULL AFTER original_currency,
    ADD COLUMN IF NOT EXISTS exchange_rate DECIMAL(18,8) NULL AFTER original_amount,
    ADD COLUMN IF NOT EXISTS exchange_rate_source VARCHAR(40) NULL AFTER exchange_rate;
