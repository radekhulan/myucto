-- Otisk daňově relevantního účetnictví období uložený při finalizaci DPPO. Finalizované
-- přiznání, jehož otisk už neodpovídá deníku, se v průvodci uzávěrkou nepoužije jako známá
-- daň a detail přiznání upozorní, že se účetnictví po finalizaci změnilo.
-- NULL = přiznání finalizované před zavedením otisku (chování beze změny) nebo DPFO.

SET NAMES utf8mb4;

ALTER TABLE income_tax_returns
  ADD COLUMN IF NOT EXISTS books_fingerprint VARCHAR(80) NULL DEFAULT NULL AFTER finalized_by;
