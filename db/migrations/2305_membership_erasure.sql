-- Výmaz osobních údajů bývalých členů spolku (#321, krok 10).
--
-- Vzor je výmaz ve mzdách (1397, 1639): po uplynutí lhůt se sestaví NÁVRH, člověk ho schválí
-- a teprve pak se provede. Návrh a jeho položky zůstávají jako doklad o výmazu, bez jména,
-- čísla i kontaktu. Položka proto nemá cizí klíč na člena (vazba je jen číslo, stejně jako
-- u mezd) a smazaný člen (krok 7) v ní může mít jen svou auditní stopu.
--
-- Účetní záznamy zůstávají. Osobní údaje člena jsou ale uvnitř hashovaných polí revizí,
-- operací a příkazů a na jejich hashe odkazují uložené podklady DPPO. Řetěz se proto
-- NEPŘEPOČÍTÁVÁ: uložené record_hash / parent_hash / request_hash zůstanou a řádek dostane
-- pečeť výmazu (erasure_item_id + erased_digest). Ověřovače přijmou změněný obsah jen
-- s platnou pečetí (MembershipErasureSeal); jakákoli jiná změna dál končí 409.
-- Triggery dovolí jedinou změnu osobních cest JSONu, a to jen s pečetí k prováděné položce
-- schváleného návrhu tohoto člena; zbytek obsahu se musí shodovat. Auditní stopu oprav
-- redaguje ActivityLogHashChain::redact() nad tabulkou z migrace 2302.
--
-- Idempotence: CREATE TABLE / ADD COLUMN / ADD INDEX / FOREIGN KEY IF NOT EXISTS,
-- triggery DROP TRIGGER IF EXISTS + CREATE, práva INSERT IGNORE.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_membership_erasure_proposals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 status ENUM('pending','approved','rejected','executed') NOT NULL DEFAULT 'pending',
 as_of DATE NOT NULL COMMENT 'den, ke kterému se posuzovalo uplynutí lhůt',
 note VARCHAR(500) NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 executable_from DATETIME NULL COMMENT 'konec odkladné lhůty, když schvaluje tentýž člověk, který navrhl',
 revoked_by BIGINT UNSIGNED NULL,
 revoked_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL,
 rejected_at DATETIME NULL,
 executed_by BIGINT UNSIGNED NULL,
 executed_at DATETIME NULL,
 UNIQUE KEY uq_nmep_tenant (supplier_id,id),
 KEY ix_nmep_status (supplier_id,status,id),
 CONSTRAINT fk_nmep_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE RESTRICT,
 CONSTRAINT fk_nmep_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
 CONSTRAINT fk_nmep_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
 CONSTRAINT fk_nmep_revoker FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL,
 CONSTRAINT fk_nmep_rejector FOREIGN KEY (rejected_by) REFERENCES users (id) ON DELETE SET NULL,
 CONSTRAINT fk_nmep_executor FOREIGN KEY (executed_by) REFERENCES users (id) ON DELETE SET NULL,
 CHECK (row_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Položka návrhu = doklad o výmazu jednoho člena. Žádné jméno, číslo ani kontakt; jen pravidlo,
-- rozhodná data a počty. Důvod přeskočení je kód (překládá ho UI), ne text.
CREATE TABLE IF NOT EXISTS nonprofit_membership_erasure_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 proposal_id BIGINT UNSIGNED NOT NULL,
 member_id BIGINT UNSIGNED NOT NULL COMMENT 'nonprofit_members.id — ZÁMĚRNĚ bez FK, doklad přežije i smazaného člena',
 subject ENUM('member','deleted_member') NOT NULL COMMENT 'deleted_member = jen auditní stopa člena smazaného v kroku 7',
 governing_rule ENUM('accounting_records','membership_only') NOT NULL COMMENT 'lhůta, která rozhodla (delší z obou)',
 ended_on DATE NOT NULL COMMENT 'ukončení členství, u smazaného člena den smazání',
 last_record_period_end DATE NULL COMMENT 'konec účetního období posledního účetního záznamu člena',
 retained_until DATE NOT NULL COMMENT 'poslední den povinnosti uchovávat',
 outcome ENUM('pending','done','skipped_changed','skipped_hold') NOT NULL DEFAULT 'pending',
 skip_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 counts_json LONGTEXT NULL CHECK (counts_json IS NULL OR JSON_VALID(counts_json)),
 executed_at DATETIME NULL,
 UNIQUE KEY uq_nmei_tenant (supplier_id,id),
 UNIQUE KEY uq_nmei_member (supplier_id,proposal_id,member_id),
 KEY ix_nmei_member (supplier_id,member_id,outcome),
 CONSTRAINT fk_nmei_proposal FOREIGN KEY (supplier_id,proposal_id)
  REFERENCES nonprofit_membership_erasure_proposals (supplier_id,id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pečeť výmazu na hashovaných záznamech člena.
ALTER TABLE nonprofit_member_revisions
 ADD COLUMN IF NOT EXISTS erasure_item_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS erased_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD INDEX IF NOT EXISTS ix_nmr_erasure (supplier_id,erasure_item_id),
 ADD CONSTRAINT fk_nmr_erasure FOREIGN KEY IF NOT EXISTS (supplier_id,erasure_item_id)
  REFERENCES nonprofit_membership_erasure_items (supplier_id,id) ON DELETE RESTRICT;

ALTER TABLE nonprofit_membership_charge_operations
 ADD COLUMN IF NOT EXISTS erasure_item_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS erased_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD INDEX IF NOT EXISTS ix_nmop_erasure (supplier_id,erasure_item_id),
 ADD CONSTRAINT fk_nmop_erasure FOREIGN KEY IF NOT EXISTS (supplier_id,erasure_item_id)
  REFERENCES nonprofit_membership_erasure_items (supplier_id,id) ON DELETE RESTRICT;

ALTER TABLE nonprofit_membership_charge_commands
 ADD COLUMN IF NOT EXISTS erasure_item_id BIGINT UNSIGNED NULL,
 ADD COLUMN IF NOT EXISTS erased_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD INDEX IF NOT EXISTS ix_nmcmd_erasure (supplier_id,erasure_item_id),
 ADD CONSTRAINT fk_nmcmd_erasure FOREIGN KEY IF NOT EXISTS (supplier_id,erasure_item_id)
  REFERENCES nonprofit_membership_erasure_items (supplier_id,id) ON DELETE RESTRICT;

DELIMITER $$
-- Člen: oprava kontaktu a čísla do prvního předpisu (krok 7); po výmazu jediná změna čísla na pseudonym.
DROP TRIGGER IF EXISTS trg_nm_immutable_update$$
CREATE TRIGGER trg_nm_immutable_update BEFORE UPDATE ON nonprofit_members FOR EACH ROW
BEGIN
 IF NOT (NEW.id<=>OLD.id) OR NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.created_by<=>OLD.created_by)
    OR NOT (NEW.created_at<=>OLD.created_at) OR NOT (NEW.recorded_at_utc<=>OLD.recorded_at_utc) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
 IF EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i WHERE i.supplier_id=OLD.supplier_id AND i.member_id=OLD.id AND i.subject='member' AND i.outcome='done') THEN
  IF NOT (NEW.client_id<=>OLD.client_id) OR CAST(OLD.member_number AS BINARY)=CONCAT('vymazano-',OLD.id) OR CAST(NEW.member_number AS BINARY)<>CONCAT('vymazano-',OLD.id)
     OR NOT EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id AND p.status='approved'
       WHERE i.supplier_id=OLD.supplier_id AND i.member_id=OLD.id AND i.subject='member' AND i.outcome='done') THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
  END IF;
 ELSEIF EXISTS(SELECT 1 FROM nonprofit_membership_charge_cases c WHERE c.supplier_id=OLD.supplier_id AND c.member_id=OLD.id)
    OR EXISTS(SELECT 1 FROM nonprofit_membership_charge_claims c WHERE c.supplier_id=OLD.supplier_id AND c.member_id=OLD.id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
END$$

-- Revize: mění se jen osobní texty v legal_basis_json a request_json (odkaz, doložení, důvod), a to jednou,
-- s pečetí k prováděné položce schváleného návrhu tohoto člena. Zbytek JSONu se musí shodovat.
DROP TRIGGER IF EXISTS trg_nmr_immutable_update$$
CREATE TRIGGER trg_nmr_immutable_update BEFORE UPDATE ON nonprofit_member_revisions FOR EACH ROW
BEGIN
 IF OLD.erasure_item_id IS NOT NULL OR NEW.erasure_item_id IS NULL OR NEW.erased_digest IS NULL
    OR NOT (NEW.id<=>OLD.id) OR NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.member_id<=>OLD.member_id)
    OR NOT (NEW.revision_no<=>OLD.revision_no) OR NOT (NEW.previous_id<=>OLD.previous_id) OR NOT (NEW.voids_revision_id<=>OLD.voids_revision_id)
    OR NOT (NEW.effective_from<=>OLD.effective_from) OR NOT (NEW.status<=>OLD.status) OR NOT (NEW.idempotency_key<=>OLD.idempotency_key)
    OR NOT (NEW.request_hash<=>OLD.request_hash) OR NOT (NEW.parent_hash<=>OLD.parent_hash) OR NOT (NEW.record_hash<=>OLD.record_hash)
    OR NOT (NEW.created_by<=>OLD.created_by) OR NOT (NEW.created_at<=>OLD.created_at) OR NOT (NEW.recorded_at_utc<=>OLD.recorded_at_utc)
    OR NOT EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id AND p.status='approved'
      WHERE i.supplier_id=OLD.supplier_id AND i.id=NEW.erasure_item_id AND i.member_id=OLD.member_id AND i.subject='member' AND i.outcome='pending')
    OR NOT JSON_EQUALS(JSON_SET(OLD.legal_basis_json,'$.reference','','$.evidence',''), JSON_SET(NEW.legal_basis_json,'$.reference','','$.evidence',''))
    OR NOT JSON_EQUALS(JSON_SET(OLD.request_json,'$.reason','','$.legal_basis.reference','','$.legal_basis.evidence',''),
      JSON_SET(NEW.request_json,'$.reason','','$.legal_basis.reference','','$.legal_basis.evidence','')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
END$$

-- Operace: v proof_json se mění jen číslo člena, osobní texty podkladů revizí a titulu a důvod storna,
-- s pečetí k prováděné položce schváleného návrhu člena případu. Zbytek důkazu se musí shodovat.
DROP TRIGGER IF EXISTS trg_nmop_immutable_update$$
CREATE TRIGGER trg_nmop_immutable_update BEFORE UPDATE ON nonprofit_membership_charge_operations FOR EACH ROW
BEGIN
 DECLARE k INT DEFAULT 0;
 DECLARE n INT DEFAULT COALESCE(JSON_LENGTH(OLD.proof_json,'$.membership_intervals'),0);
 DECLARE old_mask LONGTEXT DEFAULT JSON_SET(OLD.proof_json,'$.member.member_number','','$.member_revision.legal_basis.reference','',
  '$.member_revision.legal_basis.evidence','','$.legal_title.reference','','$.legal_title.evidence','','$.reversal_reason','');
 DECLARE new_mask LONGTEXT DEFAULT JSON_SET(NEW.proof_json,'$.member.member_number','','$.member_revision.legal_basis.reference','',
  '$.member_revision.legal_basis.evidence','','$.legal_title.reference','','$.legal_title.evidence','','$.reversal_reason','');
 WHILE k < n DO
  SET old_mask=JSON_SET(old_mask,CONCAT('$.membership_intervals[',k,'].legal_basis.reference'),'',CONCAT('$.membership_intervals[',k,'].legal_basis.evidence'),'');
  SET new_mask=JSON_SET(new_mask,CONCAT('$.membership_intervals[',k,'].legal_basis.reference'),'',CONCAT('$.membership_intervals[',k,'].legal_basis.evidence'),'');
  SET k=k+1;
 END WHILE;
 IF OLD.erasure_item_id IS NOT NULL OR NEW.erasure_item_id IS NULL OR NEW.erased_digest IS NULL
    OR NOT (NEW.id<=>OLD.id) OR NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.case_id<=>OLD.case_id)
    OR NOT (NEW.command_id<=>OLD.command_id) OR NOT (NEW.operation<=>OLD.operation)
    OR NOT (NEW.previous_operation_id<=>OLD.previous_operation_id) OR NOT (NEW.original_operation_id<=>OLD.original_operation_id)
    OR NOT (NEW.member_revision_id<=>OLD.member_revision_id) OR NOT (NEW.legal_profile_id<=>OLD.legal_profile_id)
    OR NOT (NEW.accounting_period_id<=>OLD.accounting_period_id) OR NOT (NEW.accounting_date<=>OLD.accounting_date)
    OR NOT (NEW.due_date<=>OLD.due_date) OR NOT (NEW.amount_cents<=>OLD.amount_cents)
    OR NOT (NEW.receivable_account_id<=>OLD.receivable_account_id) OR NOT (NEW.revenue_account_id<=>OLD.revenue_account_id)
    OR NOT (NEW.journal_entry_id<=>OLD.journal_entry_id) OR NOT (NEW.receivable_line_id<=>OLD.receivable_line_id)
    OR NOT (NEW.revenue_line_id<=>OLD.revenue_line_id) OR NOT (NEW.preview_hash<=>OLD.preview_hash)
    OR NOT (NEW.receipt_json<=>OLD.receipt_json) OR NOT (NEW.parent_hash<=>OLD.parent_hash) OR NOT (NEW.record_hash<=>OLD.record_hash)
    OR NOT (NEW.created_by<=>OLD.created_by) OR NOT (NEW.created_at<=>OLD.created_at)
    OR NOT EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id AND p.status='approved'
      JOIN nonprofit_membership_charge_cases c ON c.supplier_id=i.supplier_id AND c.member_id=i.member_id
      WHERE i.supplier_id=OLD.supplier_id AND i.id=NEW.erasure_item_id AND c.id=OLD.case_id AND i.subject='member' AND i.outcome='pending')
    OR COALESCE(JSON_LENGTH(NEW.proof_json,'$.membership_intervals'),0)<>n OR NOT JSON_EQUALS(old_mask,new_mask) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
