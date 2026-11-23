-- Přihlášení přes poskytovatele identity se vynucuje jen pro celou instalaci
-- (auth.oidc.required_provider, ENV MYINVOICE_OIDC_REQUIRED). Nastavení
-- jednotlivých firem z migrace 2232 se zrušilo i se sloupci.

ALTER TABLE supplier
  DROP COLUMN IF EXISTS login_policy,
  DROP COLUMN IF EXISTS login_sso_provider;
