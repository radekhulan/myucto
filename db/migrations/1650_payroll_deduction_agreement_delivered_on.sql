-- MyÚčto.cz — den doručení dohody o srážkách ze mzdy plátci mzdy (nález E-03).
--
-- Věřitel nabývá práva na výplatu srážek proti plátci mzdy okamžikem, kdy mu
-- byla dohoda doručena (§ 2045 odst. 2 občanského zákoníku). Dohoda se přitom
-- provádí jen za podmínek výkonu rozhodnutí srážkami ze mzdy (§ 148 odst. 2
-- zákoníku práce), takže se pořadí řídí § 280 odst. 5 o. s. ř. — dnem doručení
-- plátci mzdy, společně s exekučními pohledávkami.
--
-- Do téhle migrace dohoda den doručení vůbec neevidovala: pořadí mezi dohodami
-- rozhodovalo ručně nastavené `priority_no` a proti exekucím se dohoda nemohla
-- prosadit nikdy, protože dostala až kapacitu, kterou exekuce nechala.
--
-- Sloupec je NULLABLE ZÁMĚRNĚ: dohody zaevidované dřív den doručení nemají a
-- dopočítat se nedá. Výpočet je čte fail-closed — dohoda bez data se řadí AŽ ZA
-- všechny pohledávky se známým dnem doručení, tedy přesně jako dosud. Doplnění
-- data je vědomý krok účetní, ne migrace.

SET NAMES utf8mb4;

ALTER TABLE payroll_deduction_agreements
  ADD COLUMN IF NOT EXISTS delivered_on DATE NULL DEFAULT NULL AFTER valid_to;

ALTER TABLE payroll_deduction_agreement_versions
  ADD COLUMN IF NOT EXISTS delivered_on DATE NULL DEFAULT NULL AFTER valid_to;
