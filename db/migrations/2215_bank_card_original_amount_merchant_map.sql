-- Platby kartou v cizí měně: původní částka a měna nákupu, jak je uvádí banka.
-- Karetní pohyb z eurového účtu za dolarovou službu nese na výpisu odepsané eura,
-- doklad je ale v dolarech. Bez původní částky ho párování neumí porovnat.
ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS original_amount DECIMAL(14,2) NULL
    COMMENT 'Částka nákupu v původní měně (se znaménkem pohybu), jen když se liší od měny pohybu.'
    AFTER currency,
  ADD COLUMN IF NOT EXISTS original_currency CHAR(3) NULL
    COMMENT 'Původní měna nákupu kartou (ISO 4217).'
    AFTER original_amount;

-- Doplnění z uložených odpovědí API CREDITAS (originalAmount u karetních plateb).
-- Textové sloupce JSON_TABLE nesou collation výslovně: bez ní dědí výchozí collation
-- databáze (na MariaDB 11.8 často utf8mb4_uca1400_ai_ci) a porovnání s bank_ref
-- (utf8mb4_unicode_ci) skončí chybou 1267.
UPDATE bank_transactions bt
  JOIN bank_statements bs ON bs.id = bt.statement_id
  JOIN JSON_TABLE(
         bs.file_content,
         '$.transactions[*]' COLUMNS (
           tid VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.transactionId',
           amount_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.amount.currency',
           original_value DECIMAL(14,2) PATH '$.originalAmount.value',
           original_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.originalAmount.currency'
         )
       ) j ON j.tid = bt.bank_ref
   SET bt.original_amount = IF(bt.amount < 0, -ABS(j.original_value), ABS(j.original_value)),
       bt.original_currency = UPPER(j.original_currency)
 WHERE bs.source = 'bank_api'
   AND bs.file_name LIKE 'creditas-%'
   AND JSON_VALID(bs.file_content)
   AND bt.original_currency IS NULL
   AND j.original_value IS NOT NULL
   AND j.original_value <> 0
   AND j.original_currency IS NOT NULL
   AND UPPER(j.original_currency) <> UPPER(j.amount_currency);

-- Naučený obchodník karetní platby: text obchodníka z pohybu → dodavatel.
-- Plní se ručním spárováním pohybu kartou s přijatým dokladem. Párování kartou podle
-- obchodníka pak dodavatele pozná, i když se jeho název s textem banky neshoduje
-- (např. „www.cd.cz/eshop/" a „České dráhy").
CREATE TABLE IF NOT EXISTS bank_card_merchant_map (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id INT UNSIGNED NOT NULL,
  merchant_key VARCHAR(190) NOT NULL COMMENT 'Normalizovaný text obchodníka z pohybu kartou.',
  client_id BIGINT UNSIGNED NOT NULL,
  manual_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_match_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bcmm_merchant_client (supplier_id, merchant_key, client_id),
  KEY fk_bcmm_client (client_id),
  CONSTRAINT fk_bcmm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE,
  CONSTRAINT fk_bcmm_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
