<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use PDO;

/**
 * Analytika po dimenzích pro daňovou evidenci: zdrojem je peněžní deník, ne účetní deník.
 *
 * Firma v daňové evidenci nemá zaúčtované řádky, na kterých by dimenze ležely. Hodnotu
 * proto nese pohyb peněžního deníku přes svůj doklad, ve stejném pořadí přednosti jako
 * při zaúčtování v podvojném účetnictví ({@see DimensionDefaults}):
 *
 *   1. vlastní dimenze pokladního dokladu nebo bankovního pohybu (hodnota i rozpad),
 *      u pokladního dokladu ještě zakázka dokladu,
 *   2. doklad, který pohyb hradí: vydaná nebo přijatá faktura (hlavička, rozpad, výchozí
 *      hodnota zakázky a klienta), ostatní pohledávka nebo závazek; bankovní pohyb
 *      hradící víc dokladů se dělí podle částek úhrad,
 *   3. položky faktury, když hlavička typ nemá (podle částky položky bez DPH),
 *   4. výchozí hodnota pravidla dimenzí ({@see DimensionRuleService::cashMovementRule()}),
 *   5. jinak „bez hodnoty".
 *
 * Částky jsou kbelíky peněžního deníku: výnos = daňový příjem, náklad = daňový výdaj
 * (u plátce DPH bez DPH, R7). Nedaňové pohyby, DPH, převody a soukromé vklady a výběry
 * do sestavy nevstupují, takže součet přes hodnoty sedí na „rozdíl příjmů a výdajů"
 * peněžního deníku za období. Odpisy majetku nejsou peněžní pohyb a nejsou tu.
 *
 * Podvojné účetnictví tuto třídu nepoužívá; volající větví podle režimu firmy v roce.
 */
final class CashJournalDimensionService
{
    public const INCOME_CODE = DimensionRuleService::CASH_INCOME_CODE;
    public const EXPENSE_CODE = DimensionRuleService::CASH_EXPENSE_CODE;

    private const ITEM_SOURCES = [
        'invoice' => ['invoice_items', 'invoice_id'],
        'purchase_invoice' => ['purchase_invoice_items', 'purchase_invoice_id'],
    ];

    /** @var array<string,array{header:array<int,int>, splits:array<int,array<int,float>>}> */
    private array $docCache = [];

