<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;

/**
 * Rozdělení kanonických řádků evidence DPH ({@see VatLedgerService}) mezi hodnoty
 * jednoho typu dimenze. Jediné místo pravidla pro knihu DPH a soupisy KH filtrované
 * podle dimenze i pro sestavu DPH podle dimenze: řádek hodnoty v sestavě proto sedí
 * na knihu DPH filtrovanou na tutéž hodnotu a součet přes hodnoty a „bez dimenze"
 * sedí na evidenci za období na haléř.
 *
 * Dimenze řádku evidence se bere z dokladu (Firma → Dimenze na dokladu):
 *   • položka s vlastní hodnotou typu (nebo rozpadem) rozhoduje za sebe,
 *   • jinak hlavička dokladu (hodnota, nebo rozpad),
 *   • jinak řádek nemá dimenzi („bez dimenze").
 * Pořadí položky odpovídá editoru i {@see DimensionStamper}: `order_index, id`, od 1,
 * textové řádky se počítají. Rozpis DPH přijatého dokladu (`purchase_invoice_vat_allocations`)
 * a řádky pokladního dokladu položku nemají, platí pro ně hlavička.
 *
 * Výchozí dimenze zakázky, klienta, produktu a pravidel dimenzí se tu NEUPLATNÍ: popisují,
 * co dostane zaúčtovaný řádek nákladu či výnosu, ne doklad. Filtr seznamu faktur
 * ({@see \MyInvoice\Service\Accounting\Dimension\DimensionFilter::documentSql()}) čte tytéž
 * tabulky, takže doklad, jehož DPH se v knize objeví pod hodnotou, je i v seznamu.
 *
 * Částky (základ i daň, i jejich odpočtové varianty) se dělí v haléřích pravidlem
 * {@see DimensionStamper::distributeCents()} (stejné jako rozpad řádku deníku); část
 * podílu, kterou rozpad nepokryje, zůstává „bez dimenze". Součet dílů řádku je tak vždy
 * přesně částka řádku. Celkové částky dokladu (`total_with_vat_czk`, `document_total`)
 * se nedělí: limit kontrolního hlášení posuzuje celý doklad.
 *
 * Řádky se zpracovávají po dávkách: dimenze i pořadí položek dávky se načtou jedním
 * dotazem na tabulku (žádné N+1) a evidence za období nikdy neleží v paměti celá.
 */
final class VatLedgerDimensionAllocation
{
    private const BATCH = 500;

    /** Částky řádku evidence, které se dělí mezi hodnoty (zbylé jsou údaje dokladu). */
    public const AMOUNT_KEYS = ['base_czk', 'vat_czk', 'deduction_base_czk', 'deduction_vat_czk'];

    public function __construct(private readonly Connection $db) {}

    /**
     * Díly řádků mezi hodnoty typu. Každý vstupní řádek dá jeden nebo víc dílů s klíči
     * `dimension_value_id` (null = bez dimenze) a `dimension_share`; částky z
     * {@see AMOUNT_KEYS} jsou díly řádku, ostatní klíče zůstávají.
     *
     * @param iterable<array<string,mixed>> $rows kanonické řádky evidence
     * @return \Generator<int, array<string,mixed>>
     */
    public function parts(int $supplierId, int $typeId, iterable $rows): \Generator
    {
        foreach ($this->batches($rows) as $batch) {
            $shares = $this->sharesFor($supplierId, $typeId, $batch);
            foreach ($batch as $i => $row) {
                yield from self::split($row, $shares[$i]);
            }
        }
    }

