-- MyÚčto — evidence námitek proti vyúčtování služeb (§ 8 zákona č. 67/2013 Sb.).
--
-- Námitka se zapisuje s datem doručení a obsahem, vyřízení se doplní jednou s datem
-- a výsledkem. Lhůty (doručení vyúčtování, splatnost vyrovnání, podání a vyřízení
-- námitek) se z nich a z doručení v dokumentaci vyúčtování počítají jako upozornění.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS property_service_settlement_objections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 recipient_id INT UNSIGNED NOT NULL,
 received_on DATE NOT NULL,
 content VARCHAR(4000) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 resolved_on DATE NULL,
 resolution VARCHAR(4000) NULL,
 resolved_by BIGINT UNSIGNED NULL,
 resolved_recorded_at DATETIME(6) NULL,
 UNIQUE KEY uq_service_objection_tenant (supplier_id, id),
 KEY ix_service_objection_document (supplier_id, document_id, received_on),
 CONSTRAINT fk_service_objection_document FOREIGN KEY (supplier_id, document_id) REFERENCES property_service_settlements (supplier_id, id) ON DELETE RESTRICT,
 CONSTRAINT fk_service_objection_actor FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
 CONSTRAINT fk_service_objection_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE RESTRICT,
 CONSTRAINT chk_service_objection_resolution CHECK (
  (resolved_on IS NULL AND resolution IS NULL AND resolved_by IS NULL AND resolved_recorded_at IS NULL)
  OR (resolved_on IS NOT NULL AND resolution IS NOT NULL AND resolved_by IS NOT NULL AND resolved_recorded_at IS NOT NULL AND resolved_on >= received_on)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE OR REPLACE TRIGGER property_service_settlement_objections_update BEFORE UPDATE ON property_service_settlement_objections FOR EACH ROW
BEGIN
 IF OLD.resolved_on IS NOT NULL OR NEW.supplier_id <> OLD.supplier_id OR NEW.document_id <> OLD.document_id
    OR NEW.recipient_id <> OLD.recipient_id OR NEW.received_on <> OLD.received_on OR NEW.content <> OLD.content
    OR NEW.created_by <> OLD.created_by OR NEW.recorded_at <> OLD.recorded_at THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service settlement objection is immutable once resolved';
 END IF;
END//
CREATE OR REPLACE TRIGGER property_service_settlement_objections_delete BEFORE DELETE ON property_service_settlement_objections FOR EACH ROW
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service settlement objection history is immutable'//
DELIMITER ;
