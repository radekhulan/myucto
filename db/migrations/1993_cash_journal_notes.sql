-- 1993 — Poznámky k pohybům peněžního deníku daňové evidence (1:N).
--
-- V podvojném účetnictví si účetní píše poznámky k zápisu deníku (journal_entry_notes,
-- migrace 1129). Daňová evidence deník nemá; pohyb peněžního deníku je řádek pokladního
-- dokladu, bankovního pohybu, ruční úhrady faktury, ručně zaplacené přijaté faktury nebo
-- pohybu GoPay. Poznámka proto visí na dvojici (source_type, source_id) řádku deníku —
-- stejný klíč, jakým deník řádek vrací (CashJournalRepository).
--
-- Zdroje nemají společného rodiče, FK jde jen na firmu; vlastnictví zdroje ověřuje
-- aplikace (CashJournalNoteService) před každým zápisem. Mazání je soft (deleted_at)
-- jako u poznámek zápisu.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cash_journal_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  source_type ENUM('cash','bank','invoice_payment','purchase_invoice','gopay') NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_cjn_source (supplier_id, source_type, source_id, pinned, created_at),
  CONSTRAINT fk_cjn_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
