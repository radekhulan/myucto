-- Chybějící řádky třetí a čtvrté úrovně rozvahy (vyhláška č. 500/2002 Sb., příloha 1).
--
-- Výkaz měl na 100 % detailních řádků namapované účty, ale část řádků samotných
-- v seedu 1012 chyběla. Účetní tak některé zůstatky viděla sloučené do nadřazené
-- položky a nemohla je vykázat samostatně:
--
--   P.B.1.      Rezerva na důchody a podobné závazky
--   P.C.I.4.    Závazky z obchodních vztahů (dlouhodobé)
--   P.C.I.5.    Dlouhodobé směnky k úhradě
--   P.C.I.7.    Závazky — podstatný vliv
--   P.C.I.9.1.–9.3.  rozpad ostatních dlouhodobých závazků
--   P.C.II.7.   Závazky — podstatný vliv
--   C.II.1.2., C.II.1.3.   dlouhodobé pohledávky za ovládanou osobou a s podstatným vlivem
--   C.II.1.5.1.–5.4.       rozpad ostatních dlouhodobých pohledávek
--
-- ROZLIŠENÍ DLOUHODOBÝ/KRÁTKODOBÝ: vyhláška dělí závazky a pohledávky podle
-- zbytkové splatnosti nad jeden rok, ne podle účtu — 321 je jeden účet pro obojí.
-- Ze zůstatku syntetiky to tedy poznat nelze. Držíme se konvence zavedené
-- migrací 1068: dlouhodobou část nese ANALYTIKA s písmenným sufixem (311D), a
-- proto přibývá `321D` a `322D`. Firma, která analytiku nepoužívá, má dlouhodobé
-- řádky nulové a v příloze v účetní závěrce se to vysloveně uvede — výkaz je
-- úplný a je vidět, co se nevykazuje, místo aby se tvrdilo nulové saldo.
--
-- Při té příležitosti se opravují tři chybná mapování ze seedu 1012:
--   472 a 362 (závazky — podstatný vliv) visely na řádcích vyhrazených ovládané
--       nebo ovládající osobě,
--   478 (dlouhodobé směnky k úhradě) viselo na P.C.I.9. „ostatní", přestože
--       vyhláška pro ně má vlastní řádek P.C.I.5.

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

SET @bs := (
    SELECT id FROM statement_versions
     WHERE statement_type = 'balance_sheet'
       AND version_code = 'vyhl500-2002/2024'
     LIMIT 1
);

-- Migrace se v testech pouští opakovaně. Posuny pozic idempotentní nejsou, takže
-- se dělají jen tehdy, když nové řádky ještě neexistují; vlastní INSERT IGNORE
-- se pak při druhém běhu nechytne a pořadí zůstane, jak bylo.
SET @fresh := (
    SELECT COUNT(*) = 0 FROM statement_rows
     WHERE version_id = @bs AND row_code = 'P.B.1.'
);

-- ── Analytiky pro dlouhodobou část (konvence 311D z migrace 1068) ────────────
INSERT IGNORE INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, x.code, x.name, x.account_type, x.normal_side, 0, p.id, 1, NULL
FROM supplier s
JOIN (
    SELECT '321D' code, 'Dlouhodobé závazky z obchodních vztahů' name, 'liability' account_type, 'credit' normal_side, '321' parent_code
    UNION ALL SELECT '322D', 'Dlouhodobé směnky k úhradě', 'liability', 'credit', '322'
) x
JOIN chart_of_accounts p ON p.supplier_id = s.id AND p.account_code = x.parent_code;

-- ── Aktiva: dlouhodobé pohledávky ───────────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'C.II.1.1.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'C.II.1.2.', 'C.II.1.', 'assets', 'Pohledávky — ovládaná nebo ovládající osoba', 4, @p + 1, 'detail', NULL),
(@bs, 'C.II.1.3.', 'C.II.1.', 'assets', 'Pohledávky — podstatný vliv',                 4, @p + 2, 'detail', NULL);

SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'C.II.1.5.');
UPDATE statement_rows SET position = position + 4
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
UPDATE statement_rows SET row_type = 'subtotal'
 WHERE version_id = @bs AND row_code = 'C.II.1.5.';
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'C.II.1.5.1.', 'C.II.1.5.', 'assets', 'Pohledávky za společníky',       5, @p + 1, 'detail', NULL),
(@bs, 'C.II.1.5.2.', 'C.II.1.5.', 'assets', 'Dlouhodobé poskytnuté zálohy',   5, @p + 2, 'detail', NULL),
(@bs, 'C.II.1.5.3.', 'C.II.1.5.', 'assets', 'Dohadné účty aktivní',           5, @p + 3, 'detail', NULL),
(@bs, 'C.II.1.5.4.', 'C.II.1.5.', 'assets', 'Jiné pohledávky',                5, @p + 4, 'detail', NULL);

