-- Filtrace přehledu podle období příspěvku nebo konkrétního titulu před stránkováním.
ALTER TABLE nonprofit_membership_charge_cases
 ADD INDEX IF NOT EXISTS ix_nmcc_list_title (supplier_id,title_key,period_to,period_from,member_id);
