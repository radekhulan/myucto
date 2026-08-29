-- MyÚčto.cz — W30 / C-07: odkladná lhůta a odvolání u výmazu osobních údajů.
--
-- PROČ
--
-- Návrh výmazu dnes schvaluje i provádí týž člověk: `payroll.erasure` kryje
-- propose, approve i execute a `PayrollErasureProposalRepository::approve()`
-- nijak nezkoumá, kdo návrh sestavil. Jedno omylem potvrzené tlačítko tedy
-- nevratně smaže osobní údaje, které nikdo zpátky nezadá — zálohy jsou jediná
-- cesta zpět a ta je v praxi horší než sám omyl.
--
-- PROČ NE PRAVIDLO ČTYŘ OČÍ
--
-- Čtyři oči se v tomhle produktu ZÁMĚRNĚ nezavádějí. Cílový zákazník je často
-- firma s JEDNOU účetní; kdyby výmaz vyžadoval druhého schvalovatele, buď by
-- ho nešlo provést vůbec, nebo by si účetní založila druhý účet — a kontrola
-- by se změnila v obřad, který nic nehlídá. Modul má tohle pravidlo napříč
-- mzdami (viz ActionBar konvence i mzdový workflow) a tenhle nález ho neruší.
--
-- CO SE ZAVÁDÍ MÍSTO TOHO
--
-- Nevratnost se nekryje druhým člověkem, ale ČASEM a ODVOLATELNOSTÍ. Schválení
-- otevře odkladnou lhůtu; teprve po jejím uplynutí jde návrh provést a po celou
-- dobu ho jde jedním klikem vrátit do stavu „ke schválení". Omyl tak má okno,
-- ve kterém se dá napravit bez zálohy, a legitimní výmaz se nezastaví — jen se
-- o tři dny odloží, což u lhůty, která zrovna uběhla po 30 nebo 45 letech,
-- nikoho nebolí.
--
--   `executable_from` = NOW() + 72 h, schvaluje-li týž člověk, který navrhl
--   `executable_from` = NOW(),        schvaluje-li NĚKDO JINÝ
--
-- Druhý řádek je odměna, ne povinnost: kde druhá osoba je, tam se nic
-- neodkládá, protože kontrola už proběhla. Kde není, zaskočí za ni čas.
--
-- Právně to sedí na obě strany: čl. 17 odst. 1 GDPR dává subjektu právo na
-- výmaz „bez zbytečného odkladu", ne okamžitě, a čl. 12 odst. 3 na vyřízení
-- žádosti počítá s lhůtou jednoho měsíce — 72 hodin se do ní vejde s velkou
-- rezervou. Naopak čl. 5 odst. 1 písm. d) (přesnost) i čl. 32 (integrita
-- a důvěrnost) mluví pro to, aby nevratná operace měla pojistku proti omylu.
--
-- ODVOLÁNÍ
--
-- `revoked_by` / `revoked_at` drží stopu po odvolaném schválení. Návrh se vrací
-- do `pending`, takže ho jde schválit znovu (a lhůta začne běžet znovu) —
-- proto ve stavovém ENUMu žádná nová hodnota nepřibývá. Stopa zůstává, aby
-- v auditu bylo vidět „schváleno a vzato zpět", ne jen „nic se nestalo".
--
-- ZPĚTNÁ KOMPATIBILITA
--
-- Všechny tři sloupce jsou NULL bez DEFAULT, takže existující řádky se nemění.
-- `executable_from IS NULL` u už schváleného návrhu čte aplikace jako „lhůta
-- doběhla" — návrhy schválené před touhle migrací tedy neuvázly.
--
-- IDEMPOTENCE
--
-- `ADD COLUMN IF NOT EXISTS` umí MariaDB; CHECK se řeší vzorem „nejdřív
-- zahodit, pak přidat", protože `ADD CONSTRAINT IF NOT EXISTS` u CHECK neumí.
-- FK se přidávají jen tehdy, když ještě nejsou — proto samostatné ALTER
-- příkazy s IF NOT EXISTS na sloupcích a DROP/ADD na CHECKu. Migrace obsahuje
-- JEN DDL: runner čte příkazy nebufferovaně a jakýkoli SELECT (i schovaný
-- v `SET @x := (SELECT …)` nebo v `PREPARE`) by po sobě nechal nedočtený
-- kurzor a další příkaz by spadl.

SET NAMES utf8mb4;

ALTER TABLE payroll_erasure_proposals
  ADD COLUMN IF NOT EXISTS executable_from DATETIME NULL
    COMMENT 'Konec odkladné lhůty — dřív výmaz provést nelze (NULL = doběhla)',
  ADD COLUMN IF NOT EXISTS revoked_by BIGINT UNSIGNED NULL
    COMMENT 'Kdo vzal schválení zpět během odkladné lhůty',
  ADD COLUMN IF NOT EXISTS revoked_at DATETIME NULL;

ALTER TABLE payroll_erasure_proposals
  DROP CONSTRAINT IF EXISTS chk_payroll_erasure_revocation;

ALTER TABLE payroll_erasure_proposals
  ADD CONSTRAINT chk_payroll_erasure_revocation
  CHECK (
    (revoked_at IS NULL AND revoked_by IS NULL)
    OR (revoked_at IS NOT NULL)
  );

ALTER TABLE payroll_erasure_proposals
  ADD KEY IF NOT EXISTS idx_payroll_erasure_executable
    (supplier_id, status, executable_from);