END$$

-- Příkaz: v request_json se mění jen důvod, VS a podklad titulu, s pečetí k prováděné položce schváleného
-- návrhu člena, jehož operaci příkaz vytvořil. Zbytek požadavku se musí shodovat.
DROP TRIGGER IF EXISTS trg_nmcmd_immutable_update$$
CREATE TRIGGER trg_nmcmd_immutable_update BEFORE UPDATE ON nonprofit_membership_charge_commands FOR EACH ROW
BEGIN
 IF OLD.erasure_item_id IS NOT NULL OR NEW.erasure_item_id IS NULL OR NEW.erased_digest IS NULL
    OR NOT (NEW.id<=>OLD.id) OR NOT (NEW.supplier_id<=>OLD.supplier_id) OR NOT (NEW.idempotency_key<=>OLD.idempotency_key)
    OR NOT (NEW.request_hash<=>OLD.request_hash) OR NOT (NEW.created_by<=>OLD.created_by) OR NOT (NEW.rate_id<=>OLD.rate_id)
    OR NOT (NEW.created_at<=>OLD.created_at)
    OR NOT EXISTS(SELECT 1 FROM nonprofit_membership_erasure_items i
      JOIN nonprofit_membership_charge_cases c ON c.supplier_id=i.supplier_id AND c.member_id=i.member_id
      JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.case_id=c.id
      JOIN nonprofit_membership_erasure_proposals p ON p.supplier_id=i.supplier_id AND p.id=i.proposal_id AND p.status='approved'
      WHERE i.supplier_id=OLD.supplier_id AND i.id=NEW.erasure_item_id AND o.command_id=OLD.id AND i.subject='member' AND i.outcome='pending')
    OR NOT JSON_EQUALS(JSON_SET(OLD.request_json,'$.request.reason','','$.request.variable_symbol','','$.request.legal_title.reference','','$.request.legal_title.evidence',''),
      JSON_SET(NEW.request_json,'$.request.reason','','$.request.variable_symbol','','$.request.legal_title.reference','','$.request.legal_title.evidence','')) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='nonprofit_membership_immutable';
 END IF;
END$$
DELIMITER ;
