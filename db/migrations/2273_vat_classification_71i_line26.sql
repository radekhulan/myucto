-- MyÚčto.cz — § 71i: vestavěný kód pro tuzemské dodání osvobozené s nárokem na odpočet
--
-- Dodání knih včetně elektronických (§ 71i, „nulová sazba") je osvobozené plnění
-- s nárokem na odpočet. Patří na ř. 26 přiznání (pln_ost), do kontrolního hlášení nejde
-- a v koeficientu § 76 je v čitateli i jmenovateli. Výchozí číselník pro něj kód neměl:
-- kód 3 je osvobozené plnění BEZ nároku (ř. 50, zkreslil by koeficient), 26s je služba
-- s místem plnění mimo tuzemsko a 26c cestovní služba § 89. Na ř. 26 míří oba, ale doklad
-- by popisoval jiné plnění.
--
-- Pořadí hned za kódem 3: stejné display_order, číselník se řadí i podle kódu ('3' < '71i').
-- Výchozí klasifikace nové faktury kód 71i nevolí (nulová sazba se tam podle sazby nerozhoduje
-- a lookup podle sazby hledá jen přenesenou povinnost), 71i se volí výslovně.
--
-- Idempotence: stejně jako 2222, unikátní index nechytá NULL supplier_id. Vlastní kód firmy
-- se stejným jménem zůstává beze změny; aplikace u kolize upřednostní kód firmy.

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kh_regime_code, display_order)
SELECT NULL, '71i', 'Dodání v tuzemsku osvobozené s nárokem na odpočet – § 71i (knihy)', 'sale', '26', NULL, 0.00, 0, NULL, 12
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '71i');
