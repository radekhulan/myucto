-- Sezení vlastní domény firmy vzniká z canonical sezení přes domain-login.
-- Pokud canonical sezení vzniklo přihlášením u poskytovatele identity, nese
-- jeho identitu i odvozené sezení, aby ho odpojení identity zrušilo.

ALTER TABLE supplier_domain_login_requests
  ADD COLUMN IF NOT EXISTS external_identity_id BIGINT UNSIGNED NULL;
