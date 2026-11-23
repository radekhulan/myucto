<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation;
use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;

/**
 * Sestava „DPH podle dimenze": evidence DPH za zdaňovací období rozdělená po hodnotách
 * jednoho typu dimenze (Středisko, Projekt …) včetně řádku „bez dimenze".
 *
 * Data jsou TÁTÁ jako přiznání: období a režim z {@see DphPriznaniBuilder::returnScope()}
 * (identifikovaná osoba vždy za měsíc a jen s řádky samovyměření), kanonické řádky
 * {@see VatLedgerService::iterateReturnRowsByDimension()} bez konceptů rozdělené pravidlem
 * {@see VatLedgerDimensionAllocation} (položka > hlavička, rozpad po haléřích) a opravy
 * § 74b, § 46 a § 43 z {@see DphPriznaniBuilder::correctionEntries()}. Každý řádek sestavy
 * má vlastní projekci na řádky DPHDP3 ({@see DphLineProjection}) a z ní DPH na výstupu
 * (ř. 1–13), nárok na odpočet (ř. 40–45 včetně kráceného sloupce v plné výši) a základy.
 *
 * Řádky: hodnoty typu, „Bez dimenze", „Rozpis DPH bez vazby na položky" (rozpis DPH
 * přijatého dokladu s položkami v různých dimenzích) a „Opravy přiznání bez vazby na
 * položky". Součet všech je úplná evidence za období.
 *
 * Kontroly:
 *   1. Součet řádků proti evidenci za období promítnuté STEJNOU projekcí z nerozdělených
 *      řádků téhož průchodu a opravám. Rozdíl musí být nula na haléř a po řádcích přiznání;
 *      nenulový rozdíl znamená, že rozdělení řádek ztratilo nebo zdvojilo. Zrcadlový
 *      odpočet ř. 43/44 je v obou haléřový (přiznání ho skládá po celých Kč).
 *   2. Most k přiznání (ř. 62, 63, 64 z {@see DphPriznaniBuilder}): rozdíl tvoří jen úpravy
 *      bez řádků dokladů (§ 79 na ř. 45, krácení § 76 na ř. 52/53, § 78 na ř. 60)
 *      a zaokrouhlení řádků přiznání na celé Kč.
 *   3. Obraty účtu 343 po hodnotách dimenze řádků deníku za období (datum účetního
 *      případu), bez úhrad daně finančnímu úřadu a bez počátečních stavů a uzávěrky
 *      ({@see Vat343Turnover}). Rozdíl proti sestavě se vysvětluje, nekontroluje.
 *
 * Chybí-li u dokladu v cizí měně kurz, evidence ho přepočte náhradním kurzem 1,0; sestava
 * je pak předběžná (`preliminary`, `missing_rates`, varování), stejně jako náhled přiznání.
 */
final class VatDimensionReportService
{
    /** Klíče zvláštních řádků (hodnoty mají klíč id). */
    private const ROW_NONE = 'none';
    private const ROW_VAT_ALLOCATION = 'vat_allocation';
    private const ROW_CORRECTIONS = 'corrections';

    /** Popisky zvláštních řádků sestavy (`kind`). */
    public const ROW_LABELS = [
        'none' => 'Bez dimenze',
        'vat_allocation' => 'Rozpis DPH bez vazby na položky',
        'corrections' => 'Opravy přiznání bez vazby na položky (§ 43, § 46, § 74b)',
    ];

    public const AMOUNT_KEYS = [
        'vat_output', 'vat_input', 'vat_result',
        'base_output_standard', 'base_output_reduced', 'base_output_other',
        'base_input_standard', 'base_input_reduced', 'base_input_other',
    ];

    /** Řádky DPHDP3 se základní a sníženou sazbou (výstup a odpočet). */
    private const OUTPUT_STANDARD = ['1', '3', '5', '7', '10', '12'];
    private const OUTPUT_REDUCED = ['2', '4', '6', '8', '11', '13'];
    private const INPUT_STANDARD = ['40', '40k', '43', '43k'];
    private const INPUT_REDUCED = ['41', '41k', '44', '44k'];

