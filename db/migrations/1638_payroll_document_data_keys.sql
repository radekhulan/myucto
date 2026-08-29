-- MyÚčto.cz — W30 / C-05 + C-06: šifrované mzdové úložiště a krypto-výmaz.
--
-- PROČ (C-05) — jediné nešifrované mzdové úložiště
--
-- `PayrollPeriodExportStorage` ukládá měsíční exporty zašifrované
-- (`SecretEncryption::encryptFor`, formát `enc:v2:`), kdežto
-- `PayrollDocumentStorage` psal holé PDF na disk. Přitom právě tenhle adresář
-- nese výplatní pásky, mzdové listy, zápočtové listy a potvrzení o zdanitelných
-- příjmech — tedy rodná čísla, čísla účtů, srážky a exekuce — a drží je
-- **45 let** (§ 35a odst. 4 zákona č. 582/1991 Sb. u mzdových listů, § 96 odst. 3
-- zákoníku práce u evidence pracovní doby, § 38j odst. 6 ZDP u mzdových listů
-- pro daň). Zálohovaný nebo odcizený datový adresář byl tím pádem kompletní
-- mzdovou databází firmy ve strojově čitelné podobě. Čl. 32 odst. 1 písm. a)
-- GDPR přitom šifrování uvádí jako typické vhodné opatření právě pro tenhle
-- druh rizika.
--
-- PROČ (C-06) — nesplnitelný výmaz podle čl. 17 GDPR
--
-- Migrace 1231 udělala z `payroll_generated_documents` append-only tabulku
-- (triggery na UPDATE i DELETE) a výmazová větev
-- (`PayrollErasureProposalRepository::execute()`) na vydané dokumenty ani na
-- soubory na disku ZÁMĚRNĚ nesahá. Důsledek: kdokoli, komu se kdy vytiskla
-- výplatní páska, měl v systému osobní údaje, které z něj nešlo dostat pryč
-- žádnou cestou. `PayrollEmployeeDeletionRepository::BLOCKERS` proto úplný
-- výmaz takové osoby rovnou odmítá.
--
-- Neměnnost archivu je ale legitimní: § 35 odst. 3 zákona č. 563/1991 Sb.
-- (účetní záznam se opravuje, nemaže) a zákonné retenční lhůty výše. Čl. 17
-- odst. 3 písm. b) GDPR ostatně výmaz vylučuje tam, kde je zpracování nutné
-- pro splnění právní povinnosti — po dobu retence tedy mazat NESMÍME.
--
-- Konflikt se řeší **krypto-výmazem**: dokument se šifruje datovým klíčem
-- vázaným na konkrétní osobu, a výmaz zahodí ten klíč. Soubor i řádek zůstanou
-- beze změny (append-only archiv je nedotčený, integrita hashů sedí), ale
-- obsah je nevratně nečitelný. Recitál 26 GDPR mluví o tom, že za osobní údaj
-- se nepovažuje informace, u níž identifikace není rozumně proveditelná;
-- ciphertext AES-256-GCM bez klíče tenhle test splňuje. Tuhle konstrukci
-- výslovně zná i ENISA a EDPB jako „crypto-shredding".
--
-- MODEL KLÍČŮ (obálkové šifrování)
--
-- Master klíč aplikace (`cfg.app.secret_encryption_key`) NEJDE zahodit — je
-- společný pro celou instanci a jeho zahození by znečitelnilo všechno.
-- Zahodit tedy musí jít klíč PER SUBJEKT. Proto:
--
--   subjekt → datový klíč (DEK, 32 B) → zabalený master klíčem → tahle tabulka
--   dokument → zašifrovaný DEKem subjektu → soubor na disku
--
-- `subject_id` je `employee_scope_id` z `payroll_generated_documents`, tedy
-- `COALESCE(employee_id, 0)`. Nula = dokument firmy (rekapitulace, měsíční
-- balíček) — ten se nevymazává, protože není osobním údajem jedné osoby, a
-- jeho klíč proto zahodit nejde (hlídá to CHECK níž).
--
-- CO SE TÍM NEZAVŘE
--
-- Zálohy pořízené PŘED zahozením klíče obsahují i zabalený DEK. Krypto-výmaz
-- je proto účinný na produkční data okamžitě, na zálohy až vypršením jejich
-- retence — a to je vlastnost, kterou má i fyzické smazání souboru. Provozní
-- dokumentace to musí říkat nahlas.
--
-- ZPĚTNÁ KOMPATIBILITA
--
-- Tabulka je nová a prázdná. Už uložené PDF jsou v plaintextu na staré cestě
-- (`payroll-documents/sup-{id}/{hh}/{hash}`); čtecí cesta si je umí přečíst
-- dál (legacy větev v `PayrollDocumentStorage::readVerified()`), nové zápisy
-- už jdou zašifrované na cestu se subjektem. Žádný backfill se tu nedělá
-- schválně: přešifrovat archiv znamená přepsat soubory, na které se odkazuje
-- append-only tabulka, a to je operace pro samostatný obslužný skript
-- s ověřením hashů, ne pro migraci.
--
-- IDEMPOTENCE
--
-- `CREATE TABLE IF NOT EXISTS`; CHECK je součástí definice tabulky, takže vzor
-- „nejdřív zahodit, pak přidat" tu není potřeba. Migrace obsahuje JEN DDL —
-- runner čte příkazy nebufferovaně a jakýkoli SELECT (i schovaný v
-- `SET @x := (SELECT …)` nebo v `PREPARE`) by po sobě nechal nedočtený kurzor.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_document_data_keys (
  supplier_id    INT UNSIGNED NOT NULL,
  subject_id     BIGINT UNSIGNED NOT NULL
    COMMENT 'employee_scope_id: id osoby, 0 = dokument firmy (nemazatelný klíč)',
  wrapped_key    VARBINARY(512) NOT NULL
    COMMENT 'DEK zabalený master klíčem (enc:v2:…); po krypto-výmazu prázdný',
  key_algorithm  VARCHAR(32) NOT NULL DEFAULT 'aes-256-gcm',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  destroyed_at   DATETIME NULL
    COMMENT 'Okamžik krypto-výmazu — od té chvíle jsou dokumenty subjektu nečitelné',
  destroyed_by   BIGINT UNSIGNED NULL,
  destroy_reason VARCHAR(255) NULL,

  PRIMARY KEY (supplier_id, subject_id),
  KEY idx_payroll_document_key_destroyed (supplier_id, destroyed_at),
  CONSTRAINT fk_payroll_document_key_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_payroll_document_key_creator
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payroll_document_key_destroyer
    FOREIGN KEY (destroyed_by) REFERENCES users(id) ON DELETE SET NULL,
  -- Zahozený klíč musí mít prázdný obsah a naopak: půlka zahozeného stavu by
  -- znamenala, že se dokument pořád dá přečíst, ačkoli evidence tvrdí opak.
  CONSTRAINT chk_payroll_document_key_destroyed
    CHECK (
      (destroyed_at IS NULL AND OCTET_LENGTH(wrapped_key) > 0)
      OR (destroyed_at IS NOT NULL AND OCTET_LENGTH(wrapped_key) = 0)
    ),
  -- Klíč firemních dokumentů (subject_id = 0) zahodit nelze — nejsou to osobní
  -- údaje jedné osoby a jejich znečitelnění by nikomu neposloužilo.
  CONSTRAINT chk_payroll_document_key_company_kept
    CHECK (subject_id > 0 OR destroyed_at IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