    /** @var array<string,array<int,array<int,float>>> */
    private array $itemCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly CashJournalService $journal,
        private readonly DimensionRuleService $rules,
    ) {}

    /**
     * Součty v haléřích po hodnotě typu ('' = bez hodnoty) a druhu pohybu (příjem/výdaj),
     * ve tvaru řádků výsledovky po dimenzi.
     *
     * @return list<array{value_key:string, month_key:string, code:string, name:string, account_type:string,
     *                    revenue:int, cost:int, non_deductible_cost:int, income_tax_cost:int}>
     */
    public function sums(int $supplierId, int $typeId, string $from, string $to, bool $monthly = false): array
    {
        $usable = $this->rules->usableRules($supplierId);
        $acc = [];
        foreach ($this->movements($supplierId, $from, $to) as $m) {
            $month = $monthly ? substr($m['date'], 0, 7) : '';
            foreach ([[self::INCOME_CODE, $m['income']], [self::EXPENSE_CODE, $m['expense']]] as [$code, $cents]) {
                if ($cents === 0) {
                    continue;
                }
                $shares = $this->withRuleDefault($usable, $supplierId, $m, (string) $code, $typeId)['shares'];
                foreach (self::distribute($cents, $shares) as $valueId => $part) {
                    $key = $month . '|' . ($valueId > 0 ? (string) $valueId : '') . '|' . $code;
                    $acc[$key] ??= [
                        'value_key' => $valueId > 0 ? (string) $valueId : '',
                        'month_key' => $month,
                        'code' => (string) $code,
                        'name' => $code === self::INCOME_CODE ? 'Příjmy' : 'Výdaje',
                        'account_type' => $code === self::INCOME_CODE ? 'revenue' : 'expense',
                        'revenue' => 0,
                        'cost' => 0,
                        'non_deductible_cost' => 0,
                        'income_tax_cost' => 0,
                    ];
                    $acc[$key][$code === self::INCOME_CODE ? 'revenue' : 'cost'] += $part;
                }
            }
        }
        return array_values($acc);
    }

    /**
     * Pohyby s daňovým příjmem nebo výdajem, kterým podle platného pravidla (vynucení
     * error/warning) chybí hodnota typu — protějšek {@see \MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit::violations()}.
     *
     * @return array{rows:list<array<string,mixed>>, summary:list<array<string,mixed>>, total:int, truncated:bool}
     */
    public function violations(int $supplierId, string $from, string $to, int $limit = 200): array
    {
        $usable = $this->rules->usableRules($supplierId);
        $typeIds = [];
        foreach ($usable as $rule) {
            if ($rule['enforcement'] !== 'none') {
                $typeIds[$rule['dimension_type_id']] = true;
            }
        }
        $names = $this->typeNames($supplierId);
        $rows = [];
        $summary = [];
        $total = 0;
        foreach ($this->movements($supplierId, $from, $to) as $m) {
            foreach ([[self::INCOME_CODE, $m['income']], [self::EXPENSE_CODE, $m['expense']]] as [$code, $cents]) {
                if ($cents === 0) {
                    continue;
                }
                foreach (array_keys($typeIds) as $typeId) {
                    $rule = $this->withRuleDefault($usable, $supplierId, $m, (string) $code, $typeId);
                    if ($rule['enforcement'] === null || ($rule['shares'][0] ?? 0.0) <= 0.0) {
                        continue;
                    }
                    $total++;
                    $key = $typeId . '|' . $code . '|' . $rule['enforcement'] . '|' . $m['source_type'];
                    $summary[$key] ??= [
                        'type_id' => $typeId,
                        'type_name' => $names[$typeId] ?? ('#' . $typeId),
                        'synthetic' => (string) $code,
                        'enforcement' => $rule['enforcement'],
                        'source_type' => $m['source_type'],
                        'lines' => 0,
                        'amount' => 0.0,
                    ];
                    $summary[$key]['lines']++;
                    $summary[$key]['amount'] = round($summary[$key]['amount'] + $cents / 100, 2);
                    if (count($rows) < $limit) {
                        $rows[] = [
                            'line_id' => 0,
                            'entry_id' => 0,
                            'entry_date' => $m['date'],
                            'document_no' => $m['doc_no'] !== '' ? $m['doc_no'] : null,
                            'source_type' => $m['source_type'],
                            'source_id' => $m['source_id'],
                            'account_code' => (string) $code,
                            'account_name' => $code === self::INCOME_CODE ? 'Příjmy' : 'Výdaje',
                            'side' => $code === self::INCOME_CODE ? 'credit' : 'debit',
                            'amount' => $cents / 100,
                            'type_id' => $typeId,
                            'type_name' => $names[$typeId] ?? ('#' . $typeId),
                            'enforcement' => $rule['enforcement'],
                        ];
                    }
                }
            }
        }
        ksort($summary);
        return [
            'rows' => $rows,
            'summary' => array_values($summary),
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Kolik pohybů s daňovým příjmem a výdajem nese hodnotu typu z dokladu (bez pravidel),
     * protějšek {@see \MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit::coverage()}.
     *
     * @return list<array{type_id:int, type_name:string, synthetic:string, lines:int, covered:int, ratio:float, suggested:bool}>
     */
    public function coverage(int $supplierId, string $from, string $to): array
    {
        $types = (new DimensionRepository($this->db))->listTypes($supplierId, false);
        $counts = [];
        foreach ($this->movements($supplierId, $from, $to) as $m) {
            foreach ([[self::INCOME_CODE, $m['income']], [self::EXPENSE_CODE, $m['expense']]] as [$code, $cents]) {
                if ($cents === 0) {
                    continue;
                }
                foreach ($types as $type) {
                    $typeId = (int) $type['id'];
                    $key = $typeId . '|' . $code;
                    $counts[$key] ??= ['type' => $type, 'code' => (string) $code, 'lines' => 0, 'covered' => 0];
                    $counts[$key]['lines']++;
                    if (($this->shares($supplierId, $m, $typeId)[0] ?? 0.0) <= 0.0) {
                        $counts[$key]['covered']++;
                    }
                }
            }
        }
        ksort($counts);
        $out = [];
        foreach ($counts as $c) {
            $ratio = $c['lines'] > 0 ? round($c['covered'] / $c['lines'], 4) : 0.0;
            $out[] = [
                'type_id' => (int) $c['type']['id'],
                'type_name' => (string) $c['type']['name'],
                'synthetic' => $c['code'],
                'lines' => $c['lines'],
                'covered' => $c['covered'],
                'ratio' => $ratio,
                'suggested' => $c['lines'] >= 10 && $ratio >= 0.9,
            ];
        }
        return $out;
    }

    /**
     * Pohyby peněžního deníku s daňovým příjmem nebo výdajem v haléřích, po kalendářních
     * letech (klasifikace deníku bere konstanty roku).
     *
     * @return list<array{source_type:string, source_id:int, date:string, doc_no:string, invoice_id:?int,
     *                    purchase_invoice_id:?int, income:int, expense:int}>
     */
    private function movements(int $supplierId, string $from, string $to): array
    {
        $out = [];
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            $start = max($from, sprintf('%04d-01-01', $year));
            $end = min($to, sprintf('%04d-12-31', $year));
            foreach ($this->journal->classifiedMovements($supplierId, $start, $end) as $c) {
                $income = (int) round(((float) ($c['alloc']['income_taxable'] ?? 0)) * 100);
                $expense = (int) round(((float) ($c['alloc']['expense_taxable'] ?? 0)) * 100);
                if ($income === 0 && $expense === 0) {
                    continue;
                }
                $r = $c['row'];
                $out[] = [
                    'source_type' => (string) $r['source_type'],
                    'source_id' => (int) $r['source_id'],
                    'date' => (string) $r['movement_date'],
                    'doc_no' => (string) $r['doc_no'],
                    'invoice_id' => $r['invoice_id'] !== null ? (int) $r['invoice_id'] : null,
                    'purchase_invoice_id' => $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null,
                    'income' => $income,
                    'expense' => $expense,
                ];
            }
        }
        return $out;
    }

    /**
     * Podíly hodnot typu na pohybu (0 = bez hodnoty), doplněné o výchozí hodnotu pravidla.
     *
     * @param list<array<string,mixed>> $usable
     * @param array<string,mixed> $m
     * @return array{shares:array<int,float>, enforcement:?string}
     */
    private function withRuleDefault(array $usable, int $supplierId, array $m, string $code, int $typeId): array
    {
        $shares = $this->shares($supplierId, $m, $typeId);
        if (($shares[0] ?? 0.0) <= 0.0 || $usable === []) {
            return ['shares' => $shares, 'enforcement' => null];
        }
        $rule = $this->rules->cashMovementRule($usable, $supplierId, $m['source_type'], $m['source_id'], $code, $m['date'], $typeId);
        if ($rule['value_id'] === null) {
            return ['shares' => $shares, 'enforcement' => $rule['enforcement']];
        }
        $shares[$rule['value_id']] = ($shares[$rule['value_id']] ?? 0.0) + $shares[0];
        unset($shares[0]);
        return ['shares' => $shares, 'enforcement' => null];
    }

    /**
     * @param array<string,mixed> $m
     * @return array<int,float> hodnota (0 = bez hodnoty) => podíl, součet 1
     */
    private function shares(int $supplierId, array $m, int $typeId): array
    {
        $defaults = new DimensionDefaults($this->db);
        $own = match ($m['source_type']) {
            'cash' => $this->cashOwn($supplierId, $m['source_id']),
            'bank' => $this->effective($supplierId, 'bank_transaction', $m['source_id']),
            default => ['header' => [], 'splits' => []],
        };
        $fromOwn = self::typeShares($own, $typeId);
        if ($fromOwn !== null) {
            return $fromOwn;
        }

        // Doklady, které pohyb hradí, s vahou (částkou úhrady).
        $docs = [];
        if ($m['source_type'] === 'bank') {
            foreach ($defaults->bankDocuments($supplierId, $m['source_id'])['documents'] as $d) {
                $docs[] = [$d['doc_type'], $d['doc_id'], $d['amount']];
            }
        } elseif ($m['source_type'] === 'cash' && $m['invoice_id'] === null && $m['purchase_invoice_id'] === null) {
            foreach ($defaults->cashDocuments($supplierId, $m['source_id'])['documents'] as $d) {
                $docs[] = [$d['doc_type'], $d['doc_id'], $d['amount']];
            }
        }
        if ($docs === []) {
            if ($m['invoice_id'] !== null) {
                $docs[] = ['invoice', $m['invoice_id'], 1.0];
            } elseif ($m['purchase_invoice_id'] !== null) {
                $docs[] = ['purchase_invoice', $m['purchase_invoice_id'], 1.0];
            }
        }
        if ($docs === []) {
            return [0 => 1.0];
        }
        $weight = array_sum(array_map(static fn (array $d): float => abs((float) $d[2]), $docs));
        $out = [];
        foreach ($docs as [$docType, $docId, $amount]) {
            $w = $weight > 0 ? abs((float) $amount) / $weight : 1 / count($docs);
            $docShares = self::typeShares($this->effective($supplierId, $docType, $docId), $typeId)
                ?? $this->itemShares($supplierId, $docType, $docId)[$typeId]
                ?? [0 => 1.0];
            foreach ($docShares as $valueId => $share) {
                $out[$valueId] = ($out[$valueId] ?? 0.0) + $w * $share;
            }
        }
        return $out;
    }

    /** @return array{header:array<int,int>, splits:array<int,array<int,float>>} */
    private function cashOwn(int $supplierId, int $cashId): array
    {
        $own = $this->effective($supplierId, 'cash_document', $cashId);
        $stmt = $this->db->pdo()->prepare('SELECT project_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$cashId, $supplierId]);
        $projectId = $stmt->fetchColumn();
        if ($projectId === false || $projectId === null) {
            return $own;
        }
        return DimensionDefaults::layer([
            $own,
            ['header' => (new DimensionDefaults($this->db))->resolve($supplierId, null, (int) $projectId)['header'], 'splits' => []],
        ]);
    }

    /** @return array{header:array<int,int>, splits:array<int,array<int,float>>} */
    private function effective(int $supplierId, string $docType, int $docId): array
    {
        return $this->docCache[$docType . ':' . $docId] ??= (new DimensionDefaults($this->db))->effectiveDimensions($supplierId, $docType, $docId);
    }

    /**
     * Podíly hodnot po položkách faktury podle částky bez DPH (pořadí položky jako
     * v editoru dimenzí). Typ, který žádná položka nemá, chybí.
     *
     * @return array<int,array<int,float>> typ => hodnota (0 = bez hodnoty) => podíl
     */
    private function itemShares(int $supplierId, string $docType, int $docId): array
    {
        if (!isset(self::ITEM_SOURCES[$docType])) {
            return [];
        }
        $cacheKey = $docType . ':' . $docId;
        if (isset($this->itemCache[$cacheKey])) {
            return $this->itemCache[$cacheKey];
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $dims = $assignments->documentDimensions($supplierId, $docType, $docId)['items'];
        $splits = $assignments->documentSplits($supplierId, $docType, $docId);
        unset($splits[0]);
        if ($dims === [] && $splits === []) {
            return $this->itemCache[$cacheKey] = [];
        }
        [$table, $column] = self::ITEM_SOURCES[$docType];
        $stmt = $this->db->pdo()->prepare("SELECT total_without_vat FROM {$table} WHERE {$column} = ? ORDER BY order_index, id");
        $stmt->execute([$docId]);
        $weights = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $i => $w) {
            $weights[$i + 1] = (float) $w;
        }
        $total = array_sum($weights);
        if ($total == 0.0) {
            return $this->itemCache[$cacheKey] = [];
        }
        $typeIds = [];
        foreach ([$dims, $splits] as $source) {
            foreach ($source as $byType) {
                foreach (array_keys($byType) as $typeId) {
                    $typeIds[(int) $typeId] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys($typeIds) as $typeId) {
            foreach ($weights as $itemNo => $w) {
                $share = $w / $total;
                if (isset($dims[$itemNo][$typeId])) {
                    $v = (int) $dims[$itemNo][$typeId];
                    $out[$typeId][$v] = ($out[$typeId][$v] ?? 0.0) + $share;
                } elseif (!empty($splits[$itemNo][$typeId])) {
                    $sum = array_sum($splits[$itemNo][$typeId]);
                    foreach ($splits[$itemNo][$typeId] as $v => $s) {
                        $out[$typeId][(int) $v] = ($out[$typeId][(int) $v] ?? 0.0) + $share * ($sum > 0 ? $s / $sum : 0);
                    }
                } else {
                    $out[$typeId][0] = ($out[$typeId][0] ?? 0.0) + $share;
                }
            }
        }
        return $this->itemCache[$cacheKey] = $out;
    }

    /**
     * @param array{header:array<int,int>, splits:array<int,array<int,float>>} $dims
     * @return array<int,float>|null
     */
    private static function typeShares(array $dims, int $typeId): ?array
    {
        if (isset($dims['header'][$typeId])) {
            return [(int) $dims['header'][$typeId] => 1.0];
        }
        $split = $dims['splits'][$typeId] ?? [];
        $sum = array_sum($split);
        if ($split === [] || $sum <= 0) {
            return null;
        }
        $out = [];
        foreach ($split as $valueId => $share) {
            $out[(int) $valueId] = $share / $sum;
        }
        return $out;
    }

    /**
     * Rozdělí haléře podle podílů: každý díl se zaokrouhlí a zbytek dostane největší podíl
     * (při shodě nižší id) — stejné pravidlo jako rozpad řádku deníku.
     *
     * @param array<int,float> $shares
     * @return array<int,int>
     */
    public static function distribute(int $cents, array $shares): array
    {
        $shares = array_filter($shares, static fn (float $s): bool => $s > 0);
        if ($shares === []) {
            return [0 => $cents];
        }
        $sum = array_sum($shares);
        ksort($shares);
        $out = [];
        foreach ($shares as $valueId => $share) {
            $out[$valueId] = (int) round($cents * $share / $sum);
        }
        $residual = $cents - array_sum($out);
        if ($residual !== 0) {
            $biggest = array_keys($shares, max($shares), true)[0];
            $out[$biggest] += $residual;
        }
        return $out;
    }

    /** @return array<int,string> */
    private function typeNames(int $supplierId): array
    {
        $out = [];
        foreach ((new DimensionRepository($this->db))->listTypes($supplierId) as $t) {
            $out[(int) $t['id']] = (string) $t['name'];
        }
        return $out;
    }
}
