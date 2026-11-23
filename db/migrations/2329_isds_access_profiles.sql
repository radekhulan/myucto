CREATE TABLE IF NOT EXISTS isds_access_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  personal_supplier_id INT UNSIGNED NULL,
  scope ENUM('personal','shared') NOT NULL,
  environment ENUM('production','test') NOT NULL,
  method ENUM('password','certificate','user_certificate') NOT NULL,
  box_id CHAR(7) NOT NULL,
  label VARCHAR(120) NOT NULL,
  certificate_id BIGINT UNSIGNED NULL,
  secret_context CHAR(32) NOT NULL,
  username_ciphertext TEXT NULL,
  password_ciphertext TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isds_access_personal (owner_user_id, personal_supplier_id, environment, box_id),
  KEY ix_isds_access_box (environment, box_id),
  KEY ix_isds_access_certificate (certificate_id),
  CONSTRAINT fk_isds_access_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_isds_access_personal_supplier FOREIGN KEY (personal_supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_isds_access_certificate FOREIGN KEY (certificate_id) REFERENCES epo_signing_credentials (id) ON DELETE CASCADE,
  CONSTRAINT chk_isds_access_scope CHECK (
    (scope = 'personal' AND personal_supplier_id IS NOT NULL AND method = 'password')
    OR (scope = 'shared' AND personal_supplier_id IS NULL)
  ),
  CONSTRAINT chk_isds_access_username_encrypted CHECK (username_ciphertext IS NULL OR username_ciphertext LIKE 'enc:v%'),
  CONSTRAINT chk_isds_access_password_encrypted CHECK (password_ciphertext IS NULL OR password_ciphertext LIKE 'enc:v%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isds_access_profile_suppliers (
  profile_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  can_send TINYINT(1) NOT NULL,
  can_receive TINYINT(1) NOT NULL,
  can_receipts TINYINT(1) NOT NULL,
  PRIMARY KEY (profile_id, supplier_id),
  KEY ix_isds_access_supplier_profile (supplier_id, profile_id),
  CONSTRAINT fk_isds_access_supplier_profile FOREIGN KEY (profile_id) REFERENCES isds_access_profiles (id) ON DELETE CASCADE,
  CONSTRAINT fk_isds_access_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isds_access_inbox_owners (
  environment ENUM('production','test') NOT NULL,
  box_id CHAR(7) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (environment, box_id),
  CONSTRAINT fk_isds_access_inbox_owner FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE submission_outbox
  ADD COLUMN IF NOT EXISTS isds_sender_box_id CHAR(7) NULL AFTER recipient_box_id;
