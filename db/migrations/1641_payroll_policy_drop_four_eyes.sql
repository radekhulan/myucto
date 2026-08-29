-- MyÚčto.cz — pravidlo čtyř očí se nezavádí, sloupec odchází.
--
-- `four_eyes_required` zavedla migrace 1276 s výchozí `1`. Migrace 1539
-- (jednoúčetní workflow) ho srovnala na `0` a od té doby ho aplikace držela
-- natvrdo vypnutý: `PayrollEmployerPolicyService` hodnotu ze vstupu zahazoval
-- a přepisoval na `false`, `PayrollSetupFeaturesResolver` hlásil `fourEyes:
-- false` a jediná podmínka ve workflow tak nikdy nemohla nastat.
--
-- Rozhodnutí je uzavřené: pravidlo se nezavede. Řada firem má jedinou účetní,
-- takže samostatný krok „Zkontrolovat" byl prázdný obřad před „Schválit" —
-- workflow ho stejně neporovnávalo s JINOU osobou, jen kontrolovalo, že je
-- vyplněný. Stopa po kontrole se zapisuje dál: schválení doplní `reviewed_by`
-- i `reviewed_at` samo a do historie běhu jde událost `review` označená jako
-- implicitní.
--
-- Sloupec, který nikdo nečte a nikdo nesmí zapnout, je jen dva zdroje pravdy
-- v jednom — proto odchází i s CHECKem. Auditní snímky politik
-- (`payroll_employer_policy_audit.snapshot_json`) zůstávají beze změny: jsou
-- neměnné a co v nich tehdy bylo, tam patří.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_four_eyes;

ALTER TABLE payroll_employer_policies
  DROP COLUMN IF EXISTS four_eyes_required;
