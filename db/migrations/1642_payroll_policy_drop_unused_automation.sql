-- MyÚčto.cz — přepínače automatiky bez konzumenta odcházejí.
--
-- Politika zaměstnavatele nabízela tři přepínače automatiky. Konzumenta má
-- jediný z nich, `automatic_posting_enabled`: podle něj se schválená revize
-- zaúčtuje sama (`PayrollApprovedRevisionPostingService::post()`), a když je
-- vypnutý, účetní si zaúčtování vyvolá příkazem `post`. Ten zůstává.
--
-- `automatic_calculation_enabled` a `automatic_payments_enabled` nečetl NIKDO.
-- Docestovaly jen do `PayrollSetupCheckService`, kde si samy na sebe hlídaly
-- „zapnutá funkce vyžaduje dokončenou konfiguraci" — tedy blokovaly nastavení
-- kvůli funkci, která neexistuje. Obrazovka je přitom nabízela jako běžné
-- zaškrtávátko, takže firma, která si zapnula „automatický výpočet", čekala
-- spočítané mzdy a dostala prázdný běh.
--
-- Doplnit chování by nebyl úklid, ale funkce s vlastním návrhem: automatický
-- výpočet znamená zřetězit druhý příkaz stavového automatu (`calculate` hned
-- po `lock_inputs`) s vlastním idempotency klíčem, vlastní událostí v historii
-- běhu, novým `row_version` a rozhodnutím, co se stane, když výpočet selže —
-- a hlavně: co se vrátí klientovi, který dnes řídí obrazovku podle `to_status`
-- jediného příkazu. Totéž platí pro automatickou přípravu plateb. Ať tedy
-- přepínač přijde AŽ S FUNKCÍ; do té doby ať obrazovka neslibuje, co neumí.
--
-- Uložené hodnoty se nezachraňují: nic je nečetlo, takže neznamenaly nic.
-- Auditní snímky politik zůstávají beze změny — jsou neměnné.

SET NAMES utf8mb4;

ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_auto_calculation;

ALTER TABLE payroll_employer_policies
  DROP CONSTRAINT IF EXISTS chk_payroll_employer_policy_auto_payments;

ALTER TABLE payroll_employer_policies
  DROP COLUMN IF EXISTS automatic_calculation_enabled;

ALTER TABLE payroll_employer_policies
  DROP COLUMN IF EXISTS automatic_payments_enabled;
