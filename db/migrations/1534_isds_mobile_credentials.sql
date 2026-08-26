-- Osobní přístup Mobilním klíčem pro konkrétní firmu, uživatele a prostředí.
-- Nejde o souhlas s automatickým vybíráním: tabulka drží jen šifrované vstupy
-- pro ručně spuštěnou relaci, kterou uživatel pokaždé potvrdí v mobilu.
CREATE TABLE IF NOT EXISTS submission_isds_mobile_credentials (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                   INT UNSIGNED NOT NULL,
  user_id                       BIGINT UNSIGNED NOT NULL,
  environment                   ENUM('production','test') NOT NULL,
  username_ciphertext           TEXT NOT NULL,
  communication_code_ciphertext TEXT NOT NULL,
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_isds_mobile_credential_scope (supplier_id, user_id, environment),
  CONSTRAINT fk_isds_mobile_credential_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_isds_mobile_credential_user
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_isds_mobile_username_encrypted
    CHECK (username_ciphertext LIKE 'enc:v%'),
  CONSTRAINT chk_isds_mobile_code_encrypted
    CHECK (communication_code_ciphertext LIKE 'enc:v%')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
