-- MyÚčto.cz — první adresa osoby platí od NÁSTUPU, ne ode dne zápisu.
--
-- Táž vada jako u historie identity (migrace 1645), jen o tabulku vedle.
-- `payroll_person_addresses` se plní z karty osoby a účinnost první verze se
-- brala z formuláře, tedy prakticky vždy dnešním dnem. U člověka, který
-- nastoupil dřív, než firma přešla do MyÚčta, tím vznikl záznam tvrdící, že
-- před importem nikde nebydlel.
--
-- Projeví se to tam, kde se adresa čte K ROZHODNÉMU DNI: návrh profilu REGZEC
-- A1 (`PayrollRegistrationA1DraftBuilder`) se podává k datu nástupu a hlásil
-- „osoba nemá k rozhodnému dni evidovanou adresu trvalého pobytu", přestože
-- adresa v aplikaci uložená byla. Účetní tak vidí prázdný formulář u údaje,
-- který zadala.
--
-- Opravuje se jen kombinace, která je prokazatelně špatná: NEJSTARŠÍ verze
-- daného druhu adresy začíná POZDĚJI než nejstarší pracovní vztah téže osoby.
-- Adresa samotná se nemění, jen datum, odkdy uložená verze platí. Novější
-- verze (skutečná stěhování) zůstávají beze změny.

SET NAMES utf8mb4;

UPDATE payroll_person_addresses AS target
  JOIN (
        SELECT a.supplier_id,
               a.employee_id,
               a.address_type,
               MIN(a.effective_from) AS first_address_from,
               MIN(e.start_date)     AS first_employment_from
          FROM payroll_person_addresses a
          JOIN payroll_employments e
            ON e.supplier_id = a.supplier_id
           AND e.employee_id = a.employee_id
         GROUP BY a.supplier_id, a.employee_id, a.address_type
        HAVING MIN(a.effective_from) > MIN(e.start_date)
       ) AS broken
    ON broken.supplier_id = target.supplier_id
   AND broken.employee_id = target.employee_id
   AND broken.address_type = target.address_type
   AND target.effective_from = broken.first_address_from
   SET target.effective_from = broken.first_employment_from;
