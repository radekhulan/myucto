-- Odeslání příkazu po položkách (Česká spořitelna, Premium Payments v1): důvod odmítnutí
-- od banky (kód, pole a text hlášky) u položky i u celého odeslání, aby ho uživatel viděl
-- v aplikaci. provider_sign_scope říká, co se podepisuje: dávka (batch), nebo jediná
-- platba bez dávky (payment), která má vlastní adresu podpisu i dotaz na jeho stav.
SET NAMES utf8mb4;

ALTER TABLE bank_payment_order_submissions
    ADD COLUMN IF NOT EXISTS provider_sign_scope ENUM('batch','payment') NOT NULL DEFAULT 'batch' AFTER provider_sign_hash,
    ADD COLUMN IF NOT EXISTS provider_error_code VARCHAR(64) NULL AFTER error_code,
    ADD COLUMN IF NOT EXISTS provider_error_scope VARCHAR(128) NULL AFTER provider_error_code,
    ADD COLUMN IF NOT EXISTS provider_error_message VARCHAR(255) NULL AFTER provider_error_scope;

ALTER TABLE bank_payment_submission_items
    ADD COLUMN IF NOT EXISTS provider_error_code VARCHAR(64) NULL AFTER error_code,
    ADD COLUMN IF NOT EXISTS provider_error_scope VARCHAR(128) NULL AFTER provider_error_code,
    ADD COLUMN IF NOT EXISTS provider_error_message VARCHAR(255) NULL AFTER provider_error_scope;
