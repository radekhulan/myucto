<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Rozdělení částky řádku deníku podle rozpadu mezi víc hodnot jednoho typu dimenze
 * (`journal_entry_line_dimension_splits`). Jediné místo, kde se podíl převádí na
 * haléře: výsledovka po dimenzi i filtr výkazů na hodnotu z něj berou tatáž čísla,
 * takže řádek hodnoty ve výsledovce po dimenzi sedí na výkaz filtrovaný na tutéž
 * hodnotu a součet přes hodnoty sedí na částku řádku.
 *
 * Pravidlo je stejné jako {@see DimensionStamper::distributeCents()}: každý podíl se
 * zaokrouhlí na haléře. Kladný zbytek dostane největší podíl, záporný se od podílů
 * odečítá v pořadí velikosti a id jen do výše jejich zaokrouhlených dílů. Počítá se
 * v SQL nad DECIMAL, tedy přesně, bez binární plovoucí čárky.
 *
 * Částka je vždy nezáporná (`journal_entry_lines.amount`); strana zůstává na řádku.
 * Červené storno obrací znaménko až nad výsledným dílem, takže rozdělení je
 * přesným zrcadlem kladné částky.
 */
final class DimensionSplitAllocation
{
    /**
     * Odvozená tabulka `(line_id, value_id, amount)` s dílem každého řádku s rozpadem
     * typu `$typeId`. Omezení `$valueIds` vybere jen řádky, jejichž rozpad aspoň jednu
     * z hodnot obsahuje; díly se ale vždy počítají z CELÉHO rozpadu řádku, jinak by
     * zbytek po zaokrouhlení padl jinam než ve výsledovce po dimenzi.
     *
     * @param list<int>|null $valueIds null = všechny řádky s rozpadem typu
     * @param int|null $supplierId jen řádky firmy (u globálního typu sdílí hodnoty víc firem)
     * @param array{0:?string,1:string}|null $entryDates jen zaúčtované řádky se zápisem
     *        v intervalu [od, do] (od null = od počátku historie); vyžaduje firmu, aby dotaz
     *        šel po indexu data zápisu
     * @return array{0:string,1:list<int|string>}
     */
    public static function partsSql(
        int $typeId,
        ?array $valueIds = null,
        ?int $supplierId = null,
        ?array $entryDates = null,
    ): array {
        $params = [$typeId];
        $restrict = '';
        $entryJoin = '';
        if ($supplierId !== null) {
            $restrict .= ' AND s.supplier_id = ?';
            $params[] = $supplierId;
        }
        if ($entryDates !== null) {
            if ($supplierId === null) {
                throw new \InvalidArgumentException('Omezení na období rozpadu vyžaduje firmu.');
            }
            $entryJoin = ' JOIN journal_entries se ON se.id = sl.entry_id AND se.supplier_id = sl.supplier_id';
            $restrict .= ' AND se.supplier_id = ? AND se.posted_at IS NOT NULL';
            $params[] = $supplierId;
            if ($entryDates[0] !== null) {
                $restrict .= ' AND se.entry_date >= ?';
                $params[] = $entryDates[0];
            }
            $restrict .= ' AND se.entry_date <= ?';
            $params[] = $entryDates[1];
        }
        if ($valueIds !== null) {
            if ($valueIds === []) {
                return ['SELECT NULL AS line_id, NULL AS value_id, 0 AS amount FROM DUAL WHERE 1 = 0', []];
            }
            $marks = implode(',', array_fill(0, count($valueIds), '?'));
            $restrict .= " AND s.line_id IN (SELECT r.line_id FROM journal_entry_line_dimension_splits r
                                              WHERE r.dimension_type_id = ? AND r.dimension_value_id IN ({$marks}))";
            array_push($params, $typeId, ...$valueIds);
        }
        $sql = "SELECT a.line_id, a.value_id,
                       a.part + CASE WHEN a.residual > 0 AND a.rn = 1 THEN a.residual
                                     WHEN a.residual < 0
                                     THEN -LEAST(a.part, GREATEST(-a.residual - a.prior_part, 0))
                                     ELSE 0 END AS amount
                  FROM (SELECT q.*,
                               q.amount - SUM(q.part) OVER (PARTITION BY q.line_id) AS residual,
                               COALESCE(SUM(q.part) OVER (
                                   PARTITION BY q.line_id ORDER BY q.rn
                                   ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                               ), 0) AS prior_part
                          FROM (SELECT s.line_id, s.dimension_value_id AS value_id, sl.amount,
                                       ROUND(sl.amount * s.share / SUM(s.share) OVER (PARTITION BY s.line_id), 2) AS part,
                                       ROW_NUMBER() OVER (PARTITION BY s.line_id
                                                          ORDER BY s.share DESC, s.dimension_value_id) AS rn
                                  FROM journal_entry_line_dimension_splits s
                                  JOIN journal_entry_lines sl ON sl.id = s.line_id{$entryJoin}
                                 WHERE s.dimension_type_id = ?{$restrict}) q) a";
        return [$sql, $params];
    }

    /**
     * Díl řádků pro množinu hodnot (hodnota a její větev) sečtený po řádku:
     * `(line_id, amount)`.
     *
     * @param list<int> $valueIds
     * @param array{0:?string,1:string}|null $entryDates viz {@see partsSql()}
     * @return array{0:string,1:list<int|string>}
     */
    public static function lineShareSql(int $typeId, array $valueIds, ?int $supplierId = null, ?array $entryDates = null): array
    {
        [$parts, $params] = self::partsSql($typeId, $valueIds, $supplierId, $entryDates);
        if ($valueIds === []) {
            return ['SELECT NULL AS line_id, 0 AS amount FROM DUAL WHERE 1 = 0', []];
        }
        $marks = implode(',', array_fill(0, count($valueIds), '?'));
        return [
            "SELECT p.line_id, SUM(p.amount) AS amount FROM ({$parts}) p
              WHERE p.value_id IN ({$marks}) GROUP BY p.line_id",
            [...$params, ...$valueIds],
        ];
    }
}
