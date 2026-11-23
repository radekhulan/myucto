-- Celoinstanční přehled aktuálních relací od posledního kontaktu, včetně kurzorového stránkování.
ALTER TABLE sessions
  ADD INDEX IF NOT EXISTS idx_sessions_admin_last_seen
    (revoked_at, replaced_at, last_seen, session_family_id, expires_at);
