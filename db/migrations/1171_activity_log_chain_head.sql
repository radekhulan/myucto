-- 1171: § 33a — hlava hash-řetězu v samostatném řádku (odstranění deadlocku)
--
-- `ActivityLogHashChain::seal()` četl předchozí článek dotazem
--   SELECT hash FROM activity_log WHERE id < ? AND hash IS NOT NULL ORDER BY id DESC LIMIT 1 FOR UPDATE
-- Zámek je pro správnost NUTNÝ: bez něj by dvě souběžné transakce přečetly týž `prev_hash`
-- a řetěz by se rozvětvil. Jenže tenhle dotaz skenuje ROZSAH, takže v REPEATABLE READ bere
-- gap locky — a spolu s insert-intention zámkem nového řádku se dvě transakce zaklesnou.
--
-- Naměřeno při souběhu dvou testovacích sad:
--   PDOException SQLSTATE[40001]: 1213 Deadlock found when trying to get lock
-- V produkci by to potkalo dva souběžné uživatele při jakékoli auditované operaci.
--
-- ── Řešení ──────────────────────────────────────────────────────────────────────────
-- Hlava řetězu se drží v JEDNOM ZNÁMÉM ŘÁDKU. `SELECT ... WHERE id = 1 FOR UPDATE` je
-- bodový zámek nad primárním klíčem: žádný rozsah, žádné gap locky a pořadí zamykání je
-- vždy stejné. Souběh se tím mění z DEADLOCKU na frontu — a fronta je u sériového řetězu
-- správné chování, deadlock ne.
--
-- Sémantika řetězu se NEMĚNÍ: pořád je globální přes všechny tenanty a hash se počítá ze
-- stejných sloupců, takže existující články zůstávají platné a `verify()` je beze změny.
-- Proto se hlava naplní z aktuálního posledního zapečetěného záznamu, ne od nuly.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log_chain_head (
    id        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_id   BIGINT UNSIGNED NULL COMMENT 'id posledního zapečetěného záznamu',
    last_hash CHAR(64) NULL COMMENT 'jeho hash = prev_hash pro další článek',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_alch_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='§ 33a — hlava hash-řetězu activity_log; jediný řádek, bodový zámek místo range scanu';

-- Navázání na existující řetěz: bez toho by první zapečetění po migraci začalo znovu
-- od NULL a ověření by hlásilo přerušení tam, kde žádné není.
INSERT INTO activity_log_chain_head (id, last_id, last_hash)
SELECT 1, a.id, a.hash
  FROM activity_log a
 WHERE a.hash IS NOT NULL
 ORDER BY a.id DESC
 LIMIT 1
ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), last_hash = VALUES(last_hash);

-- Prázdný log (čerstvá instalace) — řádek musí existovat vždy, jinak by se na něj
-- nedalo zamknout a seal() by spadl zpět na skenování rozsahu.
INSERT IGNORE INTO activity_log_chain_head (id, last_id, last_hash) VALUES (1, NULL, NULL);
