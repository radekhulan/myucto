-- MyÚčto — EET 2.0: nastavení evidence tržeb, evidenční jednotky, logická zařízení
-- (pokladny) a pokladní certifikáty firmy.
--
-- Jednotka je místní obraz provozovny/kanálu oznámeného finanční správě (id_jednotky
-- z DIS+); zařízení je logická pokladna (id_pokl) s vlastní číselnou řadou účtenek.
-- Certifikát se ukládá jen šifrovaně (PKCS#12 i heslo) s kontextem firmy a otisku;
-- v tabulce zůstávají čitelná pouze bezpečná metadata.
--
-- Oprávnění skupiny `eet` pro systémové role seeduje migrace 2183.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS eet_units (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_number INT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 kind ENUM('premises','online','vehicle','mobile','other') NOT NULL,
 valid_from DATE NOT NULL,
 valid_to DATE NULL,
 notified_to_fs TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_eet_unit_tenant (supplier_id, id),
 UNIQUE KEY uq_eet_unit_number (supplier_id, unit_number),
 KEY ix_eet_unit_valid (supplier_id, valid_from),
 CONSTRAINT chk_eet_unit_validity CHECK (valid_to IS NULL OR valid_to >= valid_from),
 CONSTRAINT fk_eet_unit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vazba zařízení na jednotku je složená (supplier_id, unit_id), takže zařízení
-- nemůže ukazovat na jednotku jiné firmy ani při chybě v aplikaci. Jednotky se
-- fyzicky nemažou (ukončuje se účinnost); kaskáda platí jen pro smazání firmy.
CREATE TABLE IF NOT EXISTS eet_devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 unit_id BIGINT UNSIGNED NOT NULL,
 code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(120) NOT NULL,
 cash_register_id BIGINT UNSIGNED NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sequence_year SMALLINT UNSIGNED NULL,
 next_sequence INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_eet_device_tenant (supplier_id, id),
 UNIQUE KEY uq_eet_device_code (supplier_id, code),
 KEY ix_eet_device_unit (supplier_id, unit_id),
 KEY ix_eet_device_cash_register (cash_register_id),
 CONSTRAINT fk_eet_device_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
 CONSTRAINT fk_eet_device_unit FOREIGN KEY (supplier_id, unit_id) REFERENCES eet_units(supplier_id, id) ON DELETE CASCADE,
 CONSTRAINT fk_eet_device_cash_register FOREIGN KEY (cash_register_id) REFERENCES cash_registers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eet_settings (
 supplier_id INT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 environment ENUM('playground','production') NOT NULL DEFAULT 'playground',
 eic VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NULL,
 active_from DATE NULL,
 active_to DATE NULL,
 regime ENUM('standard','exempt','eet_off') NOT NULL DEFAULT 'standard',
 exemption_reason VARCHAR(255) NULL,
 default_device_id BIGINT UNSIGNED NULL,
 response_timeout_ms INT UNSIGNED NOT NULL DEFAULT 3000,
 dispatch_blocked TINYINT(1) NOT NULL DEFAULT 0,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_eet_settings_default_device (default_device_id),
 CONSTRAINT chk_eet_settings_validity CHECK (active_to IS NULL OR active_from IS NULL OR active_to >= active_from),
 CONSTRAINT fk_eet_settings_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
 CONSTRAINT fk_eet_settings_default_device FOREIGN KEY (default_device_id) REFERENCES eet_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historie nastavení: snímek po každé změně, nikdy s tajemstvím.
CREATE TABLE IF NOT EXISTS eet_settings_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 changed_at DATETIME(6) NOT NULL,
 changed_by BIGINT UNSIGNED NULL,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 KEY ix_eet_settings_history (supplier_id, changed_at, id),
 CONSTRAINT fk_eet_settings_history_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eet_certificates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 environment ENUM('playground','production') NOT NULL,
 eic VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 subject_cn VARCHAR(64) NULL,
 serial_number VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
 fingerprint_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 issuer VARCHAR(255) NULL,
 not_before DATETIME NOT NULL,
 not_after DATETIME NOT NULL,
 p12_enc MEDIUMTEXT NOT NULL,
 password_enc TEXT NOT NULL,
 label VARCHAR(120) NULL,
 status ENUM('active','retired','revoked') NOT NULL DEFAULT 'active',
 uploaded_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 retired_at DATETIME NULL,
 UNIQUE KEY uq_eet_certificate_tenant (supplier_id, id),
 UNIQUE KEY uq_eet_certificate_fingerprint (supplier_id, fingerprint_sha256),
 KEY ix_eet_certificate_active (supplier_id, environment, status, not_after),
 CONSTRAINT fk_eet_certificate_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
