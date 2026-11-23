-- Identita osoby platí od prvního vztahu, ne od vztahu, se kterým vznikla.
--
-- PROČ
--
-- Osoba založená s dohodou od března dostala historickou identitu (jméno,
-- datum narození) od března. Když jí pak přibyl vztah od února (import
-- docházky, další dohoda), únorové hlášení JMHZ nemělo jméno a kontrola
-- před během hlásila mezeru „identita je zapsaná až od …". Aplikace teď při
-- založení nebo posunu nástupu nejstarší verzi identity protáhne k prvnímu
-- nástupu (`PayrollEmploymentRepository::alignIdentityStart()`).
--
-- CO SE MĚNÍ
--
-- Stejná oprava pro data, která vznikla dřív: u osoby, jejíž nejstarší verze
-- identity začíná později než její první vztah (bez vztahů „nenastoupil"),
-- se začátek té verze posune na den prvního nástupu. Pozdější verze (změna
-- jména) ani identity, které nástupu předcházejí, se nemění. Opakované
-- spuštění už nic nenajde.

SET NAMES utf8mb4;

UPDATE payroll_person_identity_history identity
  JOIN (
    SELECT ranked.id, starts.first_start
      FROM (
        SELECT history.id,
               history.supplier_id,
               history.employee_id,
               history.effective_from,
               ROW_NUMBER() OVER (
                 PARTITION BY history.supplier_id, history.employee_id
                 ORDER BY history.effective_from, history.id
               ) AS position
          FROM payroll_person_identity_history history
      ) ranked
      JOIN (
        SELECT employment.supplier_id,
               employment.employee_id,
               MIN(COALESCE(employment.actual_start_date, employment.start_date)) AS first_start
          FROM payroll_employments employment
         WHERE employment.status <> 'no_show'
         GROUP BY employment.supplier_id, employment.employee_id
      ) starts
        ON starts.supplier_id = ranked.supplier_id
       AND starts.employee_id = ranked.employee_id
     WHERE ranked.position = 1
       AND starts.first_start IS NOT NULL
       AND starts.first_start < ranked.effective_from
  ) repair
    ON repair.id = identity.id
   SET identity.effective_from = repair.first_start,
       identity.row_version = identity.row_version + 1;
