SET NAMES utf8mb4;
ALTER TABLE accounting_periods ADD UNIQUE INDEX IF NOT EXISTS uq_dppo_period_tenant(supplier_id,id);
ALTER TABLE income_tax_return_snapshots ADD UNIQUE INDEX IF NOT EXISTS uq_dppo_snapshot_tenant(supplier_id,id);
CREATE TABLE IF NOT EXISTS nonprofit_legal_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 profile_json LONGTEXT NOT NULL CHECK(JSON_VALID(profile_json)),
 module_proof_json LONGTEXT NOT NULL CHECK(JSON_VALID(module_proof_json)),
 reason TEXT NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nlp_tenant(supplier_id,id),
 UNIQUE KEY uq_nlp_period(supplier_id,period_id,id),
 UNIQUE KEY uq_nlp_revision(supplier_id,period_id,revision_no),
 UNIQUE KEY uq_nlp_key(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id,previous_id) REFERENCES nonprofit_legal_profiles(supplier_id,period_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(revision_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nonprofit_tax_saving_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 snapshot_id BIGINT UNSIGNED NOT NULL,
 legal_profile_id BIGINT UNSIGNED NOT NULL,
 reduction_kc BIGINT UNSIGNED NOT NULL,
 saving_cents BIGINT UNSIGNED NOT NULL,
 basis_json LONGTEXT NOT NULL CHECK(JSON_VALID(basis_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_ntss_tenant(supplier_id,id),
 UNIQUE KEY uq_ntss_snapshot(supplier_id,snapshot_id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,snapshot_id) REFERENCES income_tax_return_snapshots(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,legal_profile_id) REFERENCES nonprofit_legal_profiles(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(reduction_kc>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nonprofit_tax_saving_commands (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_ntsc_tenant(supplier_id,id),
 UNIQUE KEY uq_ntsc_key(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nonprofit_tax_saving_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 command_id BIGINT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 original_id BIGINT UNSIGNED NULL,
 operation ENUM('use','reverse_use','void','restore') NOT NULL,
 period_id BIGINT UNSIGNED NULL,
 cost_line_id BIGINT UNSIGNED NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 proof_json LONGTEXT NOT NULL CHECK(JSON_VALID(proof_json)),
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_ntse_tenant(supplier_id,id),
 UNIQUE KEY uq_ntse_source(supplier_id,source_id,id),
 UNIQUE KEY uq_ntse_command(supplier_id,command_id),
 UNIQUE KEY uq_ntse_reverse(supplier_id,original_id),
 KEY ix_ntse_cost(supplier_id,cost_line_id,operation),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id) REFERENCES nonprofit_tax_saving_sources(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,command_id) REFERENCES nonprofit_tax_saving_commands(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id,previous_id) REFERENCES nonprofit_tax_saving_events(supplier_id,source_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,source_id,original_id) REFERENCES nonprofit_tax_saving_events(supplier_id,source_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_ntse_cost_line FOREIGN KEY(supplier_id,cost_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT chk_ntse_operation CHECK((operation='use' AND original_id IS NULL AND period_id IS NOT NULL AND cost_line_id IS NOT NULL AND amount_cents>0) OR (operation='reverse_use' AND original_id IS NOT NULL AND period_id IS NOT NULL AND cost_line_id IS NOT NULL AND amount_cents>0) OR (operation IN ('void','restore') AND original_id IS NULL AND period_id IS NULL AND cost_line_id IS NULL AND amount_cents=0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nlp_immutable_update BEFORE UPDATE ON nonprofit_legal_profiles FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nlp_immutable_delete BEFORE DELETE ON nonprofit_legal_profiles FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntss_immutable_update BEFORE UPDATE ON nonprofit_tax_saving_sources FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntss_immutable_delete BEFORE DELETE ON nonprofit_tax_saving_sources FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntsc_immutable_update BEFORE UPDATE ON nonprofit_tax_saving_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntsc_immutable_delete BEFORE DELETE ON nonprofit_tax_saving_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntse_immutable_update BEFORE UPDATE ON nonprofit_tax_saving_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_ntse_immutable_delete BEFORE DELETE ON nonprofit_tax_saving_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_dppo_immutable'; END$$
DELIMITER ;
