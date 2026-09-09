-- Číselník českých státních a ostatních svátků (zákon č. 245/2000 Sb.).
--
-- PROČ TO PATŘÍ DO DATABÁZE. Svátek posouvá přes § 33 odst. 4 daňového řádu
-- (280/2009 Sb.) VŠECHNY lhůty podání — přiznání k DPH, kontrolní i souhrnné
-- hlášení, přehledy OSVČ, odvody ze mzdy, splatnosti. Dokud byl seznam
-- konstantou v PHP (`Service\Report\CzechWorkingDays::FIXED_HOLIDAYS`), znamenala
-- novela zákona o svátcích vydání nové verze aplikace. Sazba je roční skalár,
-- ale svátek je DATOVANÁ položka s platností — patří tedy vedle `cnb_repo_rates`
-- a `oss_member_state_rates`, ne do kódu.
--
-- POHYBLIVÉ SVÁTKY DRŽÍME JAKO PRAVIDLO, NE JAKO DATA. Velký pátek a Velikonoční
-- pondělí zákon pojmenovává, ale jejich datum neurčuje — plyne z církevního
-- výpočtu Velikonoc (gregoriánský computus), který je stabilní algoritmus, ne
-- rozhodnutí zákonodárce. Číselník proto nese jen `rule_type='easter'` a posun
-- ve dnech od Velikonoční neděle (−2 / +1); samotný výpočet zůstává v
-- `CzechWorkingDays::easterSunday()`. Alternativa „naseedovat konkrétní data na
-- sto let dopředu" by do tabulky legislativních faktů dala dopočítanou hodnotu
-- a tiše by došla na konci naseedovaného rozsahu.
--
-- CO SE SEEDUJE. Současný stav zákona promítnutý přes celý rozsah let, který
-- aplikace počítá (`valid_from = '1900-01-01'`). Historická rekonstrukce se
-- ZÁMĚRNĚ neseeduje, ačkoli by ji sloupce unesly: Velký pátek je svátkem až od
-- novely č. 359/2015 Sb. a 17. listopad se novelou č. 129/2019 Sb. přejmenoval
-- na „Den boje za svobodu a demokracii a Mezinárodní den studentstva". Důvod je
-- ten, že `CzechWorkingDays` musí umět odpovědět i BEZ naplněného číselníku
-- (fallback na konstantu v kódu, viz tamtéž) a odpověď obou cest musí být
-- STEJNÁ — jinak by výsledek závisel na tom, jestli je instalace naseedovaná.
-- Historická platnost je tedy věc, kterou si správce může doplnit; smysl
-- číselníku je BUDOUCÍ změna zákona, a ta je od teď jeden INSERT bez releasu.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS + INSERT ... WHERE NOT EXISTS nad
-- unikátním klíčem (code, valid_from).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS public_holidays (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL COMMENT 'Stabilní kód svátku (new_year, good_friday, …)',
  name          VARCHAR(190) NOT NULL COMMENT 'Název dle z. 245/2000 Sb.',
  rule_type     ENUM('fixed','easter') NOT NULL DEFAULT 'fixed'
                   COMMENT 'fixed = pevné MM-DD; easter = posun ode dne Velikonoční neděle',
  month_day     CHAR(5) NULL COMMENT 'MM-DD — povinné pro rule_type=fixed',
  easter_offset SMALLINT NULL COMMENT 'Posun ve dnech od Velikonoční neděle — povinné pro rule_type=easter',
  valid_from    DATE NOT NULL COMMENT 'První den, kdy svátek platí',
  valid_to      DATE NULL COMMENT 'Poslední den platnosti; NULL = platí dosud',
  note          VARCHAR(255) NULL COMMENT 'Poznámka / zdroj (novela, § zákona)',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_public_holidays (code, valid_from),
  KEY idx_public_holidays_validity (valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO public_holidays (code, name, rule_type, month_day, easter_offset, valid_from, valid_to, note)
SELECT s.code, s.name, s.rule_type, s.month_day, s.easter_offset, s.valid_from, NULL, s.note
FROM (
    SELECT 'new_year' code, 'Nový rok' name, 'fixed' rule_type, '01-01' month_day, NULL easter_offset, '1900-01-01' valid_from, 'z. 245/2000 Sb., § 1 a § 2' note
    UNION ALL SELECT 'good_friday', 'Velký pátek', 'easter', NULL, -2, '1900-01-01', 'z. 245/2000 Sb., § 2 (doplněn novelou č. 359/2015 Sb.)'
    UNION ALL SELECT 'easter_monday', 'Velikonoční pondělí', 'easter', NULL, 1, '1900-01-01', 'z. 245/2000 Sb., § 2'
    UNION ALL SELECT 'labour_day', 'Svátek práce', 'fixed', '05-01', NULL, '1900-01-01', 'z. 245/2000 Sb., § 2'
    UNION ALL SELECT 'victory_day', 'Den vítězství', 'fixed', '05-08', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1'
    UNION ALL SELECT 'cyril_methodius', 'Den slovanských věrozvěstů Cyrila a Metoděje', 'fixed', '07-05', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1'
    UNION ALL SELECT 'jan_hus', 'Den upálení mistra Jana Husa', 'fixed', '07-06', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1'
    UNION ALL SELECT 'statehood_day', 'Den české státnosti', 'fixed', '09-28', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1'
    UNION ALL SELECT 'independent_state_day', 'Den vzniku samostatného československého státu', 'fixed', '10-28', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1'
    UNION ALL SELECT 'freedom_democracy_day', 'Den boje za svobodu a demokracii', 'fixed', '11-17', NULL, '1900-01-01', 'z. 245/2000 Sb., § 1 (novelou č. 129/2019 Sb. doplněn „a Mezinárodní den studentstva")'
    UNION ALL SELECT 'christmas_eve', 'Štědrý den', 'fixed', '12-24', NULL, '1900-01-01', 'z. 245/2000 Sb., § 2'
    UNION ALL SELECT 'christmas_day', '1. svátek vánoční', 'fixed', '12-25', NULL, '1900-01-01', 'z. 245/2000 Sb., § 2'
    UNION ALL SELECT 'boxing_day', '2. svátek vánoční', 'fixed', '12-26', NULL, '1900-01-01', 'z. 245/2000 Sb., § 2'
) s
WHERE NOT EXISTS (
    SELECT 1 FROM public_holidays h
     WHERE h.code = s.code AND h.valid_from = s.valid_from
);
