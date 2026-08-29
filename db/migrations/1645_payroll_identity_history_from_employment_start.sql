-- MyÚčto.cz — první verze identity osoby platí od NÁSTUPU, ne ode dne zápisu.
--
-- `payroll_person_identity_history` se plní z karty osoby a účinnost první verze
-- se dosud brala z formuláře, tedy prakticky vždy dnešním dnem. U člověka, který
-- nastoupil dřív, než firma přešla do MyÚčta, tím vznikl záznam tvrdící, že před
-- importem neexistoval.
--
-- Není to kosmetika: prvotní registrace do registru pojištěnců (REGZEC A1) se
-- podává k datu nástupu a `PayrollRegistrationIdentityService` k němu identitu
-- nenajde — skončí na `K rozhodnému datu chybí historická identita osoby.`
-- Potká to KAŽDÉHO, kdo přejde z jiného systému, a bez opravy je u něj prvotní
-- registrace trvale nepodatelná.
--
-- Opravuje se jen kombinace, která je prokazatelně špatná: NEJSTARŠÍ verze
-- identity začíná POZDĚJI než nejstarší pracovní vztah téže osoby. O jméně ani
-- občanství se nic nedomýšlí, mění se výhradně datum, odkdy uložená verze platí.
-- Novější verze (skutečné změny jména apod.) zůstávají beze změny.

SET NAMES utf8mb4;

UPDATE payroll_person_identity_history AS target
  JOIN (
        SELECT ih.supplier_id,
               ih.employee_id,
               MIN(ih.effective_from) AS first_identity_from,
               MIN(e.start_date)      AS first_employment_from
          FROM payroll_person_identity_history ih
          JOIN payroll_employments e
            ON e.supplier_id = ih.supplier_id
           AND e.employee_id = ih.employee_id
         GROUP BY ih.supplier_id, ih.employee_id
        HAVING MIN(ih.effective_from) > MIN(e.start_date)
       ) AS broken
    ON broken.supplier_id = target.supplier_id
   AND broken.employee_id = target.employee_id
   AND target.effective_from = broken.first_identity_from
   SET target.effective_from = broken.first_employment_from;
