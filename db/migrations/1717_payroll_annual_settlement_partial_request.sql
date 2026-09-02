-- MyÚčto.cz — rozdělaná evidence podkladů ročního zúčtování jde uložit.
--
-- Čtyři CHECK constrainty vynucovaly úplnost DVOJIC polí: „podaná žádost musí
-- mít datum", „doložené doklady předchozích plátců musí mít datum převzetí",
-- „povinnost podat přiznání musí mít důvod", „ročně uplatňované položky musí
-- být popsané". Účetní, která zaškrtla stav a ještě nestihla opsat datum nebo
-- větu z papíru, dostala odmítnutý zápis a přišla o CELÝ formulář — včetně
-- polí, která vyplnila správně.
--
-- Žádnou z těch podmínek nevyžaduje zákon k tomu, aby se údaj EVIDOVAL.
-- Vyžaduje je k tomu, aby se roční zúčtování PROVEDLO — a tam zůstávají:
-- `AnnualSettlementEligibility` je posuzuje jako překážky (`request_date_missing`,
-- `prior_documents_date_missing`, `must_file_tax_return`,
-- `annual_only_claims_unsupported`), takže zúčtování bez doloženého data lhůty
-- podle § 38ch odst. 1 a 3 dál neproběhne. Kontrola se tím z podmínky uložení
-- stala samostatným krokem před provedením.
--
-- `chk_payroll_annual_settlement_request_year` ZŮSTÁVÁ: rok mimo 2000–2199 není
-- rozdělaná práce, ale nesmysl.
--
-- Idempotence: MariaDB neumí u CHECK `ADD ... IF NOT EXISTS`, ale `DROP
-- CONSTRAINT IF EXISTS` ano, takže opakovaný běh migrace projde bez chyby.

SET NAMES utf8mb4;

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_evidence;

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_prior;

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_filing;

ALTER TABLE payroll_annual_settlement_requests
  DROP CONSTRAINT IF EXISTS chk_payroll_annual_settlement_request_claims;
