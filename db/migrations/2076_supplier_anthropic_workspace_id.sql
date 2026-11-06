-- ID workspace pro Anthropic klíč, který není přiřazený k žádnému workspace. Takový klíč
-- Anthropic přijme jen s hlavičkou anthropic-workspace-id. Netajný údaj firmy jako
-- openai_base_url; prázdné = hlavička se neposílá.
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS anthropic_workspace_id VARCHAR(128) NULL AFTER anthropic_default_model;
