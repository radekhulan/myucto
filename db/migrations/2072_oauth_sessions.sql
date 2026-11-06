-- MyÚčto.cz — rozpracovaná OAuth přihlášení k externím službám (MyÚčto je klient OAuth).
--
--   oauth_sessions  zobecnění bank_oauth_sessions: jedno rozpracované přihlášení
--                   (přesměrování na autorizační server nebo Device Code Flow).
--                   `domain` = oblast napojení (bank, odometer, …), `provider` = kód
--                   poskytovatele v doméně, `subject_id` = co se připojuje (bankovní
--                   účet = currencies.id, zdroj tachometru = odometer_source_accounts.id).
--                   `state_hash` je SHA-256 hodnoty state; samotný state se neukládá.
--                   `secret_ciphertext` je výhradně context-bound enc:v2 ciphertext
--                   (SecretEncryption::encryptFor, kontext firma + doména + poskytovatel).
--                   Po dokončení se tajemství maže; řádky po expiraci uklízí cron-cleanup.
--
-- Rozpracovaná připojení v bank_oauth_sessions z doby před migrací dobíhají přes
-- původní čtení (CSAS a KB+), tabulka proto zůstává.
--
--   odometer_source_accounts.pending_ciphertext  rozpracované přihlášení zdroje stavu
--                   tachometru je nově v oauth_sessions; `pending_state_hash` zůstává
--                   jako klíč do oauth_sessions. Sloupec zatím nebyl vydaný (migrace 2071),
--                   takže se ruší bez převodu dat.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, DROP COLUMN IF EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS oauth_sessions (
    state_hash        CHAR(64) NOT NULL PRIMARY KEY,
    supplier_id       INT UNSIGNED NOT NULL,
    user_id           BIGINT UNSIGNED NOT NULL,
    domain            VARCHAR(32) NOT NULL,
    provider          VARCHAR(32) NOT NULL,
    subject_id        INT UNSIGNED NOT NULL,
    flow              ENUM('redirect','device_code') NOT NULL,
    stage             VARCHAR(32) NOT NULL DEFAULT 'oauth',
    status            ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    secret_ciphertext MEDIUMTEXT NOT NULL,
    error_code        VARCHAR(80) NULL,
    expires_at        DATETIME(6) NOT NULL,
    consumed_at       DATETIME(6) NULL,
    created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    KEY idx_oauth_session_subject (supplier_id, domain, provider, subject_id, created_at),
    KEY idx_oauth_session_user (supplier_id, user_id, domain, provider, stage, status),
    KEY idx_oauth_session_expiry (expires_at),

    CONSTRAINT fk_oauth_session_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_oauth_session_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE odometer_source_accounts
    DROP COLUMN IF EXISTS pending_ciphertext;
