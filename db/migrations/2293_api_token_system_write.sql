-- Volitelný příznak API tokenu: plný zápis jako přihlášený uživatel ve webovém
-- rozhraní (`system_write`, issue #371).
--
-- Token s příznakem nepodléhá omezením specifickým pro bearer (read-only účetní
-- vrstva, session-only agendy, allowlist cest). Dál platí jen oprávnění jeho
-- uživatele. Přihlášení, MFA, heslo, správa tokenů, licence a správa instance
-- zůstávají tokenu nedostupné; uzavřené účetní období ani podané DPH, KH, SH
-- nebo OSS token nepřebije.
--
-- ⚠️ Výchozí hodnota je 0 a musí jí zůstat. Příznak jde zapnout jen při založení
-- tokenu se zápisem v přihlášené relaci se step-upem a jen na instanci, která to
-- povolí konfigurací `api.allow_system_write_tokens`. Bez ní se token s příznakem
-- chová jako běžný token.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS allow_system_write TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'token smí zapisovat jako přihlášený uživatel (system_write)'
    AFTER allow_issued_invoice_edit;
