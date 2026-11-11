-- Filtr přehledu podle titulu: předpisy titulu v pořadí členů, takže dávka i výběr čtou jen členy s titulem od kurzoru.
ALTER TABLE nonprofit_membership_charge_cases
 ADD INDEX IF NOT EXISTS ix_nmcc_list_title (supplier_id,title_key,member_id,period_to,period_from);
