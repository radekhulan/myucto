-- Osvobozený příjem podle § 6 odst. 9 ZDP patří do zúčtovaného příjmu JMHZ.
--
-- Zúčtovaný příjem celkem (10286) je podle datového slovníku nadmnožinou
-- osvobozených příjmů (10289); kontrola 97 ČSSZ zní přímo „(10289) =< (10286)".
-- Plnění osvobozené podle § 6 odst. 9 je příjmem ze závislé činnosti, jen se
-- nezdaňuje, takže se do úhrnu započítává a v hlášení se vykáže jako osvobozená
-- část. Příspěvek na stravování a přechodné ubytování měly `jmhz_treatment`
-- nastavené na `excluded`, takže z hlášení vypadly úplně a úhrn zúčtovaného
-- příjmu byl o ně nižší.
--
-- Odlišné od § 6 odst. 7 (cestovní náhrada do limitu), kde plnění příjmem VŮBEC
-- NENÍ — ta složka zůstává `excluded` správně a tahle migrace na ni nesahá.
--
-- Oprava na místě ve verzi 2026-01-01, ne nová verze: klasifikace byla od
-- začátku napsaná chybně, právo se nezměnilo. Stejně to řeší migrace 1480, 1552
-- a 1610.

SET NAMES utf8mb4;

UPDATE payroll_component_definitions
   SET jmhz_treatment = 'included',
       row_version = row_version + 1
 WHERE code IN ('PRISPEVEK_STRAVOVANI', 'PRECHODNE_UBYTOVANI')
   AND valid_from = '2026-01-01'
   AND tax_treatment = 'exempt'
   AND exemption_basis = 'periodic_benefit_limit'
   AND jmhz_treatment = 'excluded';
