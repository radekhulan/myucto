-- Redakce sdílené auditní stopy (activity_log) při schváleném výmazu osobních údajů (#321, krok 10).
--
-- Řetěz activity_log navazuje přes uložený hash, takže změněný payload se projeví jen na svém řádku.
-- verify() přijme redigovaný řádek jen s tímto záznamem, se shodným otiskem, pro akci z allowlistu
-- (ActivityLogHashChain::REDACTABLE_ACTIONS) a s atestací redakce zapsanou jako článek řetězu
-- (activity_log.redacted). Při smazání firmy tabulka zůstává s řetězem, klon ji vyprázdní (přepočítá hash).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log_redactions (
 activity_log_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 supplier_id INT UNSIGNED NULL,
 redacted_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'NULL u záznamu, který ještě nebyl zapečetěný',
 source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_alr_source (source,source_id),
 CONSTRAINT fk_alr_log FOREIGN KEY (activity_log_id) REFERENCES activity_log (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
