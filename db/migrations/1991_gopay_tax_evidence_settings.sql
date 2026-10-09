-- MyÚčto.cz — GoPay i v daňové evidenci.
--
-- Daňová evidence nemá účtový rozvrh: nastavení GoPay tam nese jen výplatní účet GoPay
-- a toleranci data výplaty. Účty pro automatické účtování proto smí být prázdné;
-- podvojné účetnictví je dál vyžaduje (GoPayService je bez všech pěti účtů nepovažuje
-- za nastavené). Cizí klíče na chart_of_accounts zůstávají, NULL je nekontrolují.
--
-- Idempotence: MODIFY COLUMN na stejnou definici nic nemění.

SET NAMES utf8mb4;

ALTER TABLE gopay_settings
  MODIFY COLUMN gopay_account_id            BIGINT UNSIGNED NULL,
  MODIFY COLUMN receivable_account_id       BIGINT UNSIGNED NULL,
  MODIFY COLUMN fee_account_id              BIGINT UNSIGNED NULL,
  MODIFY COLUMN clearing_account_id         BIGINT UNSIGNED NULL,
  MODIFY COLUMN destination_bank_account_id BIGINT UNSIGNED NULL;
