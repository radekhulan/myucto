-- Dávka uchovává schválený požadavek; každý člen má samostatnou finanční operaci.
CREATE TABLE IF NOT EXISTS nonprofit_membership_batches (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 confirmed_by BIGINT UNSIGNED NULL,
 confirmed_at DATETIME(6) NULL,
 batch_uid CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 template_json LONGTEXT NOT NULL CHECK (JSON_VALID(template_json)),
 selection_mode ENUM('active','selected') NOT NULL,
 selection_json LONGTEXT NOT NULL CHECK (JSON_VALID(selection_json)),
 seed_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 seed_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
 phase ENUM('seeding','preparing','ready','posting','finished','cancelled') NOT NULL DEFAULT 'seeding',
 approval_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 approval_expires_at DATETIME(6) NULL,
 member_count INT UNSIGNED NOT NULL DEFAULT 0,
 pending_count INT UNSIGNED NOT NULL DEFAULT 0,
 ready_count INT UNSIGNED NOT NULL DEFAULT 0,
 created_count INT UNSIGNED NOT NULL DEFAULT 0,
 existing_count INT UNSIGNED NOT NULL DEFAULT 0,
 rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
 planned_cents DECIMAL(24,0) NOT NULL DEFAULT 0,
 created_cents DECIMAL(24,0) NOT NULL DEFAULT 0,
 deferred_cents DECIMAL(24,0) NOT NULL DEFAULT 0,
 deferral_date DATE NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmb_tenant (supplier_id,id),
 UNIQUE KEY uq_nmb_key (supplier_id,idempotency_key),
 UNIQUE KEY uq_nmb_uid (batch_uid),
 FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_membership_batch_members (
 supplier_id INT UNSIGNED NOT NULL,
 batch_id BIGINT UNSIGNED NOT NULL,
 member_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','ready','created','existing','rejected') NOT NULL DEFAULT 'pending',
 amount_cents BIGINT NOT NULL,
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 expected_case_version INT UNSIGNED NULL,
 operation_id BIGINT UNSIGNED NULL,
 error_code VARCHAR(100) NULL,
 error_message VARCHAR(2000) NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (supplier_id,batch_id,member_id),
 KEY ix_nmbm_status (supplier_id,batch_id,status,member_id),
 FOREIGN KEY (supplier_id,batch_id) REFERENCES nonprofit_membership_batches(supplier_id,id) ON DELETE CASCADE,
 FOREIGN KEY (supplier_id,member_id) REFERENCES nonprofit_members(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY (supplier_id,operation_id) REFERENCES nonprofit_membership_charge_operations(supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Doplnění je opakovatelné i nad rozpracovanou vývojovou dávkou.
ALTER TABLE nonprofit_membership_batch_members
 ADD COLUMN IF NOT EXISTS deferred_cents BIGINT NOT NULL DEFAULT 0,
 ADD COLUMN IF NOT EXISTS deferral_date DATE NULL;
