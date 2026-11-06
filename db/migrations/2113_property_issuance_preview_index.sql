ALTER TABLE unit_charges
    ADD INDEX IF NOT EXISTS idx_unit_charge_preview_target
        (supplier_id, unit_id, period_from, status, id);
