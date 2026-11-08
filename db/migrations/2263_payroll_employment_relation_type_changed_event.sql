-- MyÚčto.cz — oprava druhu pracovního vztahu jako vlastní událost časové osy.
--
-- Druh vztahu (pracovní poměr, zaměstnání malého rozsahu, DPP, DPČ) se dal
-- zadat jen při založení. Vztah založený omylem s jiným druhem, typicky
-- importem registrací, šel opravit jen smazáním, a to po importu docházky
-- a mzdových vstupů blokují navázaná data. Oprava druhu se na časovou osu
-- zapisuje odlišenou událostí, aby v historii zůstalo, že vztah vedený jako
-- pracovní poměr byl opraven na dohodu.
--
-- Sada hodnot se přepisuje celá (MariaDB neumí do ENUM přidat hodnotu
-- přírůstkově), takže je příkaz idempotentní.

ALTER TABLE payroll_employment_events
  MODIFY COLUMN event_type
    ENUM('created','terms_changed','terms_corrected','status_changed','checklist_changed','relation_type_changed')
    NOT NULL;
