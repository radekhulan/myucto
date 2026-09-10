-- Převod z Money S3: stav automatiky účtování před převodem uložený trvale.
--
-- Převod automatiku po dobu běhu vypíná a po úspěšném konci vrací na stav před
-- převodem. Ten stav žil jen v protokolu, který se zapisuje na KONCI běhu — spadlý
-- worker (timeout, pád procesu) protokol nezapsal, řádek zůstal „running" a další
-- běh si stav „před převodem" přečetl z už vypnuté automatiky. Automatika tak
-- zůstala vypnutá napořád.
--
-- automation_snapshot: stav automatiky zapsaný ještě PŘED jejím vypnutím.
-- automation_restored_at: kdy se automatika na tenhle stav (nebo stav staršího
-- neobnoveného běhu) vrátila. Další běh obnovuje na nejstarší neobnovený snímek.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS, dosypání jen do prázdných sloupců.

SET NAMES utf8mb4;

ALTER TABLE money_s3_imports
    ADD COLUMN IF NOT EXISTS automation_snapshot LONGTEXT NULL AFTER protocol,
    ADD COLUMN IF NOT EXISTS automation_restored_at TIMESTAMP NULL DEFAULT NULL AFTER automation_snapshot;

-- Dřívější ostré běhy: snímek z protokolu.
UPDATE money_s3_imports
   SET automation_snapshot = JSON_EXTRACT(protocol, '$.automation.before')
 WHERE mode = 'import'
   AND automation_snapshot IS NULL
   AND protocol IS NOT NULL
   AND JSON_VALID(protocol)
   AND JSON_TYPE(JSON_EXTRACT(protocol, '$.automation.before')) = 'OBJECT';

UPDATE money_s3_imports
   SET automation_restored_at = COALESCE(finished_at, created_at)
 WHERE mode = 'import'
   AND automation_restored_at IS NULL
   AND protocol IS NOT NULL
   AND JSON_VALID(protocol)
   AND JSON_EXTRACT(protocol, '$.automation.restored') = TRUE;

-- Úspěšný běh obnovil i snímky všech starších neúspěšných běhů téže firmy.
UPDATE money_s3_imports r
  JOIN (SELECT supplier_id, MAX(id) AS last_restored
          FROM money_s3_imports
         WHERE automation_restored_at IS NOT NULL
         GROUP BY supplier_id) x ON x.supplier_id = r.supplier_id
   SET r.automation_restored_at = COALESCE(r.finished_at, r.created_at)
 WHERE r.automation_restored_at IS NULL
   AND r.automation_snapshot IS NOT NULL
   AND r.id < x.last_restored;
