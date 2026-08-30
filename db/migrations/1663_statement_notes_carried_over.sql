-- MyÚčto.cz — příloha v účetní závěrce: odkud text pochází.
--
-- Nový rok se u přílohy z velké části opakuje: sídlo, právní forma, způsoby
-- oceňování, odpisové metody. Přepisovat to každý rok ručně nemá smysl, ale
-- převzít loňský text a mlčet o tom je nebezpečné — příloha je součástí účetní
-- závěrky a loňská věta („v průběhu roku došlo k fúzi", „účetní jednotka nemá
-- závazky po splatnosti") může být letos nepravdivá. Účetní proto musí poznat,
-- které texty jsou převzaté a ještě je nikdo neprošel.
--
-- Sloupec drží ROK, ze kterého se text vzal. Vyplněný = převzato a dosud
-- nepotvrzeno; jakmile účetní sekci uloží, uloží se s NULL, tedy jako vlastní.
-- Auditní stopa se tím neztrácí: `updated_by` a `updated_at` zůstávají.

SET NAMES utf8mb4;

ALTER TABLE `statement_notes`
    ADD COLUMN IF NOT EXISTS `carried_over_from_year` SMALLINT UNSIGNED NULL
        COMMENT 'Rok, ze kterého byl text převzat; NULL = vlastní text účetní'
        AFTER `content`;
