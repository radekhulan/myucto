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
 * počítají se všechny položky dokladu včetně textových a z evidence vyřazených.
 *
 * Rozpis DPH přijatého dokladu (`purchase_invoice_vat_allocations`) k položkám vázaný není.
 * Mají-li všechny položky dokladu stejnou dimenzi (jediná položka, jen hlavička, nebo
 * všechny položky stejně), patří řádek rozpisu jí. Jinak je vazba nejednoznačná a díl
 * zůstane bez hodnoty s příznakem `dimension_unassigned = 'vat_allocation'`: sestava ho
 * ukáže samostatným řádkem a filtrovaná kniha ho vypíše jako nepřiřazený. Řádky
 * pokladního dokladu položky nemají, platí pro ně hlavička.
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
 * Řádky musí nést zdrojový řádek dokladu (`line_id`, `line_source`, {@see VatLedgerService}
 * je čte jen pro rozpad). Zpracovávají se po dávkách: dimenze i pořadí položek dávky se
 * načtou jedním dotazem na tabulku (žádné N+1) a evidence za období nikdy neleží
 * v paměti celá.
 */
final class VatLedgerDimensionAllocation
{
    private const BATCH = 500;

    /** Částky řádku evidence, které se dělí mezi hodnoty (zbylé jsou údaje dokladu). */
    public const AMOUNT_KEYS = ['base_czk', 'vat_czk', 'deduction_base_czk', 'deduction_vat_czk'];

    /** Díl bez hodnoty, protože rozpis DPH nejde jednoznačně přiřadit k položkám. */
    public const UNASSIGNED_VAT_ALLOCATION = 'vat_allocation';

    public function __construct(private readonly Connection $db) {}

    /**
     * Díly řádků mezi hodnoty typu. Každý vstupní řádek dá jeden nebo víc dílů s klíči
     * `dimension_value_id` (null = bez dimenze) a `dimension_share`; částky z
     * {@see AMOUNT_KEYS} jsou díly řádku, ostatní klíče zůstávají. Díl rozpisu DPH bez
     * jednoznačné vazby nese navíc `dimension_unassigned`.
     *
     * @param iterable<array<string,mixed>> $rows kanonické řádky evidence
     * @return \Generator<int, array<string,mixed>>
     */
    public function parts(int $supplierId, int $typeId, iterable $rows): \Generator
    {
        foreach ($this->batches($rows) as $batch) {
            foreach ($this->sharesFor($supplierId, $typeId, $batch) as $i => [$shares, $unassigned]) {
                yield from self::split($batch[$i], $shares, $unassigned);
            }
        }
    }

    /**
     * Každý řádek celý a v `dimension_selected` jeho díl pro množinu hodnot (částky
     * {@see AMOUNT_KEYS}), v `dimension_share` podíl. Řádek rozpisu DPH bez jednoznačné
     * vazby, jehož doklad hodnotu z množiny nese, má díl nulový a `dimension_unassigned`.
     *
     * @param list<int> $valueIds hodnota a případně její podřízené
     * @param iterable<array<string,mixed>> $rows
     * @return \Generator<int, array<string,mixed>>
     */
    public function annotate(int $supplierId, int $typeId, array $valueIds, iterable $rows): \Generator
    {
        $wanted = array_fill_keys($valueIds, true);
        foreach ($this->batches($rows) as $batch) {
            foreach ($this->sharesFor($supplierId, $typeId, $batch, $wanted) as $i => [$shares, $unassigned, $touches]) {
                $row = $batch[$i];
                $selected = array_fill_keys(self::AMOUNT_KEYS, 0.0);
                $share = 0.0;
                foreach (self::split($row, $shares, $unassigned) as $part) {
                    if ($part['dimension_value_id'] === null || !isset($wanted[$part['dimension_value_id']])) {
                        continue;
                    }
                    $share += $part['dimension_share'];
                    foreach (self::AMOUNT_KEYS as $key) {
                        $selected[$key] = round($selected[$key] + (float) ($part[$key] ?? 0.0), 2);
                    }
                }
                $row['dimension_selected'] = $selected;
                $row['dimension_share'] = $share;
                if ($unassigned !== null && $touches) {
                    $row['dimension_unassigned'] = $unassigned;
                }
                yield $row;
            }
        }
    }

