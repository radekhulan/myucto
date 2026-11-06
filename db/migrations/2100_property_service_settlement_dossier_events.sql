CREATE TABLE IF NOT EXISTS property_service_settlement_dossier_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 revision_no INT UNSIGNED NOT NULL,
 previous_id BIGINT UNSIGNED NULL,
 request_json LONGTEXT NOT NULL CHECK (JSON_VALID(request_json)),
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json LONGTEXT NOT NULL CHECK (JSON_VALID(snapshot_json)),
 snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_settlement_dossier_tenant (supplier_id,id),
 UNIQUE KEY uq_settlement_dossier_parent (supplier_id,document_id,id),
 UNIQUE KEY uq_settlement_dossier_revision (supplier_id,document_id,revision_no),
 UNIQUE KEY uq_settlement_dossier_token (supplier_id,idempotency_key),
 CONSTRAINT fk_settlement_dossier_document FOREIGN KEY (supplier_id,document_id) REFERENCES property_service_settlements(supplier_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_settlement_dossier_previous FOREIGN KEY (supplier_id,document_id,previous_id) REFERENCES property_service_settlement_dossier_events(supplier_id,document_id,id) ON DELETE RESTRICT,
 CONSTRAINT fk_settlement_dossier_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT chk_settlement_dossier_revision CHECK (revision_no>0 AND ((revision_no=1 AND previous_id IS NULL) OR (revision_no>1 AND previous_id IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE TRIGGER settlement_dossier_events_immutable_update BEFORE UPDATE ON property_service_settlement_dossier_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Settlement dossier events are immutable'$$
CREATE OR REPLACE TRIGGER settlement_dossier_events_immutable_delete BEFORE DELETE ON property_service_settlement_dossier_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Settlement dossier events are immutable'$$
DELIMITER ;
