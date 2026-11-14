-- Opravitelnost členské evidence, dokud na záznamu nestojí účetní zápis.
-- Zrušení revize je další článek řetězu (voids_revision_id); revize samotné zůstávají neměnné.
-- Jedno platné datum účinnosti hlídá služba pod zámkem člena a ověření historie při každém čtení,
-- protože zrušená revize datum dál drží a opravená revize ho smí použít znovu.
ALTER TABLE nonprofit_member_revisions
 ADD COLUMN IF NOT EXISTS voids_revision_id BIGINT UNSIGNED NULL AFTER previous_id,
 ADD UNIQUE INDEX IF NOT EXISTS uq_nmr_voids (supplier_id,member_id,voids_revision_id),
 ADD CONSTRAINT fk_nmr_voids FOREIGN KEY IF NOT EXISTS (supplier_id,member_id,voids_revision_id)
  REFERENCES nonprofit_member_revisions(supplier_id,member_id,id) ON DELETE RESTRICT,
 DROP INDEX IF EXISTS uq_nmr_effective;

-- Smazání člena zamyká jen plněné dávky organizace (souběh s plněním dávky).
ALTER TABLE nonprofit_membership_batches
 ADD INDEX IF NOT EXISTS ix_nmb_phase (supplier_id,phase,id);

-- Člen jde opravit (kontakt, členské číslo) jen do prvního předpisu a smazat jen bez navázaných dat (FK RESTRICT).
DELIMITER $$
DROP TRIGGER IF EXISTS trg_nm_immutable_update$$
CREATE TRIGGER trg_nm_immutable_update BEFORE UPDATE ON nonprofit_members FOR EACH ROW
BEGIN
 IF NOT (NEW.id<=>OLD.id) OR NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.created_by<=>OLD.created_by)
    OR NOT (NEW.created_at<=>OLD.created_at) OR NOT (NEW.recorded_at_utc<=>OLD.recorded_at_utc)
    OR EXISTS(SELECT 1 FROM nonprofit_membership_charge_cases c WHERE c.supplier_id=OLD.supplier_id AND c.member_id=OLD.id)
    OR EXISTS(SELECT 1 FROM nonprofit_membership_charge_claims c WHERE c.supplier_id=OLD.supplier_id AND c.member_id=OLD.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
END$$
DROP TRIGGER IF EXISTS trg_nm_immutable_delete$$
DELIMITER ;
