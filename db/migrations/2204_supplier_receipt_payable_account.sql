-- Účet závazku pro účtenky / paragony: per-supplier volba saldokontního účtu přijatých
-- dokladů druhu „Účtenka / paragon" (purchase_invoices.document_kind = 'receipt').
--
-- PROČ: část účetních vede účtenky placené hotově nebo kartou na 325 (ostatní závazky),
-- ne na 321 (dodavatelé). Vyplněný účet nahradí výchozí závazek předkontace u účtenek;
-- úhrady (pokladna, banka, karta, zápočty) pak odúčtují účet, na kterém závazek dokladu
-- skutečně vznikl (viz PurchasePayableAccount).
--
-- Výchozí NULL = beze změny, účtenka se účtuje na závazek z předkontace (321).
--
-- Idempotence: ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS receipt_payable_account_code VARCHAR(10) NULL DEFAULT NULL AFTER auto_post_purchases;
