-- 1815: úklid dočasných procedur, které po sobě měly migrace smazat
--
-- Migrace 1029, 1167, 1193, 1401, 1505 a 1605 si zakládají pomocnou proceduru,
-- zavolají ji a na konci ji zahodí. Když běh spadne mezi CREATE a DROP, nebo se
-- databáze obnoví z dumpu pořízeného v tu chvíli, procedura v databázi zůstane
-- a kontrola struktury ji hlásí jako uloženou proceduru navíc.
--
-- Trvalé procedury (sp_recompute_*, sp_cleanup_*, sp_find_invoices_with_bad_dates)
-- sem nepatří. Opakovaný běh je no-op.

DROP PROCEDURE IF EXISTS sp_f1_add_system_versioning;
DROP PROCEDURE IF EXISTS sp_journal_versioning_selfheal;
DROP PROCEDURE IF EXISTS migrate_payroll_contacts_1193;
DROP PROCEDURE IF EXISTS myucto_1401_accumulator_health;
DROP PROCEDURE IF EXISTS migrate_cash_amount_guard_1505;
DROP PROCEDURE IF EXISTS assert_payroll_registration_event_business_keys;
