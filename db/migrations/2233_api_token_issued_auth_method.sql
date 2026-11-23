-- Jakým přihlášením vzniklo sezení, ze kterého byl API token (nebo MCP grant)
-- vydaný. Firma, která vyžaduje přihlášení přes poskytovatele identity
-- (supplier.login_policy = sso_required), přijme jen token vydaný ze sezení
-- tohoto poskytovatele. Token vydaný z hesla by jinak politiku obešel na celou
-- dobu své platnosti. Starší tokeny údaj nemají, a do takové firmy proto
-- neprojdou; do ostatních firem fungují beze změny.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS issued_auth_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;

ALTER TABLE mcp_oauth_codes
  ADD COLUMN IF NOT EXISTS issued_auth_method VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;
