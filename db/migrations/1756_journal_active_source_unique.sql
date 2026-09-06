-- MyÚčto.cz — idempotence deníku se váže na AKTIVNÍ zápis dokladu, ne na jakýkoli.
--
-- Migrace 1007 zavedla UNIQUE (supplier_id, source_type, source_id), aby jeden
-- doklad nešel zaúčtovat dvakrát. To ale zároveň znemožnilo to, co §35 ZoÚ jako
-- JEDINOU opravu zaúčtování v uzavřeném/zamčeném období předepisuje a co doslova
-- říká i docblock PostingService::rewriteExisting — „opravu zaúčtuj novým zápisem":
-- po stornu nebylo kam nový zápis vložit, protože klíč držel stornovaný originál.
-- Přeúčtování zamčeného dokladu tak končilo chybou `entry_reversed` a účetní
-- neměla jinou cestu než ruční zápis bez vazby na doklad.
--
-- Klíč se proto počítá z virtuálního sloupce `active_source_id`, který je NULL
-- u stornovaného zápisu. Ochrana proti dvojímu zaúčtování zůstává STEJNĚ TVRDÁ:
-- aktivních (nestornovaných) zápisů může mít doklad pořád nejvýš jeden. Storno
-- samo má `source_id` NULL už od 1007, takže se do klíče nikdy nepletlo.
--
-- journal_entries je system-versioned (1029) → ALTER historie vyžaduje
-- `system_versioning_alter_history = KEEP` (týž vzor jako 1046/1099/1100).
-- Virtuální sloupec se nepočítá do řádku, takže historii jen dopočítá.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS + DROP INDEX IF EXISTS +
-- CREATE UNIQUE INDEX IF NOT EXISTS.

SET NAMES utf8mb4;
SET @@system_versioning_alter_history = KEEP;

ALTER TABLE journal_entries
  ADD COLUMN IF NOT EXISTS active_source_id BIGINT UNSIGNED
    AS (IF(reversed_by IS NULL, source_id, NULL)) VIRTUAL
    COMMENT 'source_id, dokud zápis není stornovaný — nosič idempotence zaúčtování';

CREATE UNIQUE INDEX IF NOT EXISTS uq_je_supplier_active_source
  ON journal_entries (supplier_id, source_type, active_source_id);

ALTER TABLE journal_entries DROP INDEX IF EXISTS uq_je_supplier_source;

-- Vyhledávací index nad SUROVÝM source_id: nový unikát ho nenahradí (indexuje
-- `active_source_id`), a `findBySource`/`listBySourceWithLines`/reporty se ptají
-- právě na `source_id`. Bez něj by se z lookupu stal fullscan deníku.
CREATE INDEX IF NOT EXISTS idx_je_supplier_source
  ON journal_entries (supplier_id, source_type, source_id);
