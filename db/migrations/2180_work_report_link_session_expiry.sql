-- MyÚčto.cz — serverová platnost relací veřejného náhledu na výkaz práce.
--
-- Relace z ověření odkazu dosud vypršela jen s cookie (180 dní), na serveru
-- platila navždy. Nový sloupec expires_at nastaví ověření kódem (podle
-- work_report_tracking.session_lifetime_days, výchozí 180 dní) a náhled ho
-- kontroluje při každém požadavku.
--
-- Existující relace dostanou created_at + 180 dní, tedy stejnou lhůtu, jakou
-- měla jejich cookie. Klienti s platnou cookie se proto znovu ověřovat nemusí;
-- relace starší 180 dní už cookie v prohlížeči stejně neměly.

SET NAMES utf8mb4;

ALTER TABLE work_report_link_sessions
  ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER last_seen_at;

UPDATE work_report_link_sessions
   SET expires_at = created_at + INTERVAL 180 DAY
 WHERE expires_at IS NULL;

ALTER TABLE work_report_link_sessions
  MODIFY COLUMN expires_at DATETIME NOT NULL;
