-- Časové rozlišení členských příspěvků: uzávěrka hledá případy, jejichž období
-- přesahuje rozvahový den (period_to > konec období), odklad i rozpuštění v N+1.
ALTER TABLE nonprofit_membership_charge_cases
    ADD INDEX IF NOT EXISTS ix_nmcc_deferral
        (supplier_id, period_to, period_from, id);
