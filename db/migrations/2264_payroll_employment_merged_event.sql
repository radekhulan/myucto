-- MyÚčto.cz: sloučení dvou pracovních vztahů téže osoby jako událost časové osy.
--
-- Import registrací ČSSZ založí osobě vztah s registrací a účetní jí ručně
-- založí druhý vztah, na který naimportuje docházku a mzdové vstupy. Sloučení
-- přesune data ručního vztahu do registrovaného a ruční vztah smaže. Na časové
-- ose cílového vztahu po tom zůstává odlišená událost, ze které je vidět,
-- odkud data přišla a které podmínky cíl převzal.
--
-- Sada hodnot se přepisuje celá (MariaDB neumí do ENUM přidat hodnotu
-- přírůstkově), takže je příkaz idempotentní.

ALTER TABLE payroll_employment_events
  MODIFY COLUMN event_type
    ENUM('created','terms_changed','terms_corrected','status_changed','checklist_changed','relation_type_changed','merged')
    NOT NULL;
