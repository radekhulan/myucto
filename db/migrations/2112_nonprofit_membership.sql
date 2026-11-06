SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries MODIFY source_type ENUM('invoice','purchase_invoice','bank','cash','asset','manual','closing','opening','depreciation','asset_disposal','fx_revaluation','stock','provision','income_tax','profit_distribution','offset','small_asset_accrual','prepaid_expense_accrual','settlement','deferred_tax','payroll','vat_clearing','payroll_payment','gopay','card_settlement','card_writeoff','other_item','payroll_accident_insurance','payroll_travel','deferred_revenue_accrual','unit_charge','unit_payment','unit_payment_revision','property_service_settlement','property_service_lifecycle','property_funding','nonprofit_capital','nonprofit_membership') NOT NULL DEFAULT 'manual';

CREATE TABLE IF NOT EXISTS nonprofit_members (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 member_number VARCHAR(80) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nm_tenant(supplier_id,id),
 UNIQUE KEY uq_nm_client(supplier_id,client_id),
 UNIQUE KEY uq_nm_number(supplier_id,member_number),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_member_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 member_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 effective_from DATE NOT NULL,
 status ENUM('active','suspended','ended') NOT NULL,
 legal_basis_json LONGTEXT NOT NULL CHECK(JSON_VALID(legal_basis_json)),
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmr_tenant(supplier_id,id),
 UNIQUE KEY uq_nmr_chain(supplier_id,member_id,id),
 UNIQUE KEY uq_nmr_revision(supplier_id,member_id,revision_no),
 UNIQUE KEY uq_nmr_effective(supplier_id,member_id,effective_from),
 UNIQUE KEY uq_nmr_key(supplier_id,idempotency_key),
 KEY ix_nmr_effective_lookup(supplier_id,member_id,effective_from,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,member_id) REFERENCES nonprofit_members(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,member_id,previous_id) REFERENCES nonprofit_member_revisions(supplier_id,member_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(revision_no>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_membership_charge_cases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 member_id BIGINT UNSIGNED NOT NULL,
 title_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 period_from DATE NOT NULL,
 period_to DATE NOT NULL,
 head_operation_id BIGINT UNSIGNED NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmcc_tenant(supplier_id,id),
 UNIQUE KEY uq_nmcc_identity(supplier_id,member_id,title_key,period_from,period_to),
 KEY ix_nmcc_overlap(supplier_id,member_id,title_key,period_from,period_to,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,member_id) REFERENCES nonprofit_members(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(period_from<=period_to),
 CHECK(row_version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_membership_charge_commands (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_json LONGTEXT NOT NULL CHECK(JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmcmd_tenant(supplier_id,id),
 UNIQUE KEY uq_nmcmd_key(supplier_id,idempotency_key),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonprofit_membership_charge_operations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 command_id BIGINT UNSIGNED NOT NULL,
 operation ENUM('issue','reverse') NOT NULL,
 previous_operation_id BIGINT UNSIGNED NULL,
 original_operation_id BIGINT UNSIGNED NULL,
 member_revision_id BIGINT UNSIGNED NOT NULL,
 legal_profile_id BIGINT UNSIGNED NOT NULL,
 accounting_period_id BIGINT UNSIGNED NOT NULL,
 accounting_date DATE NOT NULL,
 due_date DATE NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 receivable_account_id BIGINT UNSIGNED NOT NULL,
 revenue_account_id BIGINT UNSIGNED NOT NULL,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 receivable_line_id BIGINT UNSIGNED NOT NULL,
 revenue_line_id BIGINT UNSIGNED NOT NULL,
 preview_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 proof_json LONGTEXT NOT NULL CHECK(JSON_VALID(proof_json)),
 receipt_json LONGTEXT NOT NULL CHECK(JSON_VALID(receipt_json)),
 parent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmop_tenant(supplier_id,id),
 UNIQUE KEY uq_nmop_case(supplier_id,case_id,id),
 UNIQUE KEY uq_nmop_command(supplier_id,command_id),
 UNIQUE KEY uq_nmop_journal(supplier_id,journal_entry_id),
 UNIQUE KEY uq_nmop_receivable_line(supplier_id,receivable_line_id),
 UNIQUE KEY uq_nmop_revenue_line(supplier_id,revenue_line_id),
 UNIQUE KEY uq_nmop_reverse(supplier_id,original_operation_id),
 KEY ix_nmop_period(supplier_id,accounting_period_id,operation,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,case_id) REFERENCES nonprofit_membership_charge_cases(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,command_id) REFERENCES nonprofit_membership_charge_commands(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,case_id,previous_operation_id) REFERENCES nonprofit_membership_charge_operations(supplier_id,case_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,case_id,original_operation_id) REFERENCES nonprofit_membership_charge_operations(supplier_id,case_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,member_revision_id) REFERENCES nonprofit_member_revisions(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,legal_profile_id) REFERENCES nonprofit_legal_profiles(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,accounting_period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,receivable_account_id) REFERENCES chart_of_accounts(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,revenue_account_id) REFERENCES chart_of_accounts(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,journal_entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,receivable_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,revenue_line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(amount_cents>0),
 CHECK((operation='issue' AND original_operation_id IS NULL) OR (operation='reverse' AND original_operation_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE nonprofit_membership_charge_cases DROP FOREIGN KEY IF EXISTS fk_nmcc_head;
ALTER TABLE nonprofit_membership_charge_cases ADD CONSTRAINT fk_nmcc_head FOREIGN KEY(supplier_id,id,head_operation_id) REFERENCES nonprofit_membership_charge_operations(supplier_id,case_id,id) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS nonprofit_membership_charge_claims (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 case_id BIGINT UNSIGNED NOT NULL,
 operation_id BIGINT UNSIGNED NOT NULL,
 original_claim_id BIGINT UNSIGNED NULL,
 member_id BIGINT UNSIGNED NOT NULL,
 client_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('issue','reversal') NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 record_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_nmclaim_tenant(supplier_id,id),
 UNIQUE KEY uq_nmclaim_operation(supplier_id,operation_id),
 UNIQUE KEY uq_nmclaim_reverse(supplier_id,original_claim_id),
 KEY ix_nmclaim_member(supplier_id,member_id,id),
 KEY ix_nmclaim_client(supplier_id,client_id,id),
 FOREIGN KEY(supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,case_id) REFERENCES nonprofit_membership_charge_cases(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,operation_id) REFERENCES nonprofit_membership_charge_operations(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,original_claim_id) REFERENCES nonprofit_membership_charge_claims(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,member_id) REFERENCES nonprofit_members(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,client_id) REFERENCES clients(supplier_id,id) ON DELETE RESTRICT,
 CHECK(amount_cents>0),
 CHECK((kind='issue' AND original_claim_id IS NULL) OR (kind='reversal' AND original_claim_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE TRIGGER trg_nm_immutable_update BEFORE UPDATE ON nonprofit_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nm_immutable_delete BEFORE DELETE ON nonprofit_members FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmr_immutable_update BEFORE UPDATE ON nonprofit_member_revisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmr_immutable_delete BEFORE DELETE ON nonprofit_member_revisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmcmd_immutable_update BEFORE UPDATE ON nonprofit_membership_charge_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmcmd_immutable_delete BEFORE DELETE ON nonprofit_membership_charge_commands FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmop_immutable_update BEFORE UPDATE ON nonprofit_membership_charge_operations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmop_immutable_delete BEFORE DELETE ON nonprofit_membership_charge_operations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmclaim_immutable_update BEFORE UPDATE ON nonprofit_membership_charge_claims FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmclaim_immutable_delete BEFORE DELETE ON nonprofit_membership_charge_claims FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
CREATE OR REPLACE TRIGGER trg_nmcc_guard_update BEFORE UPDATE ON nonprofit_membership_charge_cases FOR EACH ROW
BEGIN
 IF NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.member_id<=>OLD.member_id) OR NOT (NEW.title_key<=>OLD.title_key)
    OR NOT (NEW.period_from<=>OLD.period_from) OR NOT (NEW.period_to<=>OLD.period_to) OR NOT (NEW.created_by<=>OLD.created_by)
    OR NOT (NEW.created_at<=>OLD.created_at) OR NEW.row_version<>OLD.row_version+1 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_case_immutable';
 END IF;
END$$
CREATE OR REPLACE TRIGGER trg_nmcc_immutable_delete BEFORE DELETE ON nonprofit_membership_charge_cases FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable'; END$$
DELIMITER ;
