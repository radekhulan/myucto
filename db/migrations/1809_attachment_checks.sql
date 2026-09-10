-- Kontrola zaúčtovaných dokladů proti uloženému vytěžení jejich příloh.
--
-- Jeden řádek = jedna dvojice doklad × obsah přílohy (sha256). Nese poslední
-- výsledek porovnání (částka, DUZP, IČO protistrany, VS) a potvrzení „v pořádku".
--
-- Potvrzení je vázané na OTISK porovnávaných hodnot (`ack_fingerprint`): dokud
-- se nezmění doklad ani vytěžení, varování se znovu nehlásí. Změna kterékoli
-- porovnávané hodnoty dá jiný otisk a rozdíl se ukáže znovu, i s dřívějším
-- důvodem potvrzení pro kontext.
--
-- `entity_type` je řetězec, ne ENUM: další typ dokladu se zapne v kódu bez migrace.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS attachment_checks (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          INT UNSIGNED NOT NULL,
  entity_type          VARCHAR(32) NOT NULL,
  entity_id            BIGINT UNSIGNED NOT NULL,
  sha256               CHAR(64) NOT NULL COMMENT 'obsah přílohy, jejíž vytěžení se porovnávalo',
  document_id          BIGINT UNSIGNED NULL COMMENT 'dokument v sekci Dokumenty; NULL = PDF slot dokladu',
  extraction_id        BIGINT UNSIGNED NULL,
  status               ENUM('match','mismatch','skipped') NOT NULL,
  severity             ENUM('warning','info') NULL,
  findings             LONGTEXT NULL COMMENT 'JSON seznam rozdílů',
  fingerprint          CHAR(64) NOT NULL COMMENT 'otisk porovnávaných hodnot dokladu i vytěžení',
  doc_tax_date         DATE NULL,
  attachment_tax_date  DATE NULL,
  checked_at           DATETIME NOT NULL,
  ack_fingerprint      CHAR(64) NULL COMMENT 'otisk, ke kterému bylo potvrzeno „v pořádku"',
  ack_reason           VARCHAR(500) NULL,
  ack_by               INT UNSIGNED NULL,
  ack_at               DATETIME NULL,
  UNIQUE KEY uq_attcheck_entity_sha (supplier_id, entity_type, entity_id, sha256),
  KEY idx_attcheck_status (supplier_id, status, severity),
  KEY idx_attcheck_tax_date (supplier_id, doc_tax_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
