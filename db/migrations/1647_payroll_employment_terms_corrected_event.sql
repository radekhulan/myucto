-- MyÚčto.cz — oprava platné verze podmínek jako vlastní událost časové osy.
--
-- Karta vztahu uměla podmínky měnit jedinou cestou: založit NOVOU verzi
-- s vlastním datem účinnosti. Kdo si přišel spravit překlep v úvazku nebo
-- doplnit mzdovou účtárnu, kterou nikdo nevyplnil, tím do evidence zapsal,
-- že se podmínky k danému dni ZMĚNILY. Časová osa pak tvrdila změnu, která
-- se nestala, a mzdový běh počítal dvě období tam, kde je jedno.
--
-- Oprava platné verze proto přepisuje řádek podmínek na místě a na časovou
-- osu se zapisuje odlišenou událostí — jinak by v historii nešlo poznat
-- „od 1. 9. platí jiný úvazek" od „v srpnu jsme měli v úvazku překlep".
--
-- Sada hodnot se přepisuje celá (MariaDB neumí do ENUM přidat hodnotu
-- přírůstkově), takže je příkaz idempotentní — po druhém spuštění vypadá
-- sloupec stejně.

ALTER TABLE payroll_employment_events
  MODIFY COLUMN event_type
    ENUM('created','terms_changed','terms_corrected','status_changed','checklist_changed')
    NOT NULL;
