SET NAMES utf8mb4;

-- Akceptace nativního výpočtu DPPO 504 firmou. Výchozí stav je bez akceptace:
-- finalizace, XML i podání zůstávají blokované, dokud správce firmy výslovně
-- nepotvrdí ověření výpočtu pro konkrétní verzi pravidel a formuláře.
CREATE TABLE IF NOT EXISTS nonprofit_dppo_acceptances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    previous_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ruleset VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    form_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    form_version VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    xsd_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verification_reference VARCHAR(500) NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT UTC_TIMESTAMP(6),
    UNIQUE KEY uq_nda_revision (supplier_id, revision_no),
    UNIQUE KEY uq_nda_idempotency (supplier_id, idempotency_key),
    CONSTRAINT chk_nda_status CHECK (status IN ('accepted', 'revoked')),
    CONSTRAINT chk_nda_revision CHECK (revision_no > 0),
    CONSTRAINT fk_nda_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_nda_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_nda_immutable_update;
DROP TRIGGER IF EXISTS trg_nda_immutable_delete;

DELIMITER $$
CREATE TRIGGER trg_nda_immutable_update BEFORE UPDATE ON nonprofit_dppo_acceptances FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_dppo_acceptance_immutable';
END$$
CREATE TRIGGER trg_nda_immutable_delete BEFORE DELETE ON nonprofit_dppo_acceptances FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nonprofit_dppo_acceptance_immutable';
END$$
DELIMITER ;
