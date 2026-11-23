-- Přihlášení přes externího poskytovatele identity (OpenID Connect, první je
-- Microsoft Entra ID). Účet v MyÚčto musí existovat předem; identita se k němu
-- naváže pozvánkou nebo samotným uživatelem a od té doby se páruje výhradně
-- podle (poskytovatel, vydavatel, subjekt). E-mail z poskytovatele účet nikdy
-- nespáruje, uloží se jen pro přehled.
--
-- U Entra ID je subjekt `oid` (stabilní ID objektu v tenantu) a tenant_id `tid`.
-- Claim `sub` je u Entra párový pro každou registraci aplikace, takže by se při
-- výměně registrace (prod/test, nový tajný klíč nestačí, ale nová aplikace ano)
-- všem uživatelům rozpojil.

CREATE TABLE IF NOT EXISTS user_external_identities (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  provider        VARCHAR(32)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issuer          VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tenant_id       VARCHAR(64)  CHARACTER SET ascii COLLATE ascii_bin NULL,
  subject         VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email_at_link   VARCHAR(190) NULL,
  display_name    VARCHAR(190) NULL,
  linked_by       ENUM('self','admin','invite') NOT NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_used_at    DATETIME(6) NULL,
  UNIQUE KEY uq_external_identity (provider, issuer, subject),
  KEY ix_external_identity_user (user_id, provider),
  CONSTRAINT fk_external_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rozběhnuté přihlášení u poskytovatele. `id` je hash parametru `state`,
-- záznam je jednorázový (consumed_at) a platí deset minut. `browser_binding`
-- je hash náhodné cookie, kterou dostal prohlížeč, který přihlášení zahájil,
-- takže cizí callback s ukradeným `state` neprojde.
CREATE TABLE IF NOT EXISTS oidc_login_flows (
  id                 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  provider           VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose            VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  nonce              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code_verifier      VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  browser_binding    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  return_to          VARCHAR(512) NULL,
  user_id            BIGINT UNSIGNED NULL,
  session_id_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  step_up_operation  VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
  invite_id          BIGINT UNSIGNED NULL,
  created_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at         DATETIME(6) NOT NULL,
  consumed_at        DATETIME(6) NULL,
  KEY ix_oidc_flows_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pozvánka k propojení účtu s poskytovatelem. Správce ji pošle konkrétní osobě,
-- ta se přihlásí u poskytovatele a identita se naváže na pozvaný účet. Díky tomu
-- se pro první spojení nepoužije e-mail z poskytovatele.
CREATE TABLE IF NOT EXISTS oidc_link_invites (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  provider     VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at   DATETIME(6) NOT NULL,
  used_at      DATETIME(6) NULL,
  UNIQUE KEY uq_oidc_invite_token (token_hash),
  KEY ix_oidc_invite_user (user_id, provider),
  CONSTRAINT fk_oidc_invite_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Politika přihlášení firmy. `sso_required` = do firmy se smí jen se sezením
-- vydaným přihlášením u `login_sso_provider`, heslo ani passkey tam nestačí.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS login_policy ENUM('any','sso_required') NOT NULL DEFAULT 'any',
  ADD COLUMN IF NOT EXISTS login_sso_provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;

-- Sezení vydané přihlášením u poskytovatele nese odkaz na identitu, aby šlo
-- při odpojení identity zrušit i její sezení.
ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS external_identity_id BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS ix_sessions_external_identity (external_identity_id);
