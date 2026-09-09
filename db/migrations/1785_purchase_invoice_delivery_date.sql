-- MyÚčto.cz — audit VAT klasifikací 2026-08, nález M-7:
-- § 25 ZDPH šlo dopočítat jen z AI extrakce, protože datum dodání neměl kam uložit
--
-- Zákonné DUZP pořízení zboží z JČS je 15. den měsíce následujícího po měsíci pořízení
-- (dřív den vystavení dokladu, § 25 odst. 1). Vstupem je DATUM DODÁNÍ, které zahraniční
-- doklad nese jako „Leistungsdatum" / „date of supply" — a to na přijaté faktuře nemělo
-- vlastní sloupec. AI extraktor ho proto ukládal do `tax_date` a hned ho přepisoval
-- dopočteným DUZP; ISDOC, iDoklad, Fakturoid ani ruční zadání ho neuchovaly vůbec.
-- Bez uloženého data dodání nešlo § 25 ani dopočítat, ani zpětně ověřit.
--
-- Sloupec je EVIDENČNÍ: `tax_date` zůstává zákonným DUZP, ze kterého se odvozuje období
-- i kurz ČNB. Prázdné datum dodání se NEDOMÝŠLÍ — doklad si nechá zadané DUZP a dostane
-- varování (`eu_acquisition_delivery_date_missing`), protože z data vystavení se měsíc
-- pořízení určit nedá a tichý odhad by přesunul daň do jiného období.

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS delivery_date DATE NULL
        COMMENT 'Datum dodání / převzetí z dokladu (§ 25 ZDPH vstup pro DUZP); NULL = neuvedeno'
        AFTER tax_date;
