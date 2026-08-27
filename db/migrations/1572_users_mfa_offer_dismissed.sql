-- 1572: odmítnutí dobrovolné nabídky MFA
--
-- PROČ: při `auth.require_mfa = false` je `must_setup_mfa` vždycky false, router
-- na `/setup-mfa` nikoho nepošle a stránka se nikdy nezobrazí. Uživatel tedy
-- spadne rovnou do aplikace a o tom, že si dvoufázové ověření může zapnout, se
-- nedozví. Nabídku proto zobrazujeme i bez vynucení — ale s tlačítkem
-- „pokračovat bez ověření".
--
-- Odmítnutí musí přežít odhlášení i výměnu zařízení, jinak by se nabídka vracela
-- při každém přihlášení a z „nabídky" by se stalo otravné vynucení jinými
-- prostředky. Session ani localStorage se proto nepoužijí — rozhodnutí patří
-- k účtu.
--
-- Proč sloupec na `users` a ne `user_preferences`: ta tabulka je opt-in úložiště
-- rozvržení tabulek (`pref_key` má prefix whitelist `table.<page_key>`) a je
-- zapisovatelná obecným preference endpointem. Bezpečnostní rozhodnutí do ní
-- nepatří — tohle smí nastavit jen dedikovaný endpoint pro přihlášeného majitele
-- účtu.
--
-- NULL = nabídka se ještě může zobrazit (výchozí stav i pro všechny existující
-- účty). Vyplněný čas = uživatel ji odmítl; MFA si nadále může kdykoli zapnout
-- v Profil → Zabezpečení. Zapnutí faktoru sloupec nemaže a nemusí: nabídka se
-- řídí i tím, že účet žádný faktor nemá.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS mfa_offer_dismissed_at DATETIME(6) NULL DEFAULT NULL
        COMMENT 'kdy uživatel odmítl dobrovolnou nabídku MFA; NULL = nabídka se může zobrazit';
