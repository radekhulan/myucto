-- Volitelná schopnost tokenu: vidět mzdovou evidenci PODÁNÍ v Dokumentech.
--
-- Dokumenty navázané na mzdová podání (doručenky datové schránky, protokoly
-- ČSSZ, odpovědi zdravotních pojišťoven) jsou pro API tokeny neviditelné —
-- `DocumentViewerResolver` je podmiňuje přihlášenou relací. Integrace, která
-- si takový soubor sama nahraje, ho pak ani nenajde.
--
-- Tenhle příznak to odemyká, ale VÝHRADNĚ pro evidenci podání. Zdravotní
-- údaje, exekuce, insolvence, cizinecká povolení a výplatní pásky zůstávají
-- session-only bez ohledu na něj: jsou to zvláštní kategorie osobních údajů
-- a dlouhodobé tajemství v konfiguráku integrace se na ně nehodí.
--
-- ⚠️ Výchozí hodnota je 0 a musí jí zůstat. Existující tokeny tím nic
-- nezískají a nový token schopnost dostane jen tehdy, když ji člověk při
-- vytváření výslovně zaškrtne — a to už je chráněné relací, heslem a MFA.

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS allow_payroll_submission_docs TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'token smí v Dokumentech vidět evidenci mzdových podání'
    AFTER scope;
