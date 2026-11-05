-- MyÚčto.cz — počáteční stav valutové pokladny ve valutě
--
-- Valutová pokladna vede duální zůstatek: korunový z deníku a valutový ze součtu dokladů
-- (`cash_documents.amount_foreign`). Peníze, které v pokladně ležely před prvním dokladem
-- v MyÚčtu (převod z jiného systému od určitého roku, založení pokladny uprostřed
-- provozu), valutový zůstatek neznal a pokladna ukazovala záporný stav.
--
-- `opening_foreign_amount` = stav ve valutě na začátku dne `opening_foreign_date`.
-- Zůstatek k datu = počáteční stav + doklady od toho dne; starší doklady jsou už
-- v počátečním stavu obsažené. Obě hodnoty NULL = dosavadní chování (jen doklady).

SET NAMES utf8mb4;

ALTER TABLE cash_registers
  ADD COLUMN IF NOT EXISTS opening_foreign_amount DECIMAL(15,2) NULL DEFAULT NULL
    COMMENT 'Počáteční stav valutové pokladny ve valutě k opening_foreign_date; NULL = bez počátečního stavu'
    AFTER own_series,
  ADD COLUMN IF NOT EXISTS opening_foreign_date DATE NULL DEFAULT NULL
    COMMENT 'Den, k jehož začátku platí opening_foreign_amount'
    AFTER opening_foreign_amount;