    public const ACCOUNT_343_NOTE = 'Obrat účtu 343 se liší od sestavy, protože: (1) deník řadí podle data účetního případu, '
        . 'evidence DPH podle DUZP a u přijatých dokladů podle vzniku nároku na odpočet (§ 73); (2) řádek 343 nese '
        . 'dimenzi hlavičky dokladu (a výchozí dimenze zakázky, klienta či pravidla), dimenze položky platí jen pro '
        . 'náklad a výnos, kdežto sestava dělí DPH po položkách; (3) ruční a interní zápisy na 343 (samovyměření, '
        . 'opravy § 74b, převody mezi analytikami) jdou podle dimenze řádku zápisu; (4) koncepty a nezaúčtované '
        . 'doklady v deníku nejsou. Úhrady daně finančnímu úřadu, vratky odpočtu, počáteční stavy a uzávěrka '
        . 'se do obratu nepočítají.';

    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        private readonly DphPriznaniBuilder $vatReturn,
        private readonly DimensionService $dimensions,
        private readonly DimensionRepository $dimensionRepo,
    ) {}

    /**
     * @param 'monthly'|'quarterly' $period u čtvrtletí nese `$month` libovolný měsíc čtvrtletí
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $year, int $month, string $period, int $typeId): array
    {
        if (!$this->dimensionRepo->enabled($supplierId)) {
            throw new DimensionException('dimensions_disabled', 'Dimenze nejsou u firmy zapnuté (Nastavení firmy).', 409);
        }
        $type = $this->dimensions->requireType($supplierId, $typeId);
        // Období a režim přesně jako přiznání: identifikovaná osoba vždy za měsíc a jen
        // s řádky samovyměření ({@see DphPriznaniBuilder::returnScope()}).
        $scope = $this->vatReturn->returnScope($supplierId, $year, $month, $period);
        [$start, $end, $period] = [$scope['start'], $scope['end'], $scope['period']];
        $quarter = $scope['quarter'];

        // Jeden průchod evidencí: nerozdělený řádek jde do kontrolní projekce (a sběru
        // chybějících kurzů), jeho díly do projekcí hodnot. Evidence se tak čte jednou
        // a v paměti nikdy neleží celá.
        $evidence = new DphLineProjection();
        $missingByDoc = [];
        $onRow = static function (array $r) use ($evidence, &$missingByDoc): void {
            $evidence->add($r);
            VatLedgerService::collectMissingExchangeRate($missingByDoc, $r);
        };
        /** @var array<string, DphLineProjection> $buckets */
        $buckets = [];
        $documents = [];
        foreach ($this->ledger->iterateReturnRowsByDimension($supplierId, $start, $end, $typeId, false, $onRow) as $part) {
            $key = isset($part['dimension_unassigned'])
                ? self::ROW_VAT_ALLOCATION
                : ($part['dimension_value_id'] === null ? self::ROW_NONE : (string) $part['dimension_value_id']);
            ($buckets[$key] ??= new DphLineProjection())->add($part);
            if ($part['code'] !== null && $part['dphdp3_line'] !== null) {
                $documents[$key][VatLedgerService::documentIdentity($part)] = true;
            }
        }
        $filter = static function (array $lines) use ($scope): array {
            if (!$scope['identified']) {
                return $lines;
            }
            $ignored = [];
            return DphPriznaniBuilder::filterLinesForIdentified($lines, $ignored);
        };

        // Opravy, které přiznání přičítá k řádkům (§ 74b, § 46, § 43): podíl na položkách
        // dokladu nemají, sestava je vykazuje samostatným řádkem v úplném součtu.
        $corrections = $this->vatReturn->correctionEntries($supplierId, $year, $month, $period, $scope['identified'], $scope['payer']);
        $correctionLines = [];
        foreach ($corrections['entries'] as $entry) {
            if (round($entry['base'], 2) == 0.0 && round($entry['vat'], 2) == 0.0) {
                continue;
            }
            $correctionLines[$entry['line']]['base'] = ($correctionLines[$entry['line']]['base'] ?? 0.0) + $entry['base'];
            $correctionLines[$entry['line']]['vat'] = ($correctionLines[$entry['line']]['vat'] ?? 0.0) + $entry['vat'];
        }

        $valueKeys = array_values(array_filter(array_keys($buckets), static fn (string|int $k): bool => ctype_digit((string) $k)));
        $values = $this->values($supplierId, $typeId, array_map('strval', $valueKeys));
        $rowsSpec = array_map(static fn (array $v): array => ['kind' => 'value', 'key' => (string) $v['id'], 'value' => $v], $values);
        $rowsSpec[] = ['kind' => 'none', 'key' => self::ROW_NONE, 'value' => null];
        if (isset($buckets[self::ROW_VAT_ALLOCATION])) {
            $rowsSpec[] = ['kind' => 'vat_allocation', 'key' => self::ROW_VAT_ALLOCATION, 'value' => null];
        }
        if ($correctionLines !== []) {
            $rowsSpec[] = ['kind' => 'corrections', 'key' => self::ROW_CORRECTIONS, 'value' => null];
        }

        $out = [];
        $sumLines = [];
        foreach ($rowsSpec as $spec) {
            $lines = $spec['kind'] === 'corrections'
                ? $correctionLines
                : $filter(isset($buckets[$spec['key']]) ? $buckets[$spec['key']]->lines(false) : []);
            foreach ($lines as $line => $d) {
                $sumLines[$line]['base'] = ($sumLines[$line]['base'] ?? 0.0) + (float) $d['base'];
                $sumLines[$line]['vat'] = ($sumLines[$line]['vat'] ?? 0.0) + (float) $d['vat'];
            }
            $value = $spec['value'];
            $out[] = [
                'kind'      => $spec['kind'],
                'value_id'  => $value === null ? null : (int) $value['id'],
                'code'      => $value === null ? null : (string) $value['code'],
                'name'      => $value === null ? self::ROW_LABELS[$spec['kind']] : (string) $value['name'],
                'label'     => $value === null ? self::ROW_LABELS[$spec['kind']] : self::label($value),
                'depth'     => $value === null ? 0 : (int) $value['depth'],
                'is_active' => $value === null ? true : (bool) $value['is_active'],
                'documents' => count($documents[$spec['key']] ?? []),
            ] + self::amounts($lines) + ['lines' => self::roundLines($lines)];
        }
        $total = self::sumAmounts($out);

        // Kontrola: evidence za období promítnutá z NEROZDĚLENÝCH řádků téhož průchodu
        // a opravy přiznání, tedy řádky přiznání před zaokrouhlením na celé Kč.
        $evidenceLines = $filter($evidence->lines(false));
        foreach ($correctionLines as $line => $d) {
            $evidenceLines[$line]['base'] = (float) ($evidenceLines[$line]['base'] ?? 0.0) + $d['base'];
            $evidenceLines[$line]['vat'] = (float) ($evidenceLines[$line]['vat'] ?? 0.0) + $d['vat'];
        }
        $evidenceAmounts = self::amounts($evidenceLines);
        $difference = [];
        foreach (self::AMOUNT_KEYS as $k) {
            $difference[$k] = round($total[$k] - $evidenceAmounts[$k], 2);
        }
        $lineDifferences = [];
        foreach (array_unique([...array_map('strval', array_keys($sumLines)), ...array_map('strval', array_keys($evidenceLines))]) as $line) {
            $db = round(($sumLines[$line]['base'] ?? 0.0) - (float) ($evidenceLines[$line]['base'] ?? 0.0), 2);
            $dv = round(($sumLines[$line]['vat'] ?? 0.0) - (float) ($evidenceLines[$line]['vat'] ?? 0.0), 2);
            if (abs($db) >= 0.005 || abs($dv) >= 0.005) {
                $lineDifferences[] = ['line' => (string) $line, 'base' => $db, 'vat' => $dv];
            }
        }
        $ok = $lineDifferences === [] && array_filter($difference, static fn (float $d): bool => abs($d) >= 0.005) === [];

        [$return, $returnError] = $this->returnBridge($supplierId, $year, $month, $period, $total);
        [$account343, $account343Error] = $this->account343($supplierId, $typeId, $start, $end, $out, $total);

        $missingRates = array_values($missingByDoc);
        $warnings = [];
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Částky těchto dokladů jsou přepočtené náhradním kurzem 1,0 a sestava je jen předběžná; '
                . 'kurz doplňte na dokladech (přiznání ho při stažení doplní z ČNB).';
        }
        if ($scope['forced_monthly']) {
            $warnings[] = 'Identifikovaná osoba podává vždy za kalendářní měsíc — sestava je za zvolený měsíc, ne za čtvrtletí.';
        }
        if (isset($buckets[self::ROW_VAT_ALLOCATION])) {
            $warnings[] = 'Některé přijaté doklady mají rozpis DPH a položky s různými dimenzemi: rozpis nejde přiřadit '
                . 'k položkám, jeho DPH je v řádku „' . self::ROW_LABELS['vat_allocation'] . '".';
        }
        array_push($warnings, ...$corrections['warnings']);
        if (!$ok) {
            $warnings[] = 'Součet sestavy nesedí na evidenci DPH za období. Rozdíl po řádcích přiznání je v kontrolním řádku.';
        }

        return [
            'period' => [
                'year' => $year, 'month' => $month, 'period_type' => $period, 'quarter' => $quarter,
                'start' => $start, 'end' => $end,
                'label' => $quarter !== null ? sprintf('%d. čtvrtletí %04d', $quarter, $year) : sprintf('%02d/%04d', $month, $year),
                'forced_monthly' => $scope['forced_monthly'],
            ],
            'supplier' => $this->supplier($supplierId),
            'dimension_type' => ['id' => (int) $type['id'], 'name' => (string) $type['name']],
            'amount_keys' => self::AMOUNT_KEYS,
            'rows' => $out,
            'total' => $total,
            'control' => [
                'evidence' => $evidenceAmounts,
                'difference' => $difference,
                'ok' => $ok,
                'line_differences' => $lineDifferences,
                'return' => $return,
                'return_error' => $returnError,
            ],
            'preliminary' => $missingRates !== [],
            'missing_rates' => $missingRates,
            'account343' => $account343,
            'account343_unavailable' => $account343Error,
            'generated_at' => date('Y-m-d H:i:s'),
            'warnings' => $warnings,
        ];
    }

    /**
     * Částky sestavy z řádků DPHDP3.
     *
     * @param array<string, array{base:float, vat:float}> $lines
     * @return array<string,float>
     */
    public static function amounts(array $lines): array
    {
        $a = array_fill_keys(self::AMOUNT_KEYS, 0.0);
        foreach ($lines as $line => $d) {
            $line = (string) $line;
            $n = (int) $line;
            $base = (float) $d['base'];
            $vat = (float) $d['vat'];
            if ($n >= 1 && $n <= 13) {
                $a['vat_output'] += $vat;
                if (in_array($line, self::OUTPUT_STANDARD, true)) {
                    $a['base_output_standard'] += $base;
                } elseif (in_array($line, self::OUTPUT_REDUCED, true)) {
                    $a['base_output_reduced'] += $base;
                } else {
                    $a['base_output_other'] += $base;
                }
            } elseif ($n >= 20 && $n <= 26) {
                // Plnění s nárokem na odpočet bez daně (EU, vývoz, přenesení § 92a, OSS).
                $a['base_output_other'] += $base;
            } elseif ($n >= 40 && $n <= 45) {
                // Krácený odpočet (40k–44k) v plné výši; krácení koeficientem (ř. 52/53)
                // je úprava za celé období, je v mostu k přiznání.
                $a['vat_input'] += $vat;
                if (in_array($line, self::INPUT_STANDARD, true)) {
                    $a['base_input_standard'] += $base;
                } elseif (in_array($line, self::INPUT_REDUCED, true)) {
                    $a['base_input_reduced'] += $base;
                } else {
                    $a['base_input_other'] += $base;
                }
            }
        }
        $a['vat_result'] = $a['vat_output'] - $a['vat_input'];
        return array_map(static fn (float $v): float => round($v, 2), $a);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,float>
     */
    private static function sumAmounts(array $rows): array
    {
        $cents = array_fill_keys(self::AMOUNT_KEYS, 0);
        foreach ($rows as $row) {
            foreach (self::AMOUNT_KEYS as $k) {
                $cents[$k] += (int) round(((float) $row[$k]) * 100);
            }
        }
        return array_map(static fn (int $c): float => $c / 100.0, $cents);
    }

    /**
     * @param array<string, array{base:float, vat:float}> $lines
     * @return array<string, array{base:float, vat:float}>
     */
    private static function roundLines(array $lines): array
    {
        $out = [];
        uksort($lines, static fn ($a, $b): int => strnatcmp((string) $a, (string) $b));
        foreach ($lines as $line => $d) {
            $out[(string) $line] = ['base' => round((float) $d['base'], 2), 'vat' => round((float) $d['vat'], 2)];
        }
        return $out;
    }

    /**
     * Most sestava → přiznání: ř. 62/63/64 tak, jak je skládá {@see DphPriznaniBuilder}, a rozdíl
     * proti součtu sestavy (úpravy § 79, § 76, § 78 a zaokrouhlení na celé Kč).
     *
     * @param array<string,float> $evidence
     * @return array{0: array<string,mixed>|null, 1: string|null}
     */
    private function returnBridge(int $supplierId, int $year, int $month, string $period, array $evidence): array
    {
        try {
            $summary = $this->vatReturn->build($supplierId, $year, $month, $period)['summary'];
        } catch (\Throwable $e) {
            return [null, 'Přiznání za období nejde sestavit: ' . $e->getMessage()];
        }
        $return = [
            'vat_output' => round((float) $summary['total_vat_output'], 2),
            'vat_input' => round((float) $summary['total_vat_input'], 2),
            'vat_result' => round((float) $summary['tax_due'], 2),
        ];
        $bridge = [];
        foreach ($return as $k => $v) {
            $bridge[$k] = round($v - $evidence[$k], 2);
        }
        return [$return + ['bridge' => $bridge], null];
    }

    /**
     * Hodnoty typu v pořadí stromu (rodič před dětmi) s hloubkou. Neaktivní hodnoty jen
     * tehdy, když na ně v období něco připadlo.
     *
     * @param list<string> $withAmounts id hodnot s částkami
     * @return list<array<string,mixed>>
     */
    private function values(int $supplierId, int $typeId, array $withAmounts): array
    {
        $all = $this->dimensionRepo->listValues($supplierId, $typeId);
        $used = array_fill_keys(array_map('intval', $withAmounts), true);
        $byParent = [];
        $ids = array_fill_keys(array_map(static fn (array $v): int => (int) $v['id'], $all), true);
        foreach ($all as $v) {
            $parent = $v['parent_id'] !== null && isset($ids[(int) $v['parent_id']]) ? (int) $v['parent_id'] : 0;
            $byParent[$parent][] = $v;
        }
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent, $used): void {
            foreach ($byParent[$parent] ?? [] as $v) {
                if (!empty($v['is_active']) || isset($used[(int) $v['id']])) {
                    $out[] = $v + ['depth' => $depth];
                }
                $walk((int) $v['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /**
     * Obraty účtu 343 po hodnotách dimenze řádků deníku za období.
     *
     * Hodnota řádku: jediná hodnota typu, u Střediska navázaného na číselník i textový kód
     * střediska řádku bez dimenze, rozpad po haléřích ({@see DimensionSplitAllocation}) —
     * stejně jako výsledovka po dimenzi.
     *
     * @param list<array<string,mixed>> $reportRows
     * @param array<string,float> $reportTotal
     * @return array{0: array<string,mixed>|null, 1: string|null}
     */
    private function account343(int $supplierId, int $typeId, string $start, string $end, array $reportRows, array $reportTotal): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code LIKE '343%'");
        $stmt->execute([$supplierId]);
        $accountIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if ($accountIds === []) {
            return [null, 'Firma nemá v účtové osnově účet 343 (daňová evidence nebo nezaložené účetnictví).'];
        }
        $marks = implode(',', array_fill(0, count($accountIds), '?'));
        $excluded = 'CASE WHEN ' . Vat343Turnover::bookTransferSql('e') . " THEN 'opening'
                          WHEN " . Vat343Turnover::settlementLineSql('l', 'e') . " THEN 'payment' ELSE '' END";

        // Vyloučené zápisy (úhrady a vratky daně, počáteční stavy a uzávěrka) celé, bez
        // rozpadu po dimenzích: počet je počet zápisů, ne řádků či dílů.
        $excludedStmt = $this->db->pdo()->prepare(
            "SELECT x.excluded, COUNT(DISTINCT x.entry_id) AS entries, SUM(x.net) AS net
               FROM (SELECT e.id AS entry_id, {$excluded} AS excluded,
                            CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END AS net
                       FROM journal_entries e
                       JOIN journal_entry_lines l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id
                      WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL
                        AND e.entry_date >= ? AND e.entry_date <= ?
                        AND l.account_id IN ({$marks})) x
              WHERE x.excluded <> ''
           GROUP BY x.excluded"
        );
        $excludedStmt->execute([$supplierId, $start, $end, ...$accountIds]);
        $excludedSums = ['payment' => ['count' => 0, 'net' => 0], 'opening' => ['count' => 0, 'net' => 0]];
        foreach ($excludedStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $excludedSums[$r['excluded']] = ['count' => (int) $r['entries'], 'net' => (int) round(((float) $r['net']) * 100)];
        }

        $single = $this->db->pdo()->prepare(
            "SELECT COALESCE(jd.dimension_value_id, ccv.id) AS value_id, {$excluded} AS excluded,
                    COUNT(DISTINCT e.id) AS entries,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE 0 END) AS debit,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS credit
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id
          LEFT JOIN journal_entry_line_dimensions jd ON jd.line_id = l.id AND jd.dimension_type_id = ?
          LEFT JOIN cost_centers cc ON jd.line_id IS NULL AND l.cost_center IS NOT NULL
                                   AND cc.supplier_id = l.supplier_id AND cc.code = l.cost_center
          LEFT JOIN dimension_values ccv ON ccv.cost_center_id = cc.id AND ccv.type_id = ?
              WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date >= ? AND e.entry_date <= ?
                AND l.account_id IN ({$marks})
                AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s
                                 WHERE s.line_id = l.id AND s.dimension_type_id = ?)
           GROUP BY value_id, excluded"
        );
        $single->execute([$typeId, $typeId, $supplierId, $start, $end, ...$accountIds, $typeId]);
        $sums = $single->fetchAll(\PDO::FETCH_ASSOC);

        [$partsSql, $partsParams] = DimensionSplitAllocation::partsSql($typeId, null, $supplierId, [$start, $end]);
        $signedPart = '(CASE WHEN l.is_red_storno = 1 THEN -sp.amount ELSE sp.amount END)';
        $split = $this->db->pdo()->prepare(
            "SELECT sp.value_id, {$excluded} AS excluded, COUNT(DISTINCT e.id) AS entries,
                    SUM(CASE WHEN l.side = 'debit' THEN {$signedPart} ELSE 0 END) AS debit,
                    SUM(CASE WHEN l.side = 'credit' THEN {$signedPart} ELSE 0 END) AS credit
               FROM ({$partsSql}) sp
               JOIN journal_entry_lines l ON l.id = sp.line_id
               JOIN journal_entries e ON e.supplier_id = l.supplier_id AND e.id = l.entry_id
              WHERE l.supplier_id = ? AND l.account_id IN ({$marks})
           GROUP BY sp.value_id, excluded"
        );
        $split->execute([...$partsParams, $supplierId, ...$accountIds]);
        array_push($sums, ...$split->fetchAll(\PDO::FETCH_ASSOC));

        $byValue = [];
        foreach ($sums as $r) {
            $debit = (int) round(((float) $r['debit']) * 100);
            $credit = (int) round(((float) $r['credit']) * 100);
            if ($r['excluded'] !== '') {
                continue;
            }
            $key = $r['value_id'] === null ? '' : (string) (int) $r['value_id'];
            $byValue[$key]['debit'] = ($byValue[$key]['debit'] ?? 0) + $debit;
            $byValue[$key]['credit'] = ($byValue[$key]['credit'] ?? 0) + $credit;
        }

        // Řádky v pořadí sestavy; hodnota, která je jen na 343 (mimo strom sestavy), se přidá.
        $labels = [];
        foreach ($reportRows as $row) {
            if ($row['kind'] === 'value' || $row['kind'] === 'none') {
                $labels[$row['value_id'] === null ? '' : (string) $row['value_id']] = $row;
            }
        }
        $extra = array_diff(array_keys($byValue), array_keys($labels));
        if ($extra !== []) {
            foreach ($this->dimensionRepo->valuesByIds($supplierId, array_map('intval', $extra)) as $value) {
                $labels[(string) $value['id']] = ['value_id' => (int) $value['id'], 'code' => (string) $value['code'],
                    'name' => (string) $value['name'], 'label' => self::label($value), 'vat_result' => 0.0];
            }
        }
        $order = [...array_values(array_filter(array_keys($labels), static fn ($k): bool => $k !== '')), ''];
        $rows = [];
        $comparison = [];
        $total = ['debit' => 0, 'credit' => 0];
        foreach ($order as $key) {
            $key = (string) $key;
            $meta = $labels[$key] ?? ['value_id' => null, 'code' => null, 'name' => 'Bez dimenze', 'label' => 'Bez dimenze', 'vat_result' => 0.0];
            $debit = $byValue[$key]['debit'] ?? 0;
            $credit = $byValue[$key]['credit'] ?? 0;
            $total['debit'] += $debit;
            $total['credit'] += $credit;
            $rows[] = [
                'value_id' => $meta['value_id'], 'code' => $meta['code'], 'name' => $meta['name'], 'label' => $meta['label'],
                'debit' => $debit / 100.0, 'credit' => $credit / 100.0, 'net' => ($credit - $debit) / 100.0,
            ];
            $reportResult = (float) ($meta['vat_result'] ?? 0.0);
            $comparison[] = [
                'value_id' => $meta['value_id'], 'label' => $meta['label'],
                'report_result' => $reportResult, 'account_net' => ($credit - $debit) / 100.0,
                'difference' => round($reportResult - ($credit - $debit) / 100.0, 2),
            ];
        }
        $net = ($total['credit'] - $total['debit']) / 100.0;
        $comparison[] = [
            'value_id' => null, 'label' => 'Celkem', 'report_result' => $reportTotal['vat_result'],
            'account_net' => $net, 'difference' => round($reportTotal['vat_result'] - $net, 2), 'is_total' => true,
        ];

        return [[
            'rows' => $rows,
            'total' => ['debit' => $total['debit'] / 100.0, 'credit' => $total['credit'] / 100.0, 'net' => $net],
            'excluded' => [
                'payments' => ['count' => $excludedSums['payment']['count'], 'net' => $excludedSums['payment']['net'] / 100.0],
                'opening' => ['count' => $excludedSums['opening']['count'], 'net' => $excludedSums['opening']['net'] / 100.0],
            ],
            'comparison' => $comparison,
            'note' => self::ACCOUNT_343_NOTE,
        ], null];
    }

    /**
     * Popisek hodnoty: kód a název, u hodnoty s názvem shodným s kódem jen název.
     *
     * @param array<string,mixed> $value
     */
    private static function label(array $value): string
    {
        return trim((string) $value['code']) === trim((string) $value['name'])
            ? (string) $value['name']
            : DimensionValueLabel::full($value);
    }

    /** @return array{company_name:string, ic:string, dic:string} */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name' => (string) ($row['company_name'] ?? ''),
            'ic' => (string) ($row['ic'] ?? ''),
            'dic' => (string) ($row['dic'] ?? ''),
        ];
    }

    /** @return array{0:string, 1:string, 2:int|null} */
    public static function periodRange(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $start = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $quarter * 3)))->modify('last day of this month')->format('Y-m-d');
            return [$start, $end, $quarter];
        }
        $start = sprintf('%04d-%02d-01', $year, $month);
        return [$start, (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'), null];
    }
}
