-- MyÚčto.cz — technický správce instance bez přístupu k firemním agendám.
-- Opakované spuštění zachová roli, její aktivitu, přiřazení i uložená tajemství.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE roles
  MODIFY COLUMN role_type ENUM('superadmin','staff','client','instance_admin') NOT NULL;

INSERT INTO roles (system_key, name, role_type, is_active)
SELECT 'instance_admin', 'Správce instance', 'instance_admin', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE system_key = 'instance_admin');

CREATE TABLE IF NOT EXISTS user_technical_access (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  all_suppliers TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_technical_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_technical_suppliers (
  user_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, supplier_id),
  KEY idx_technical_supplier (supplier_id),
  CONSTRAINT fk_technical_supplier_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_technical_supplier_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instance_smtp_targets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  host VARCHAR(253) NOT NULL,
  port SMALLINT UNSIGNED NOT NULL,
  encryption ENUM('tls','ssl') NOT NULL,
  username VARCHAR(190) NOT NULL,
  from_email VARCHAR(190) NOT NULL,
  test_recipient VARCHAR(190) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instance_smtp_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  target_id INT UNSIGNED NOT NULL,
  password_ciphertext TEXT NOT NULL,
  test_attempted_at DATETIME NULL DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_instance_smtp_settings_target FOREIGN KEY (target_id) REFERENCES instance_smtp_targets(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instance_smtp_test_attempts (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  attempted_at DATETIME NOT NULL,
  CONSTRAINT fk_instance_smtp_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE instance_smtp_settings
  ADD COLUMN IF NOT EXISTS test_attempted_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS instance_admin_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  result VARCHAR(30) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_instance_admin_events_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
