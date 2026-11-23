-- Filtr přehledu podle titulu: předpisy titulu v pořadí členů, takže dávka i výběr čtou jen členy s titulem od kurzoru.
-- Odebrání před založením sjednotí pořadí sloupců i v databázi, kde index stejného jména vznikl s jiným pořadím.
ALTER TABLE nonprofit_membership_charge_cases
 DROP INDEX IF EXISTS ix_nmcc_list_title,
 ADD INDEX ix_nmcc_list_title (supplier_id,title_key,member_id,period_to,period_from);
