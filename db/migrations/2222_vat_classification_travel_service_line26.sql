-- MyÚčto.cz — cestovní služba § 89: vestavěný kód pro zbytek ceny na ř. 26
--
-- Migrace 1511 založila kódy 1c/2c pro přirážku (ř. 1/2, KH A.4/A.5 s kódem režimu 1).
-- Zbytek ceny ale neměl kam: metodika GFŘ č. j. 67743/21/7100-20116-050701, část
-- „vyplňování v daňovém přiznání", ho uvádí na ř. 26 (částka za plnění snížená o přirážku
-- včetně daně, u zálohy částka zálohy snížená o přirážku ze zálohy). Kód 26s na ř. 26
-- sice míří, ale znamená službu do 3. země, takže by doklad popisoval něco jiného.
--
-- Bez oddílu KH: do kontrolního hlášení vstupuje jen přirážka. kh_regime_code = '1' tu
-- do žádné věty KH nejde, označuje řádek jako součást zvláštního režimu, a proto ho
-- daňový doklad k záloze přebírá i při nulové sazbě (PaymentTaxDocumentCreator).
--
-- Idempotence: stejně jako 1511, unikátní index nechytá NULL supplier_id.

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, kh_regime_code, display_order)
SELECT NULL, '26c', 'Cestovní služba § 89 – cena snížená o přirážku včetně daně', 'sale', '26', NULL, 0.00, '1', 17
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '26c');
