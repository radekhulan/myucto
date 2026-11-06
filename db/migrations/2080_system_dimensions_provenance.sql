SET NAMES utf8mb4;

ALTER TABLE dimension_types
    ADD COLUMN IF NOT EXISTS system_key VARCHAR(30) NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_dimension_type_system (supplier_id, system_key),
    ADD CONSTRAINT IF NOT EXISTS chk_dimension_type_system CHECK (
        system_key IS NULL OR (
            supplier_id IS NOT NULL AND supplier_group_id IS NULL AND kind = 'custom'
            AND system_key IN ('activity', 'activity_kind', 'tax_regime', 'unit', 'service', 'funding_source')
        )
    );

ALTER TABLE dimension_values
    ADD COLUMN IF NOT EXISTS semantic_key VARCHAR(50) NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_dimension_value_semantic (type_id, semantic_key);

ALTER TABLE document_dimensions
    ADD COLUMN IF NOT EXISTS assignment_origin VARCHAR(24) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS assignment_origin_ref VARCHAR(190) NULL;

ALTER TABLE document_dimension_splits
    ADD COLUMN IF NOT EXISTS assignment_origin VARCHAR(24) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS assignment_origin_ref VARCHAR(190) NULL;

ALTER TABLE journal_entry_line_dimensions
    ADD COLUMN IF NOT EXISTS assignment_origin VARCHAR(24) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS assignment_origin_ref VARCHAR(190) NULL;

ALTER TABLE journal_entry_line_dimension_splits
    ADD COLUMN IF NOT EXISTS assignment_origin VARCHAR(24) NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS assignment_origin_ref VARCHAR(190) NULL;
