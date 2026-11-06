SET NAMES utf8mb4;
ALTER TABLE chart_of_accounts ADD UNIQUE KEY IF NOT EXISTS uq_coa_supplier_id(supplier_id,id);
CREATE TABLE IF NOT EXISTS accounting_account_definition_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 name VARCHAR(190) NOT NULL,
 normal_side ENUM('debit','credit') NULL,
 tax_deductibility ENUM('deductible','non_deductible') NOT NULL,
 is_active TINYINT(1) NOT NULL,
 definition_json LONGTEXT NOT NULL CHECK(JSON_VALID(definition_json)),
 definition_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence VARCHAR(1000) NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 recorded_at DATETIME(6) NOT NULL,
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 UNIQUE KEY uq_account_definition_date(supplier_id,account_id,effective_from),
 FOREIGN KEY(supplier_id,account_id) REFERENCES chart_of_accounts(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(is_active IN (0,1)),
 CHECK(BINARY name=BINARY JSON_VALUE(definition_json,'$.name')),
 CHECK(normal_side <=> JSON_VALUE(definition_json,'$.normal_side')),
 CHECK(tax_deductibility=JSON_VALUE(definition_json,'$.tax_deductibility')),
 CHECK(is_active=CAST(JSON_VALUE(definition_json,'$.is_active') AS UNSIGNED)),
 CHECK(BINARY definition_hash=BINARY SHA2(CONCAT(supplier_id,':',account_id,':',effective_from,':',definition_json),256))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE accounting_account_definition_history
 ADD CONSTRAINT IF NOT EXISTS chk_account_definition_payload CHECK(
 JSON_CONTAINS_PATH(definition_json,'all','$.name','$.normal_side','$.tax_deductibility','$.is_active')=1
 AND JSON_TYPE(JSON_EXTRACT(definition_json,'$.name'))='STRING'
 AND JSON_TYPE(JSON_EXTRACT(definition_json,'$.normal_side')) IN ('STRING','NULL')
 AND JSON_TYPE(JSON_EXTRACT(definition_json,'$.tax_deductibility'))='STRING'
 AND JSON_TYPE(JSON_EXTRACT(definition_json,'$.is_active'))='INTEGER'
 );
