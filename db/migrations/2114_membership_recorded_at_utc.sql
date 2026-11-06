SET NAMES utf8mb4;

ALTER TABLE nonprofit_members
    ADD COLUMN IF NOT EXISTS recorded_at_utc DATETIME(6) NULL AFTER created_at;

ALTER TABLE nonprofit_member_revisions
    ADD COLUMN IF NOT EXISTS recorded_at_utc DATETIME(6) NULL AFTER created_at;

ALTER TABLE nonprofit_membership_charge_claims
    ADD COLUMN IF NOT EXISTS recorded_at_utc DATETIME(6) NULL AFTER created_at;
