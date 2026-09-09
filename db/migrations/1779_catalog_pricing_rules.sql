-- 1779: cenové profily, deterministická pravidla a obchodní měnové kurzy

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS stock_pricing_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  calculation_mode ENUM('markup','target_margin') NOT NULL,
  percentage DECIMAL(7,3) NOT NULL,
  rounding ENUM('none','0.01','0.10','0.50','1','9_ending') NOT NULL DEFAULT 'none',
  fx_source VARCHAR(40) NOT NULL DEFAULT 'cnb',
  max_rate_age_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_spp_supplier_code (supplier_id, code),
  KEY idx_spp_supplier_currency (supplier_id, currency_code, is_active),
  CONSTRAINT fk_spp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_spp_percentage CHECK (
    (calculation_mode = 'markup' AND percentage >= -100)
    OR (calculation_mode = 'target_margin' AND percentage >= 0 AND percentage < 100)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_pricing_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  profile_id BIGINT UNSIGNED NOT NULL,
  match_type ENUM('product','category','manufacturer','vendor','default') NOT NULL,
  match_id BIGINT UNSIGNED NULL,
  priority INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_spr_supplier_match (supplier_id, is_active, match_type, match_id, priority, id),
  KEY idx_spr_profile (profile_id),
  CONSTRAINT fk_spr_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_spr_profile FOREIGN KEY (profile_id) REFERENCES stock_pricing_profiles(id) ON DELETE CASCADE,
  CONSTRAINT chk_spr_match CHECK (
    (match_type = 'default' AND match_id IS NULL)
    OR (match_type <> 'default' AND match_id IS NOT NULL AND match_id > 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_pricing_exchange_rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  currency_code CHAR(3) NOT NULL,
  rate_date DATE NOT NULL,
  source VARCHAR(40) NOT NULL,
  rate DECIMAL(14,6) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sper_rate (supplier_id, currency_code, source, rate_date),
  KEY idx_sper_lookup (supplier_id, currency_code, source, rate_date DESC),
  CONSTRAINT fk_sper_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT chk_sper_rate CHECK (rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_item_prices
  MODIFY COLUMN IF EXISTS price_mode ENUM('markup','target_margin','fixed') NOT NULL DEFAULT 'markup',
  ADD COLUMN IF NOT EXISTS use_pricing_rules TINYINT(1) NOT NULL DEFAULT 0 AFTER is_manual_override,
  ADD COLUMN IF NOT EXISTS computed_profile_id BIGINT UNSIGNED NULL AFTER computed_rate,
  ADD COLUMN IF NOT EXISTS computed_rule_id BIGINT UNSIGNED NULL AFTER computed_profile_id,
  ADD COLUMN IF NOT EXISTS computed_rate_date DATE NULL AFTER computed_rule_id,
  ADD COLUMN IF NOT EXISTS computed_rate_source VARCHAR(40) NULL AFTER computed_rate_date,
  ADD COLUMN IF NOT EXISTS computed_cost_source VARCHAR(40) NULL AFTER computed_rate_source,
  ADD COLUMN IF NOT EXISTS computed_calculation_mode VARCHAR(20) NULL AFTER computed_cost_source,
  ADD COLUMN IF NOT EXISTS computed_percentage DECIMAL(7,3) NULL AFTER computed_calculation_mode,
  ADD COLUMN IF NOT EXISTS computed_context JSON NULL AFTER computed_percentage;

INSERT IGNORE INTO stock_pricing_exchange_rates
  (supplier_id, currency_code, rate_date, source, rate)
SELECT used.supplier_id, rates.currency_code, rates.rate_date, 'cnb', rates.rate
  FROM (
        SELECT supplier_id, currency_code FROM stock_item_prices
        UNION
        SELECT supplier_id, currency_code FROM stock_item_vendors
       ) used
  JOIN (
        SELECT currency_code, rate_date, rate
          FROM (
                SELECT currency_code, rate_date, rate,
                       ROW_NUMBER() OVER (PARTITION BY currency_code ORDER BY rate_date DESC) AS position
                  FROM exchange_rates
               ) ranked
         WHERE position = 1
       ) rates ON rates.currency_code = used.currency_code
 WHERE rates.currency_code <> 'CZK';
