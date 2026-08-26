-- Jednorázové serverové relace pro Mobilní klíč a SMS ISDS.
-- Klient drží pouze náhodný token; v DB je jeho SHA-256 a šifrovaný payload.
CREATE TABLE IF NOT EXISTS submission_isds_auth_flows (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_id        INT UNSIGNED NOT NULL,
  user_id            BIGINT UNSIGNED NOT NULL,
  environment        ENUM('production','test') NOT NULL,
  flow_type          ENUM('mobile_key','sms') NOT NULL,
  payload_ciphertext TEXT NULL,
  status             ENUM('pending','processing','consumed','blocked') NOT NULL DEFAULT 'pending',
  attempts           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts       SMALLINT UNSIGNED NOT NULL,
  expires_at         DATETIME NOT NULL,
  consumed_at        DATETIME NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_isds_auth_flow_token (token_hash),
  KEY idx_isds_auth_flow_scope (supplier_id, user_id, environment, flow_type, status, expires_at),
  KEY idx_isds_auth_flow_expiry (expires_at),
  CONSTRAINT fk_isds_auth_flow_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_isds_auth_flow_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_isds_auth_flow_token_hash
    CHECK (token_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_isds_auth_flow_attempts
    CHECK (max_attempts > 0 AND attempts <= max_attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
