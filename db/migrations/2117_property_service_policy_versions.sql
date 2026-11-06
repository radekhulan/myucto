-- MyÚčto — profil účtování služby SVJ jako verzovaná účetní politika služba × období.
--
-- Rozvahový nebo výsledkový profil volí SVJ pro celou službu, ne každá jednotka zvlášť.
-- Verze platí od valid_from do začátku další verze téže služby. Existující případy se
-- převedou: služba, jejíž případy mají jediný profil, dostane verzi od nejstaršího období.
-- Služba s nesouladnými profily verzi nedostane; kontrola služeb v uzávěrce ji pak hlásí
-- jako neprokázanou politiku, dokud se nesoulad nevyřeší.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS property_service_policy_versions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 valid_from DATE NOT NULL,
 policy VARCHAR(40) NOT NULL,
 evidence VARCHAR(4000) NOT NULL,
 origin VARCHAR(20) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 recorded_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_service_policy_tenant (supplier_id, id),
 UNIQUE KEY uq_service_policy_version (supplier_id, service_id, valid_from),
 CONSTRAINT fk_service_policy_service FOREIGN KEY (supplier_id, service_id) REFERENCES property_objects (supplier_id, id) ON DELETE RESTRICT,
 CONSTRAINT fk_service_policy_actor FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
 CONSTRAINT chk_service_policy_value CHECK (policy IN ('service_balance_sheet', 'service_profit_and_loss')),
 CONSTRAINT chk_service_policy_origin CHECK (origin IN ('declared', 'first_case', 'migrated'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE TRIGGER property_service_policy_versions_update BEFORE UPDATE ON property_service_policy_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service policy version';
CREATE OR REPLACE TRIGGER property_service_policy_versions_delete BEFORE DELETE ON property_service_policy_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable service policy version';

INSERT IGNORE INTO property_service_policy_versions (supplier_id, service_id, valid_from, policy, evidence, origin, created_by, recorded_at)
SELECT c.supplier_id, c.service_id, MIN(c.period_from), MIN(c.policy),
       'Převzato z dosavadních případů služby se shodným profilem', 'migrated', MIN(c.created_by), UTC_TIMESTAMP(6)
FROM property_service_lifecycle_cases c
WHERE NOT EXISTS (
  SELECT 1 FROM property_service_policy_versions v
  WHERE v.supplier_id = c.supplier_id AND v.service_id = c.service_id
)
GROUP BY c.supplier_id, c.service_id
HAVING COUNT(DISTINCT c.policy) = 1;
