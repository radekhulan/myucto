-- 1762: KB+ client registration and single-use OAuth onboarding state.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_oauth_clients (
  supplier_id             INT UNSIGNED NOT NULL,
  provider                VARCHAR(32) NOT NULL,
  credentials_ciphertext  MEDIUMTEXT NOT NULL,
  registered_by_user_id   BIGINT UNSIGNED NULL,
  registered_at           DATETIME NOT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, provider),
  CONSTRAINT fk_bank_oauth_client_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bank_oauth_client_user
    FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_oauth_sessions (
  state_hash              CHAR(64) PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  currency_id             INT UNSIGNED NOT NULL,
  user_id                 BIGINT UNSIGNED NOT NULL,
  provider                VARCHAR(32) NOT NULL,
  stage                   ENUM('registration','oauth') NOT NULL,
  status                  ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  secret_ciphertext       MEDIUMTEXT NOT NULL,
  error_code              VARCHAR(80) NULL,
  expires_at              DATETIME(6) NOT NULL,
  consumed_at             DATETIME(6) NULL,
  created_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at              DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_bank_oauth_session_account (supplier_id, currency_id, provider, created_at),
  KEY idx_bank_oauth_session_expiry (status, expires_at),
  CONSTRAINT fk_bank_oauth_session_currency
    FOREIGN KEY (supplier_id, currency_id) REFERENCES currencies(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_bank_oauth_session_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
