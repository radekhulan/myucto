-- Rozpad řádku zápisu v deníku na doklady saldokonta.
--
-- Vazba zápis → doklad (`journal_entry_document_links`) částku nenese, takže saldokonto
-- vyrovnání ručním zápisem uzná jen tehdy, když zápis vyrovnává jediný doklad svého druhu.
-- Interní doklad převzatý z jiného programu, který vyrovnává víc faktur (zápočet přes 395,
-- odpis, přeúčtování), proto nechával faktury v saldokontu otevřené, přestože je hlavní
-- kniha na nule. Tabulka nese, kolik z řádku zápisu patří kterému dokladu.
--
-- `kind`:
--   settlement = část řádku vyrovnává doklad (vydaná faktura: dal 311, přijatá: má dáti 321),
--   origin     = část řádku je předpisem ostatní pohledávky / závazku založené ze zůstatku
--                bez dokladu (záloha, přeplatek zaúčtovaný interním dokladem); položka
--                se znovu neúčtuje, předpis zůstává v původním zápisu.
-- `amount` je v Kč a vždy kladná, na straně řádku.
--
-- Řádek se drží přes (entry_id, line_no), účet a stranu v okamžiku rozpadu, ne přes id
-- řádku, stejně jako párování okruhů (migrace 1895): přeúčtování zápisu řádky smaže a vloží
-- znovu s novými id. Přeúčtování, které by řádek s rozpadem změnilo nebo odebralo, i smazání
-- takového zápisu blokuje JournalSettlementUseGuard; nesouhlasí-li přesto na daném pořadí
-- účet nebo strana, rozpad se nepočítá.
--
-- `released_on` = den, od kterého předpis ostatní položky ze zůstatku přestal platit (storno
-- položky). Saldokonto k dřívějšímu dni položku dál ukazuje.
--
-- Rozpad je metadata, ne účetní zápis: nemění deník, obraty ani zůstatky.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS journal_settlement_allocations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id INT UNSIGNED    NOT NULL,
  entry_id    BIGINT UNSIGNED NOT NULL,
  line_no     INT UNSIGNED    NOT NULL,
  account_id  BIGINT UNSIGNED NOT NULL COMMENT 'Účet řádku v okamžiku rozpadu (kontrola po přeúčtování)',
  side        ENUM('debit','credit') NOT NULL COMMENT 'Strana řádku v okamžiku rozpadu',
  kind        ENUM('settlement','origin') NOT NULL DEFAULT 'settlement',
  doc_type    ENUM('invoice','purchase_invoice','other_item') NOT NULL,
  doc_id      BIGINT UNSIGNED NOT NULL COMMENT 'ID dokladu dle doc_type (bez FK, polymorfní)',
  amount      DECIMAL(15,2)   NOT NULL COMMENT 'Kč, kladné, na straně řádku',
  released_on DATE            NULL COMMENT 'Předpis ostatní položky zrušen od tohoto dne (storno položky)',
  created_by  BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jsa_line_doc (supplier_id, entry_id, line_no, account_id, side, doc_type, doc_id),
  KEY ix_jsa_doc (supplier_id, doc_type, doc_id),
  KEY ix_jsa_account (supplier_id, account_id),
  KEY ix_jsa_created_by (created_by),
  CONSTRAINT fk_jsa_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_jsa_entry FOREIGN KEY (supplier_id, entry_id)
      REFERENCES journal_entries(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_jsa_account FOREIGN KEY (supplier_id, account_id)
      REFERENCES chart_of_accounts(supplier_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_jsa_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_jsa_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