    /**
     * Řádky zúžené na množinu hodnot jednoho typu: z řádku zůstane součet dílů hodnot
     * z množiny, řádek bez dílu vypadne. `dimension_share` nese podíl řádku.
     *
     * @param list<int> $valueIds hodnota a případně její podřízené
     * @param iterable<array<string,mixed>> $rows
     * @return \Generator<int, array<string,mixed>>
     */
    public function filter(int $supplierId, int $typeId, array $valueIds, iterable $rows): \Generator
    {
        $wanted = array_fill_keys($valueIds, true);
        foreach ($this->batches($rows) as $batch) {
            $shares = $this->sharesFor($supplierId, $typeId, $batch);
            foreach ($batch as $i => $row) {
                $selected = null;
                $share = 0.0;
                foreach (self::split($row, $shares[$i]) as $part) {
                    if ($part['dimension_value_id'] === null || !isset($wanted[$part['dimension_value_id']])) {
                        continue;
                    }
                    $share += $part['dimension_share'];
                    if ($selected === null) {
                        $selected = $part;
                        continue;
                    }
                    foreach (self::AMOUNT_KEYS as $key) {
                        if ($selected[$key] !== null) {
                            $selected[$key] = round($selected[$key] + $part[$key], 2);
                        }
                    }
                }
                if ($selected !== null) {
                    $selected['dimension_share'] = $share;
                    unset($selected['dimension_value_id']);
                    yield $selected;
                }
            }
        }
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return \Generator<int, list<array<string,mixed>>>
     */
    private function batches(iterable $rows): \Generator
    {
        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row;
            if (count($batch) >= self::BATCH) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Rozdělí jeden řádek podle podílů (hodnota => podíl). Pořadí dílů: hodnoty podle id,
     * „bez dimenze" (zbytek podílu) nakonec.
     *
     * @param array<string,mixed> $row
     * @param array<int,float> $shares
     * @return list<array<string,mixed>>
     */
    public static function split(array $row, array $shares): array
    {
        $total = array_sum($shares);
        if ($shares === [] || $total <= 0.0) {
            return [$row + ['dimension_value_id' => null, 'dimension_share' => 1.0]];
        }
        if ($total > 1.0) {
            // Rozpad, jehož podíly dávají víc než celek (ruční zásah do dat), se normuje;
            // řádek nesmí ve sestavě narůst.
            $shares = array_map(static fn (float $s): float => $s / $total, $shares);
            $total = 1.0;
        }
        ksort($shares);
        $weights = array_values($shares);
        $keys = array_keys($shares);
        $rest = 1.0 - $total;
        if ($rest > 1e-9) {
            $weights[] = $rest;
            $keys[] = null;
        }
        $parts = [];
        foreach ($keys as $k => $valueId) {
            $parts[$k] = $row;
            $parts[$k]['dimension_value_id'] = $valueId;
            $parts[$k]['dimension_share'] = $weights[$k];
        }
        foreach (self::AMOUNT_KEYS as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $cents = DimensionStamper::distributeCents((int) round(((float) $row[$key]) * 100), $weights);
            foreach ($cents as $k => $c) {
                $parts[$k][$key] = $c / 100.0;
            }
        }
        return array_values($parts);
    }

    /**
     * Podíly hodnot typu pro každý řádek dávky (index řádku => hodnota => podíl).
     *
     * @param list<array<string,mixed>> $batch
     * @return array<int, array<int,float>>
     */
    private function sharesFor(int $supplierId, int $typeId, array $batch): array
    {
        $docIds = ['invoice' => [], 'purchase_invoice' => [], 'cash_document' => []];
        foreach ($batch as $row) {
            $docType = self::docType($row);
            if ($docType !== null) {
                $docIds[$docType][(int) $row['invoice_id']] = true;
            }
        }
        $assigned = [];
        foreach ($docIds as $docType => $ids) {
            if ($ids !== []) {
                $assigned[$docType] = $this->assignments($supplierId, $typeId, $docType, array_keys($ids));
            }
        }
        $positions = $this->itemPositions($batch, $assigned);

        $out = [];
        foreach ($batch as $i => $row) {
            $docType = self::docType($row);
            $doc = $docType !== null ? ($assigned[$docType][(int) $row['invoice_id']] ?? []) : [];
            $itemNo = $positions[(string) ($row['line_source'] ?? '')][(int) ($row['line_id'] ?? 0)] ?? null;
            $out[$i] = ($itemNo !== null ? ($doc[$itemNo] ?? null) : null) ?? ($doc[0] ?? []);
        }
        return $out;
    }

    /**
     * Druh dokladu řádku v `document_dimensions`. Pokladní doklad se pozná podle
     * `document_kind = 'cash'` ({@see VatLedgerService::documentIdentity()}).
     *
     * @param array<string,mixed> $row
     */
    private static function docType(array $row): ?string
    {
        if (($row['document_kind'] ?? null) === 'cash') {
            return 'cash_document';
        }
        return match ($row['source'] ?? null) {
            'sale' => 'invoice',
            'purchase' => 'purchase_invoice',
            default => null,
        };
    }

    /**
     * Přiřazení typu na dokladech: doklad => pořadí položky (0 = hlavička) => hodnota => podíl.
     * Jediná hodnota má podíl 1, rozpad své podíly. Má-li položka (nebo hlavička) obojí
     * (souběžný zápis mimo editor), vyhrává rozpad, stejně jako při zaúčtování.
     *
     * @param list<int> $docIds
     * @return array<int, array<int, array<int,float>>>
     */
    private function assignments(int $supplierId, int $typeId, string $docType, array $docIds): array
    {
        $marks = implode(',', array_fill(0, count($docIds), '?'));
        $params = [$supplierId, $docType, ...$docIds, $typeId];
        $out = [];
        $single = $this->db->pdo()->prepare(
            "SELECT doc_id, item_no, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = ? AND doc_id IN ({$marks}) AND dimension_type_id = ?"
        );
        $single->execute($params);
        foreach ($single->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['doc_id']][(int) $r['item_no']] = [(int) $r['dimension_value_id'] => 1.0];
        }
        $splits = $this->db->pdo()->prepare(
            "SELECT doc_id, item_no, dimension_value_id, share FROM document_dimension_splits
              WHERE supplier_id = ? AND doc_type = ? AND doc_id IN ({$marks}) AND dimension_type_id = ?"
        );
        $splits->execute($params);
        $splitMap = [];
        foreach ($splits->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $splitMap[(int) $r['doc_id']][(int) $r['item_no']][(int) $r['dimension_value_id']] = (float) $r['share'];
        }
        foreach ($splitMap as $docId => $items) {
            foreach ($items as $itemNo => $shares) {
                $out[$docId][$itemNo] = $shares;
            }
        }
        return $out;
    }

    /**
     * Pořadí položek (od 1) pro řádky dávky, jen u dokladů, které mají dimenzi na
     * některé položce — jinak rozhoduje hlavička a pořadí není potřeba.
     *
     * @param list<array<string,mixed>> $batch
     * @param array<string, array<int, array<int, array<int,float>>>> $assigned
     * @return array<string, array<int,int>> zdroj řádku => id položky => pořadí
     */
    private function itemPositions(array $batch, array $assigned): array
    {
        $tables = [
            'invoice_item' => ['invoice', 'invoice_items', 'invoice_id'],
            'purchase_item' => ['purchase_invoice', 'purchase_invoice_items', 'purchase_invoice_id'],
        ];
        $docs = [];
        foreach ($batch as $row) {
            $source = (string) ($row['line_source'] ?? '');
            if (!isset($tables[$source])) {
                continue;
            }
            $docId = (int) $row['invoice_id'];
            $items = $assigned[$tables[$source][0]][$docId] ?? [];
            if (array_diff(array_keys($items), [0]) !== []) {
                $docs[$source][$docId] = true;
            }
        }
        $out = [];
        foreach ($docs as $source => $ids) {
            [, $table, $column] = $tables[$source];
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, ROW_NUMBER() OVER (PARTITION BY {$column} ORDER BY order_index, id) AS item_no
                   FROM {$table} WHERE {$column} IN ({$marks})"
            );
            $stmt->execute(array_keys($ids));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[$source][(int) $r['id']] = (int) $r['item_no'];
            }
        }
        return $out;
    }
}
