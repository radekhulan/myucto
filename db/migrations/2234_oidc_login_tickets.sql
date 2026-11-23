-- Poskytovatel identity, který nahrazuje jen heslo (Google, Apple, Seznam),
-- po návratu vydá krátký jednorázový lístek. S ním prohlížeč projde běžným
-- přihlášením (/api/auth/login) a druhý faktor se ověří stejně jako po hesle.
-- Lístek se ukládá do oidc_login_flows (purpose = 'ticket') a nese identitu,
-- ze které vznikl, aby výsledné sezení šlo zrušit při odpojení identity.

ALTER TABLE oidc_login_flows
  ADD COLUMN IF NOT EXISTS external_identity_id BIGINT UNSIGNED NULL;