    /**
     * Řádky zúžené na množinu hodnot jednoho typu: z řádku zůstane součet dílů hodnot
     * z množiny, řádek bez dílu vypadne. Řádek rozpisu DPH bez jednoznačné vazby, jehož
     * doklad hodnotu z množiny nese, projde s nulovými částkami a `dimension_unassigned`
     * (s celými částkami v `dimension_unassigned_amounts`), aby ho výpis mohl uvést.
     *
     * @param list<int> $valueIds hodnota a případně její podřízené
     * @param iterable<array<string,mixed>> $rows
     * @return \Generator<int, array<string,mixed>>
     */
    public function filter(int $supplierId, int $typeId, array $valueIds, iterable $rows): \Generator
    {
        foreach ($this->annotate($supplierId, $typeId, $valueIds, $rows) as $row) {
            $selected = $row['dimension_selected'];
            unset($row['dimension_selected']);
            if (isset($row['dimension_unassigned'])) {
                $row['dimension_unassigned_amounts'] = array_intersect_key($row, array_flip(self::AMOUNT_KEYS));
            } elseif ($row['dimension_share'] <= 0.0) {
                continue;
            }
            foreach (self::AMOUNT_KEYS as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $row[$key] = $selected[$key];
                }
            }
            yield $row;
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
     * „bez dimenze" (zbytek podílu) nakonec. `$unassigned` označí díl bez hodnoty důvodem.
     *
     * @param array<string,mixed> $row
     * @param array<int,float> $shares
     * @return list<array<string,mixed>>
     */
    public static function split(array $row, array $shares, ?string $unassigned = null): array
    {
        $total = array_sum($shares);
        if ($shares === [] || $total <= 0.0) {
            $part = $row + ['dimension_value_id' => null, 'dimension_share' => 1.0];
            if ($unassigned !== null) {
                $part['dimension_unassigned'] = $unassigned;
            }
            return [$part];
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
     * Pro každý řádek dávky: [hodnota => podíl, důvod nepřiřazení|null, doklad nese hodnotu z `$wanted`].
     *
     * @param list<array<string,mixed>> $batch
     * @param array<int,true> $wanted
     * @return array<int, array{0: array<int,float>, 1: ?string, 2: bool}>
     */
    private function sharesFor(int $supplierId, int $typeId, array $batch, array $wanted = []): array
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
        [$positions, $itemCounts] = $this->itemPositions($batch, $assigned);

        $out = [];
        foreach ($batch as $i => $row) {
            $docType = self::docType($row);
            $doc = $docType !== null ? ($assigned[$docType][(int) $row['invoice_id']] ?? []) : [];
            $touches = false;
            foreach ($doc as $shares) {
                if (array_intersect_key($shares, $wanted) !== []) {
                    $touches = true;
                    break;
                }
            }
            if (($row['line_source'] ?? null) === 'purchase_allocation') {
                $out[$i] = [...self::documentWideShares($doc, $itemCounts[(int) $row['invoice_id']] ?? 0), $touches];
                continue;
            }
            $itemNo = $positions[(string) ($row['line_source'] ?? '')][(int) ($row['line_id'] ?? 0)] ?? null;
            $out[$i] = [($itemNo !== null ? ($doc[$itemNo] ?? null) : null) ?? ($doc[0] ?? []), null, $touches];
        }
        return $out;
    }

    /**
     * Dimenze celého dokladu pro řádek bez vazby na položku (rozpis DPH přijatého dokladu):
     * jednoznačná, když ji všechny položky mají stejnou (vlastní, nebo z hlavičky).
     *
     * @param array<int, array<int,float>> $doc pořadí položky (0 = hlavička) => hodnota => podíl
     * @return array{0: array<int,float>, 1: ?string}
     */
    private static function documentWideShares(array $doc, int $itemCount): array
    {
        $header = $doc[0] ?? [];
        $effective = null;
        for ($itemNo = 1; $itemNo <= $itemCount; $itemNo++) {
            $shares = $doc[$itemNo] ?? $header;
            ksort($shares);
            if ($effective !== null && $effective !== $shares) {
                return [[], self::UNASSIGNED_VAT_ALLOCATION];
            }
            $effective = $shares;
        }
        return [$effective ?? $header, null];
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
     * Pořadí položek (od 1) a jejich počet za doklad, jednou dávkou přes ROW_NUMBER nad
     * VŠEMI položkami dokladu (i textovými a z evidence vyřazenými, jako v editoru). Jen
     * u dokladů, kde rozhoduje: dimenze na některé položce, nebo rozpis DPH přijatého dokladu.
     *
     * @param list<array<string,mixed>> $batch
     * @param array<string, array<int, array<int, array<int,float>>>> $assigned
     * @return array{0: array<string, array<int,int>>, 1: array<int,int>} [zdroj => id položky => pořadí, přijatý doklad => počet položek]
     */
    private function itemPositions(array $batch, array $assigned): array
    {
        $tables = [
            'invoice_item' => ['invoice', 'invoice_items', 'invoice_id'],
            'purchase_item' => ['purchase_invoice', 'purchase_invoice_items', 'purchase_invoice_id'],
            'purchase_allocation' => ['purchase_invoice', 'purchase_invoice_items', 'purchase_invoice_id'],
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
                $docs[$tables[$source][1]][$docId] = true;
            }
        }
        $positions = [];
        $counts = [];
        foreach ($docs as $table => $ids) {
            $column = $table === 'invoice_items' ? 'invoice_id' : 'purchase_invoice_id';
            $source = $table === 'invoice_items' ? 'invoice_item' : 'purchase_item';
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, {$column} AS doc_id, ROW_NUMBER() OVER (PARTITION BY {$column} ORDER BY order_index, id) AS item_no
                   FROM {$table} WHERE {$column} IN ({$marks})"
            );
            $stmt->execute(array_keys($ids));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $positions[$source][(int) $r['id']] = (int) $r['item_no'];
                if ($source === 'purchase_item') {
                    $counts[(int) $r['doc_id']] = max($counts[(int) $r['doc_id']] ?? 0, (int) $r['item_no']);
                }
            }
        }
        return [$positions, $counts];
    }
}
