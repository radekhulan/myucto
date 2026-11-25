-- MyÚčto.cz — tuzemský přenos u dodání elektřiny a plynu soustavou obchodníkovi (§ 92ea)
--
-- Kontrolní hlášení vede tato plnění pod vlastními kódy předmětu plnění: 19 dodání
-- elektřiny, 20 dodání plynu (VetaA1 dodavatel, VetaB1 příjemce). Globální číselník měl
-- jen stavební práce (4), odpad a šrot (5) a nemovitou věc (3), takže výkup elektřiny
-- z fotovoltaiky šel vykázat jen pod cizím kódem. Řádek přiznání i oddíl KH zůstávají
-- shodné s ostatními variantami (ř. 25 / ř. 10, A.1 / B.1), liší se jen kód předmětu plnění.
--
-- Idempotence: INSERT ... SELECT ... WHERE NOT EXISTS (unikátní index na (supplier_id, code)
-- nechytá NULL supplier_id).

SET NAMES utf8mb4;

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '25s19', 'Tuzemský přenos – dodání elektřiny soustavou obchodníkovi (§ 92ea) – dodavatel', 'sale', '25', 'A.1', NULL, 1, '19', 26
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '25s19');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '25s20', 'Tuzemský přenos – dodání plynu soustavou obchodníkovi (§ 92ea) – dodavatel', 'sale', '25', 'A.1', NULL, 1, '20', 27
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '25s20');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '5s19', 'Tuzemský přenos – dodání elektřiny soustavou obchodníkovi (§ 92ea) – příjemce', 'purchase', '10', 'B.1', 21.00, 1, '19', 53
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '5s19');

INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, kod_pred_pl, display_order)
SELECT NULL, '5s20', 'Tuzemský přenos – dodání plynu soustavou obchodníkovi (§ 92ea) – příjemce', 'purchase', '10', 'B.1', 21.00, 1, '20', 54
 WHERE NOT EXISTS (SELECT 1 FROM vat_classifications WHERE supplier_id IS NULL AND code = '5s20');
