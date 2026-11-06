-- MyÚčto — návrhy úhrad jednotek SVJ z bankovních pohybů (fronta „k potvrzení").
--
-- Po importu výpisu firmy s profilem SVJ rozpozná UnitPaymentResolver jednotku podle
-- identifikátorů registru účinných k datu platby (VS, SS, účet, SIPO, text) a uloží
-- návrh se skóre a důvody. Návrh nic neúčtuje: potvrzuje se stejným náhledem a
-- tokenem jako ruční úhrada. Jeden pohyb má nejvýš jeden návrh; opakovaný import
-- návrh jen přepočítá.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS unit_payment_bank_proposals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 bank_transaction_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL,
 unit_id BIGINT UNSIGNED NULL,
 score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 resolver_version SMALLINT UNSIGNED NOT NULL,
 resolution_json LONGTEXT NOT NULL CHECK (JSON_VALID(resolution_json)),
 resolution_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_unit_bank_proposal_tenant (supplier_id, id),
 UNIQUE KEY uq_unit_bank_proposal_transaction (bank_transaction_id),
 KEY ix_unit_bank_proposal_queue (supplier_id, status, bank_transaction_id),
 KEY ix_unit_bank_proposal_unit (supplier_id, unit_id),
 CONSTRAINT chk_unit_bank_proposal_status CHECK (status IN ('identified', 'review_required', 'unidentified')),
 CONSTRAINT fk_unit_bank_proposal_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
 CONSTRAINT fk_unit_bank_proposal_transaction FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE CASCADE,
 CONSTRAINT fk_unit_bank_proposal_unit FOREIGN KEY (supplier_id, unit_id) REFERENCES property_objects(supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Žádost o zrušení odeslaného příkazu k vratce a doložené neprovedení se čtou z auditu
-- rezervace po stránkách; bez indexu by dotaz procházel celý audit plateb firmy.
ALTER TABLE unit_payment_audit ADD INDEX IF NOT EXISTS ix_unit_payment_audit_object (supplier_id, object_kind, object_id, action);