-- ── Pasiva: rezervy ─────────────────────────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.B.');
UPDATE statement_rows SET position = position + 1
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.B.1.', 'P.B.', 'liabilities', 'Rezerva na důchody a podobné závazky', 3, @p + 1, 'detail', NULL);

-- ── Pasiva: dlouhodobé závazky ──────────────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.C.I.3.');
UPDATE statement_rows SET position = position + 2
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.C.I.4.', 'P.C.I.', 'liabilities', 'Závazky z obchodních vztahů', 3, @p + 1, 'detail', NULL),
(@bs, 'P.C.I.5.', 'P.C.I.', 'liabilities', 'Dlouhodobé směnky k úhradě',  3, @p + 2, 'detail', NULL);

SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.C.I.6.');
UPDATE statement_rows SET position = position + 1
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.C.I.7.', 'P.C.I.', 'liabilities', 'Závazky — podstatný vliv', 3, @p + 1, 'detail', NULL);

SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.C.I.9.');
UPDATE statement_rows SET position = position + 3
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
UPDATE statement_rows SET row_type = 'subtotal', label = 'Závazky ostatní'
 WHERE version_id = @bs AND row_code = 'P.C.I.9.';
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.C.I.9.1.', 'P.C.I.9.', 'liabilities', 'Závazky ke společníkům', 4, @p + 1, 'detail', NULL),
(@bs, 'P.C.I.9.2.', 'P.C.I.9.', 'liabilities', 'Dohadné účty pasivní',   4, @p + 2, 'detail', NULL),
(@bs, 'P.C.I.9.3.', 'P.C.I.9.', 'liabilities', 'Jiné závazky',           4, @p + 3, 'detail', NULL);

-- ── Pasiva: krátkodobé závazky ──────────────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.C.II.6.');
UPDATE statement_rows SET position = position + 1
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.C.II.7.', 'P.C.II.', 'liabilities', 'Závazky — podstatný vliv', 3, @p + 1, 'detail', NULL);

-- ── Mapování účtů na nové řádky ─────────────────────────────────────────────
-- Mapuje se JEN tam, kde pro řádek existuje vlastní účet. Dlouhodobé varianty
-- pohledávek (351, 352, 354, 314, 388, 378) vlastní syntetiku nemají a jsou už
-- namapované na krátkodobé řádky; kdyby se tytéž prefixy přidaly i na dlouhodobé,
-- mapovač je má stejně dlouhé a započítal by částku do OBOU řádků. Dlouhodobé
-- řádky proto zůstávají prázdné, tak jak bylo rozhodnuto, a naplní se až
-- analytikou s písmenným sufixem (konvence 311D).
INSERT IGNORE INTO statement_account_map
    (version_id, row_code, account_prefix, target, balance_condition, sign)
VALUES
(@bs, 'P.C.I.4.',   '321D', 'gross', 'any', 1),
(@bs, 'P.C.I.5.',   '322D', 'gross', 'any', 1),
(@bs, 'P.C.I.5.',   '478',  'gross', 'any', 1),
(@bs, 'P.C.I.7.',   '472',  'gross', 'any', 1),
(@bs, 'P.C.I.9.3.', '474',  'gross', 'any', 1),
(@bs, 'P.C.I.9.3.', '479',  'gross', 'any', 1),
(@bs, 'P.C.II.7.',  '362',  'gross', 'any', 1);

-- Oprava tří mapování ze seedu 1012. Všechna tři vedla částku na řádek, který
-- vyhláška vyhrazuje jinému vztahu, takže výkaz tvrdil něco jiného, než co se
-- stalo:
--   472 (dlouhodobé závazky — podstatný vliv) viselo na P.C.I.6., která patří
--       ovládané nebo ovládající osobě,
--   362 (krátkodobé závazky — podstatný vliv) totéž na P.C.II.6.,
--   478 (dlouhodobé směnky k úhradě) viselo na P.C.I.9. „ostatní", přestože
--       vyhláška pro ně má vlastní řádek P.C.I.5.
DELETE FROM statement_account_map
 WHERE version_id = @bs
   AND ((row_code = 'P.C.I.6.'  AND account_prefix = '472')
     OR (row_code = 'P.C.II.6.' AND account_prefix = '362'));

-- `P.C.I.9.` je nově mezisoučet, takže na něm účty viset nesmí — jinak by se
-- částka započítala dvakrát, jednou přes účet a podruhé přes součet podřádků.
-- Účty 474 a 479 přebírá podřádek 9.3 „Jiné závazky"; pro 9.1 (závazky ke
-- společníkům) ani 9.2 (dohadné účty pasivní) dlouhodobá syntetika neexistuje,
-- takže zůstávají prázdné.
DELETE FROM statement_account_map
 WHERE version_id = @bs AND row_code = 'P.C.I.9.';
