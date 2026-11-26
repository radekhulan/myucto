-- Převzaté souhrnné syntetiky skupin podle přílohy č. 3 vyhlášky 504/2002 Sb.
-- Používáme odpovídající detail příloh č. 1 a 2 ve znění od 1. 1. 2024.
-- Pro 510, 540 a 640 používáme obecné položky jako u 518, 549 a 649 podle § 26 odst. 1
-- písm. f), § 26 odst. 5 písm. g) a § 27 odst. 3 písm. f). Obdobně 230 a 240 používají obecný
-- řádek jako 231 a 249. 220 se stejně jako 221 vykazuje podle strany zůstatku.
-- Je-li k dispozici podrobnější syntetika nebo výjimka firmy, platí její vlastní řádek.
-- Skupiny bez odpovídajícího obecného řádku vyžadují rozlišení podle obsahu.
-- Mapu drž v souladu s katalogem Statement504Catalog.

SET @bs504 := (SELECT id FROM statement_versions
    WHERE framework = 'cz_504' AND statement_type = 'balance_sheet' AND version_code = 'vyhl504-2002/2016');
SET @is504 := (SELECT id FROM statement_versions
    WHERE framework = 'cz_504' AND statement_type = 'income_statement' AND version_code = 'vyhl504-2002/2016');

INSERT IGNORE INTO statement_account_map (version_id, row_code, account_prefix, target, balance_condition, sign) VALUES
(@bs504, 'UR.75', '220', 'gross', 'debit', 1),
(@bs504, 'US.39', '220', 'gross', 'credit', -1),
(@bs504, 'US.39', '230', 'gross', 'any', -1),
(@bs504, 'US.44', '240', 'gross', 'any', -1),
(@bs504, 'UR.80', '260', 'gross', 'any', 1),
(@bs504, 'UR.66', '350', 'gross', 'any', 1),
(@bs504, 'US.3', '900', 'gross', 'any', -1),
(@bs504, 'US.4', '910', 'gross', 'any', -1),
(@bs504, 'US.5', '920', 'gross', 'any', -1),
(@bs504, 'US.12', '940', 'gross', 'any', -1),
(@is504, 'UT.8', '510', 'gross', 'any', 1),
(@is504, 'UT.20', '530', 'gross', 'any', 1),
(@is504, 'UT.28', '540', 'gross', 'any', 1),
(@is504, 'UT.10', '560', 'gross', 'any', 1),
(@is504, 'UT.36', '580', 'gross', 'any', 1),
(@is504, 'UT.38', '590', 'gross', 'any', 1),
(@is504, 'UT.47', '600', 'gross', 'any', -1),
(@is504, 'UT.54', '640', 'gross', 'any', -1),
(@is504, 'UT.42', '690', 'gross', 'any', -1);
