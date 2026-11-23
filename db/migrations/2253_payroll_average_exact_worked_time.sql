-- Přesná odpracovaná doba hodinového odměňování; původní otisky zůstávají beze změny.
ALTER TABLE payroll_average_earning_snapshots
    ADD COLUMN IF NOT EXISTS worked_duration_units BIGINT UNSIGNED NULL AFTER worked_minutes,
    MODIFY COLUMN worked_minutes INT UNSIGNED NULL,
    DROP CONSTRAINT IF EXISTS chk_payroll_average_actual_input,
    DROP CONSTRAINT IF EXISTS chk_payroll_average_duration;

ALTER TABLE payroll_average_earning_snapshots
    ADD CONSTRAINT chk_payroll_average_actual_input CHECK (
        source_kind <> 'actual' OR (
            worked_days >= 21 AND (
                (worked_duration_units IS NOT NULL AND worked_duration_units > 0)
                OR (worked_duration_units IS NULL AND worked_minutes IS NOT NULL AND worked_minutes > 0)
            )
        )
    ),
    ADD CONSTRAINT chk_payroll_average_duration CHECK (
        (worked_duration_units IS NULL AND worked_minutes IS NOT NULL)
        OR (worked_duration_units IS NOT NULL AND (
            (worked_minutes IS NULL AND MOD(worked_duration_units, 50) <> 0)
            OR (worked_minutes IS NOT NULL AND worked_duration_units = worked_minutes * 50)
        ))
    );
