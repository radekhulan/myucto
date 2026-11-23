-- MyÚčto.cz — opravné JMHZ za měsíc, který podal předchozí program.
--
-- PROČ
--
-- Obsahová oprava JMHZ se váže na řádné podání z MyÚčta: bere z jeho zmrazeného
-- XML GUID podání, variabilní symbol, období a GUID formulářů osob (Pravidla
-- podání JMHZ 1.4.5, kap. 3: oprava nemění GUID podání a každý opravný formulář
-- odkazuje na GUID součásti, kde byla poprvé nahlášena). Měsíc podaný jiným
-- programem takové podání nemá, takže opravu nešlo navázat.
--
-- CO TABULKA NESE
--
-- Řádné podání předchozího programu převzaté jako kořen opravy. Vzniká k němu
-- běžné `payroll_submissions` (regular, kanál `other`, přijaté ručním potvrzením)
-- s datovou větou:
--
--   * `original_xml`: nahrané původní XML hlášení (ověřené XSD, VS a obdobím),
--   * `guid_reference`: účetní zadala GUID podání a GUID formulářů osob; datová
--     věta je sestavená z aktuální přípravy s těmito GUID a slouží jen jako
--     identita formulářů, obsah původního podání neznáme.
--
-- Nic se neodesílá. Jeden kořen na podání.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_jmhz_external_roots (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             INT UNSIGNED NOT NULL,
  environment             ENUM('production','test') NOT NULL,
  submission_id           BIGINT UNSIGNED NOT NULL,
  mode                    ENUM('original_xml','guid_reference') NOT NULL,
  submission_guid         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  period                  CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  form_count              INT UNSIGNED NOT NULL,
  unmatched_form_count    INT UNSIGNED NOT NULL DEFAULT 0,
  original_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  note                    VARCHAR(1000) NOT NULL,
  created_by              BIGINT UNSIGNED NOT NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_payroll_jmhz_external_root_submission (supplier_id, environment, submission_id),
  KEY idx_payroll_jmhz_external_root_period (supplier_id, environment, period),
  CONSTRAINT fk_payroll_jmhz_external_root_submission
    FOREIGN KEY (supplier_id, environment, submission_id)
    REFERENCES payroll_submissions (supplier_id, environment, id) ON DELETE RESTRICT,
  CONSTRAINT fk_payroll_jmhz_external_root_creator
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_payroll_jmhz_external_root_guid
    CHECK (submission_guid REGEXP '^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$'),
  CONSTRAINT chk_payroll_jmhz_external_root_period
    CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
  CONSTRAINT chk_payroll_jmhz_external_root_original
    CHECK ((mode = 'original_xml') = (original_sha256 IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
