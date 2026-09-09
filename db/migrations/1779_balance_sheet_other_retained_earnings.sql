-- Rozvaha — chybějící řádek P.A.IV.2. „Jiný výsledek hospodaření minulých let (±)"
-- (vyhláška č. 500/2002 Sb., příloha 1; číselník MF ČR tabulka 24810 „Rozvaha-pasiva", ř. 21).
--
-- Migrace 1664 doplnila sedm chybějících řádků třetí a čtvrté úrovně, `P.A.IV.2.`
-- mezi nimi nebyl a v seedu 1012 také ne (seed má jen `P.A.IV.` a `P.A.IV.1.`).
--
-- CO SE TÍM OPRAVUJE. Účet 426 „Jiný výsledek hospodaření minulých let" nebyl
-- vůbec ve směrné osnově ({@see ChartOfAccountsTemplate}) a žádný řádek výkazu
-- na něj neukazoval. Nešlo tedy o „schování do nadřazeného řádku": zůstatek
-- účtu, který si firma založila ručně, spadl do `unmapped_accounts` a v rozvaze
-- nebyl VŮBEC — tedy PASIVA ≠ AKTIVA o celý ten zůstatek. V příloze účetní
-- závěrky u DPPO řádek 21 chyběl a součet A.IV. = A.IV.1. + A.IV.2. neměl co
-- sečíst.
--
-- ÚČETNÍ ROZHODNUTÍ — které účty ten řádek plní. Právě a jen 426. § 15a
-- vyhlášky 500/2002 Sb. vyhrazuje „Jiný výsledek hospodaření minulých let"
-- třem věcem: rozdílům ze změny účetní metody, opravám nesprávností minulých
-- období a odloženým daním z těchto titulů. Ty se v české účtové osnově vedou
-- na samostatné syntetice 426 přesně proto, aby se NEmíchaly s 428/429
-- (nerozdělený zisk / neuhrazená ztráta minulých let), které jsou výsledkem
-- rozdělení zisku, ne opravou. Přemapování 428/429 by tedy bylo věcně chybné a
-- nedělá se; dvojí započtení nehrozí, protože 426 dnes nevisí na žádném řádku
-- (ověřeno dotazem nad `statement_account_map`, prefixy `42*` = 421/423/427/
-- 428/429 a žádný kratší prefix, který by 426 zachytil).
--
-- NORMAL_SIDE. Účet je saldní (`NULL`), stejně jako 414/419/431: oprava
-- minulého období může vyjít na MD i D a stranu určuje znaménko zůstatku, ne
-- definice. Znaménko se ve výkazu nese přirozeně (`sign = 1`, jako u 429).
--
-- Idempotence: INSERT IGNORE nad unikátními klíči (uq_coa_supplier_code,
-- uq_sr_version_code, uq_sam). Posun pozic idempotentní není, a proto se — jako
-- v migraci 1664 — dělá jen při prvním běhu (`@fresh`).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

SET @bs := (
    SELECT id FROM statement_versions
     WHERE statement_type = 'balance_sheet'
       AND version_code = 'vyhl500-2002/2024'
     LIMIT 1
);

SET @fresh := (
    SELECT COUNT(*) = 0 FROM statement_rows
     WHERE version_id = @bs AND row_code = 'P.A.IV.2.'
);

-- ── Účet 426 do osnovy již existujících firem ───────────────────────────────
-- Nové firmy ho dostanou ze šablony (ChartOfAccountsTemplate); tenhle INSERT je
-- pro ty, které osnovu naseedovaly dřív.
INSERT IGNORE INTO chart_of_accounts
    (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
SELECT s.id, '426', 'Jiný výsledek hospodaření minulých let', 'equity', NULL, 1, NULL, 1, NULL
FROM supplier s
WHERE EXISTS (
    SELECT 1 FROM chart_of_accounts c
     WHERE c.supplier_id = s.id AND c.account_code = '428'
);

-- ── Řádek P.A.IV.2. ─────────────────────────────────────────────────────────
SET @p := (SELECT position FROM statement_rows WHERE version_id = @bs AND row_code = 'P.A.IV.1.');
UPDATE statement_rows SET position = position + 1
 WHERE version_id = @bs AND position > @p AND @fresh = 1;
INSERT IGNORE INTO statement_rows
    (version_id, row_code, parent_row_code, section, label, level, position, row_type, calc_key)
VALUES
(@bs, 'P.A.IV.2.', 'P.A.IV.', 'liabilities', 'Jiný výsledek hospodaření minulých let (±)', 3, @p + 1, 'detail', NULL);

-- ── Mapování účtu ───────────────────────────────────────────────────────────
INSERT IGNORE INTO statement_account_map
    (version_id, row_code, account_prefix, target, balance_condition, sign)
VALUES
(@bs, 'P.A.IV.2.', '426', 'gross', 'any', 1);
