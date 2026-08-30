-- MyÚčto.cz — zabezpečený odkaz na mzdový dokument pro zaměstnance.
--
-- PROČ ODKAZ A NE PŘÍLOHA
-- -----------------------
-- Výplatní páska je zvlášť citlivý osobní údaj. E-mailová příloha je jednorázově
-- nevratná: leží v cizí schránce, v zálohách poštovního serveru, v archivu
-- zaměstnavatele i v každé kopii, kterou si příjemce udělal. Když se ukáže, že
-- šla na špatnou adresu, není co odvolat. Odkaz naopak umožňuje expiraci,
-- zneplatnění a hlavně doložitelné převzetí — evidence pak neříká jen
-- „odesláno", ale „tento člověk si to tehdy vyzvedl".
--
-- PROČ LOKÁTOR NENÍ JEDNORÁZOVÝ, ALE KÓD ANO
-- ------------------------------------------
-- Firemní poštovní bezpečnostní brány (Microsoft Defender Safe Links, Proofpoint
-- URL Defense a spol.) VOLAJÍ GET na každý odkaz v příchozím e-mailu dřív, než
-- ho člověk uvidí. Kdyby byl lokátor v URL spotřebovaný prvním GETem, spálil by
-- ho skener a zaměstnanec by se k pásce už nikdy nedostal. Proto:
--   * token v URL je jen ADRESA — sám o sobě neukáže nic a jeho GET je bez
--     vedlejších účinků,
--   * skutečná pověřovací hodnota je jednorázový číselný kód, který si zaměstnanec
--     nechá poslat POSTem na svou (a jen svou) známou adresu.
-- Tím se z uniklé URL — přeposlaný e-mail, historie prohlížeče na sdíleném PC,
-- firemní mailový archiv, log proxy — stane bezcenný řetězec: k zobrazení je
-- navíc potřeba ŽIVÝ přístup do schránky v okamžiku čtení.
--
-- CO SE TU VĚDOMĚ NEUKLÁDÁ
-- ------------------------
-- Žádný plaintext: token ani kód ani session token, jen jejich sha256. A žádná
-- e-mailová adresa: jen její keyed lookup hash (stejný, jaký nese
-- `payroll_person_contacts.contact_value_hash`) a maskovaná podoba pro zobrazení.
-- Worker si plaintext vyzvedne až v okamžiku odeslání a POROVNÁ ho s hashem —
-- když se adresa mezitím změnila, odeslání selže místo toho, aby pásku poslalo
-- jinam. Přesměrování výplatnice tichou změnou kontaktu tak není cesta.
--
-- Tabulka odkazů slouží zároveň jako fronta rozesílky (`dispatch_state`,
-- `lease_token`), aby stav odkazu a stav odeslání nemohly divergovat — vzor je
-- `payroll_annual_document_batch_items` z migrace 1652.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_document_access_links (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id            INT UNSIGNED NOT NULL,
  payroll_document_id    BIGINT UNSIGNED NOT NULL,
  employee_id            BIGINT UNSIGNED NOT NULL,
  token_hash             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
                         COMMENT 'sha256 lokátoru z URL; NULL dokud odkaz čeká ve frontě',
  recipient_email_hash   BINARY(32) NOT NULL
                         COMMENT 'keyed lookup hash adresy — shodný s payroll_person_contacts.contact_value_hash',
  recipient_masked       VARCHAR(191) NOT NULL
                         COMMENT 'maskovaná adresa pro zobrazení účetní i zaměstnanci',
  dispatch_state         ENUM('pending','sending','sent','failed','cancelled')
                         NOT NULL DEFAULT 'pending',
  attempt_count          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at        DATETIME NULL,
  lease_token            CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  leased_until           DATETIME NULL,
  last_error_code        VARCHAR(64) NULL,
  idempotency_key        VARCHAR(190) NOT NULL,
  expires_at             DATETIME NOT NULL,
  sent_at                DATETIME NULL,
  revoked_at             DATETIME NULL,
  first_downloaded_at    DATETIME NULL,
  last_downloaded_at     DATETIME NULL,
  download_count         INT UNSIGNED NOT NULL DEFAULT 0,
  created_by             BIGINT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_document_access_link_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_document_access_link_token (token_hash),
  UNIQUE KEY uq_payroll_document_access_link_idempotency
    (supplier_id, idempotency_key),
  KEY idx_payroll_document_access_link_document
    (supplier_id, payroll_document_id, id),
  KEY idx_payroll_document_access_link_employee
    (supplier_id, employee_id, id),
  KEY idx_payroll_document_access_link_dispatch
    (dispatch_state, next_attempt_at, id),
  KEY idx_payroll_document_access_link_expiry (expires_at),
  CONSTRAINT fk_payroll_document_access_link_document
    FOREIGN KEY (supplier_id, payroll_document_id)
    REFERENCES payroll_generated_documents (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_access_link_employee
    FOREIGN KEY (supplier_id, employee_id)
    REFERENCES payroll_employees (supplier_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_document_access_link_user
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payroll_document_access_link_attempts
    CHECK (attempt_count <= 10),
  CONSTRAINT chk_payroll_document_access_link_downloads
    CHECK (
      (download_count = 0 AND first_downloaded_at IS NULL)
      OR (download_count > 0 AND first_downloaded_at IS NOT NULL)
    ),
  CONSTRAINT chk_payroll_document_access_link_sent
    CHECK (dispatch_state <> 'sent' OR sent_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jednorázový číselný kód. Vlastní `supplier_id` tu není z pohodlí: dovoluje
-- složený FK na odkaz, takže kód z jednoho tenanta se fyzicky nemůže navázat na
-- odkaz jiného, ani kdyby se aplikace spletla.
CREATE TABLE IF NOT EXISTS payroll_document_access_codes (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  link_id        BIGINT UNSIGNED NOT NULL,
  code_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at     DATETIME NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at        DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip             VARBINARY(16) NULL,

  UNIQUE KEY uq_payroll_document_access_code_supplier_id (supplier_id, id),
  KEY idx_payroll_document_access_code_active (supplier_id, link_id, used_at, id),
  KEY idx_payroll_document_access_code_expires (expires_at),
  CONSTRAINT fk_payroll_document_access_code_link
    FOREIGN KEY (supplier_id, link_id)
    REFERENCES payroll_document_access_links (supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_payroll_document_access_code_attempts
    CHECK (attempts <= 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ověřená relace. Krátká životnost je záměr: u výkazu práce vydrží dny, u
-- výplatní pásky jde o pár hodin, protože prohlížeč bývá sdílený.
CREATE TABLE IF NOT EXISTS payroll_document_access_sessions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id    INT UNSIGNED NOT NULL,
  link_id        BIGINT UNSIGNED NOT NULL,
  session_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at     DATETIME NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at     DATETIME NULL,
  ip             VARBINARY(16) NULL,

  UNIQUE KEY uq_payroll_document_access_session_supplier_id (supplier_id, id),
  UNIQUE KEY uq_payroll_document_access_session_hash (session_hash),
  KEY idx_payroll_document_access_session_link (supplier_id, link_id, id),
  KEY idx_payroll_document_access_session_expires (expires_at),
  CONSTRAINT fk_payroll_document_access_session_link
    FOREIGN KEY (supplier_id, link_id)
    REFERENCES payroll_document_access_links (supplier_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

-- Odkaz smí ukazovat jen na OSOBNÍ dokument téhož tenanta a téže osoby. Firemní
-- sestavy (mzdový list firmy, rekapitulace) `employee_id` nemají a tímhle
-- triggerem se k zaměstnaneckému odkazu nedostanou ani omylem v kódu.
CREATE TRIGGER IF NOT EXISTS trg_payroll_document_access_link_tenant_person_insert
BEFORE INSERT ON payroll_document_access_links
FOR EACH ROW
BEGIN
  IF NOT EXISTS (
    SELECT 1
      FROM payroll_generated_documents document
     WHERE document.supplier_id = NEW.supplier_id
       AND document.id = NEW.payroll_document_id
       AND document.employee_id = NEW.employee_id
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document access link tenant or person mismatch';
  END IF;
END//

-- Dokument ani osoba se u existujícího odkazu nikdy nepřevěšuje. Kdyby to šlo,
-- byl by jednou ověřený odkaz použitelný na cizí pásku.
--
-- `token_hash` vzniká až ve chvíli, kdy worker sestavuje zprávu. Dřív to nejde:
-- plaintext lokátoru neexistuje nikde jinde než v tom jednom odeslaném e-mailu,
-- takže by ho zařazení do fronty nemělo kam uložit, aniž by ho tím prozradilo.
--
-- Dokud odkaz odešel NEÚSPĚŠNĚ (`sent_at IS NULL`), smí ho další pokus přerazit
-- novým — starý se nikomu nedostal do ruky a bez téhle možnosti by jediný výpadek
-- SMTP spálil lokátor natrvalo. Jakmile ale `sent_at` jednou je, token je
-- zamčený: odkaz, který zaměstnanec drží v e-mailu, se nesmí tiše přesměrovat
-- na jiný obsah.
CREATE TRIGGER IF NOT EXISTS trg_payroll_document_access_link_immutable_target
BEFORE UPDATE ON payroll_document_access_links
FOR EACH ROW
BEGIN
  IF NEW.supplier_id <> OLD.supplier_id
     OR NEW.payroll_document_id <> OLD.payroll_document_id
     OR NEW.employee_id <> OLD.employee_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document access link target is immutable';
  END IF;
  IF OLD.sent_at IS NOT NULL
     AND (NEW.token_hash IS NULL OR NEW.token_hash <> OLD.token_hash) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll document access link token is immutable once sent';
  END IF;
END//

DELIMITER ;
