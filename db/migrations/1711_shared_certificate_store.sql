-- Jedno úložiště certifikátů místo tří.
--
-- PROČ: trezor `epo_signing_credentials` je už dnes centrála, ze které vybírají
-- EPO i mzdová podání (`payroll_submission_signing_profiles.credential_id` na něj
-- míří cizím klíčem `fk_pssp_credential`, viz migrace 1373) a do které
-- `CertificateVaultAction` nahrává certifikáty. ISDS si ale drží vlastní kopie
-- ve dvou tabulkách, takže tentýž certifikát nahrává uživatel dvakrát — jednou
-- v Podáních, podruhé v Datové schránce. Horší než opisování je platnost:
-- certifikát vyprší a vyměnit ho musíte na víc místech, aniž by kterékoli
-- z nich řeklo, že ta ostatní jsou prošlá.
--
-- POZOR na jméno: `signing_credentials` je JINÁ tabulka — profily podepisování
-- dokumentů, kde certifikát bydlí jako soubor na disku (`certificate_path`).
-- Sem `credential_id` NEMÍŘÍ.
--
-- Sloupce s ciphertextem se ZATÍM neruší, jen se uvolňují na NULL: řádek
-- s vlastní kopií jede dál beze změny, nový odkazuje. Zahodí je až migrace
-- poté, co budou všechny řádky převedené.

ALTER TABLE submission_channel_credentials
  ADD COLUMN IF NOT EXISTS credential_id BIGINT UNSIGNED NULL
    COMMENT 'odkaz do sdíleného úložiště certifikátů; NULL = vlastní kopie v ciphertextu'
    AFTER supplier_id,
  MODIFY COLUMN certificate_ciphertext MEDIUMTEXT NULL
    COMMENT 'vlastní kopie certifikátu; NULL, když se bere z credential_id';

ALTER TABLE isds_gateway_registrations
  ADD COLUMN IF NOT EXISTS credential_id BIGINT UNSIGNED NULL
    COMMENT 'odkaz do sdíleného úložiště certifikátů; NULL = vlastní kopie v ciphertextu'
    AFTER id,
  MODIFY COLUMN certificate_ciphertext MEDIUMTEXT NULL
    COMMENT 'vlastní kopie certifikátu; NULL, když se bere z credential_id';

-- Index, ne cizí klíč: trezor maže měkce (`deleted_at`), takže
-- tvrdá vazba by bránila i tomu měkkému smazání. Osiřelý odkaz řeší čtecí
-- cesta pojmenovanou chybou, ne tichým pádem na prázdno.
CREATE INDEX IF NOT EXISTS idx_channel_credentials_credential
  ON submission_channel_credentials (credential_id);

CREATE INDEX IF NOT EXISTS idx_gateway_registrations_credential
  ON isds_gateway_registrations (credential_id);

-- Právě jedna z obou cest musí být vyplněná. MariaDB neumí `IF NOT EXISTS`
-- u CHECK, takže se nejdřív zahazuje (viz ostatní migrace).
ALTER TABLE submission_channel_credentials
  DROP CONSTRAINT IF EXISTS chk_channel_credentials_certificate_source;
ALTER TABLE submission_channel_credentials
  ADD CONSTRAINT chk_channel_credentials_certificate_source
    CHECK (credential_id IS NOT NULL OR certificate_ciphertext IS NOT NULL);

ALTER TABLE isds_gateway_registrations
  DROP CONSTRAINT IF EXISTS chk_gateway_registrations_certificate_source;
ALTER TABLE isds_gateway_registrations
  ADD CONSTRAINT chk_gateway_registrations_certificate_source
    CHECK (credential_id IS NOT NULL OR certificate_ciphertext IS NOT NULL);
