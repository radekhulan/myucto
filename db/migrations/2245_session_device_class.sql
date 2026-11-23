-- MyÚčto.cz — druh zařízení relace a důvod jejího ukončení.
--
-- Parametry licence mohou omezit počet současně přihlášených relací jednoho
-- uživatele zvlášť pro prohlížeč počítače a pro mobilní zařízení. Druh se
-- určí při přihlášení z User-Agent (SessionDeviceClass) a uloží k relaci,
-- stávající relace se doplní stejným pravidlem. `revoke_reason` rozliší
-- relaci ukončenou novějším přihlášením (`superseded`), aby jí aplikace
-- řekla, proč byla odhlášena.
--
-- Re-run safe: ADD COLUMN IF NOT EXISTS, doplnění jen prázdných hodnot.

SET NAMES utf8mb4;

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS device_class ENUM('browser','mobile') NULL DEFAULT NULL
    COMMENT 'druh zařízení pro limit souběžných relací',
  ADD COLUMN IF NOT EXISTS revoke_reason VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'superseded = ukončena novějším přihlášením nad limit licence';

UPDATE sessions
   SET device_class = IF(user_agent REGEXP 'Mobi|Android|iPhone|iPad|iPod|Windows Phone|IEMobile|Opera Mini|BlackBerry|webOS|CFNetwork|Dalvik|okhttp',
                         'mobile', 'browser')
 WHERE device_class IS NULL;
