<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\TaxEvidence\CashJournalDimensionService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;

/**
 * Výsledovka po dimenzi: řádky = hodnoty jednoho typu (strom), sloupce = výnosy
 * (třída 6), náklady (třída 5) a výsledek. Počítá se z deníku, ze zaúčtovaných
 * zápisů bez uzávěrkového převodu výsledkových účtů (jako výkazy).
 *
 * Hodnota ve stromu má vlastní částky (řádky přímo s ní) a souhrn za celou větev.
 * Řádky bez hodnoty daného typu tvoří řádek „bez hodnoty", takže součet sestavy
 * sedí na výsledek hospodaření firmy za období. Řádek s rozpadem mezi víc hodnot
 * se rozdělí po haléřích ({@see DimensionSplitAllocation}), stejně jako ve výkazech
 * filtrovaných na hodnotu.
 *
 * Výběr kořenů sestavy:
 *   • bez omezení = hodnoty nejvyšší úrovně + „bez hodnoty" (součet = VH firmy),
 *   • `value_id` = jen větev jedné hodnoty (účelová výsledovka projektu a jeho etap),
 *   • `responsible_user_id` = hodnoty s touto odpovědnou osobou včetně jejich větví;
 *     hodnota ležící ve větvi jiné vybrané hodnoty se nesčítá podruhé.
 * S omezením se řádek „bez hodnoty" nevykazuje a součet je součtem kořenů.
 *
 * `accounts` přidá rozpad po syntetických účtech: řádky = účty, sloupce = kořeny
 * (a „bez hodnoty"), buňka = částka větve kořene na účtu.
 *
 * Globální typ lze sečíst za víc firem skupiny najednou (projekt vedený přes
 * mateřskou firmu i SPV); které firmy smí do součtu, určuje volající.
 */
final class DimensionProfitService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $dimensions,
        private readonly AccountingModeRepository $modes,
        private readonly CashJournalDimensionService $cashJournal,
    ) {}

    /**
     * @param list<int> $supplierIds firmy do součtu (první je aktuální firma)
     * @param array{value_id?:?int, responsible_user_id?:?int, accounts?:bool, companies?:bool} $options
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $typeId, string $from, string $to, array $supplierIds, array $options = []): array
    {
        $type = $this->dimensions->findType($supplierId, $typeId);
        if ($type === null) {
            throw new ReportException('not_found', 'Typ dimenze nenalezen.', 404);
        }
        $values = $this->dimensions->listValues($supplierId, $typeId);
        $rootValueId = (int) ($options['value_id'] ?? 0);
        $responsible = (int) ($options['responsible_user_id'] ?? 0);
        $withAccounts = (bool) ($options['accounts'] ?? false);
        $withCompanies = (bool) ($options['companies'] ?? false);

        $known = [];
        foreach ($values as $v) {
            $known[$v['id']] = $v;
        }

        // hodnota ('' = bez hodnoty) => syntetický účet => ['revenue' => haléře, 'cost' => haléře]
        $direct = [];
        $companyDirect = [];
        $accounts = [];
        foreach ($supplierIds as $sid) {
            foreach ($this->sums($sid, $typeId, $from, $to) as $row) {
                $key = $row['value_key'];
                $code = $row['code'];
                $direct[$key][$code]['revenue'] = ($direct[$key][$code]['revenue'] ?? 0) + $row['revenue'];
                $direct[$key][$code]['cost'] = ($direct[$key][$code]['cost'] ?? 0) + $row['cost'];
                if ($withCompanies) {
                    $companyKey = $key !== '' && !isset($known[(int) $key]) ? '' : $key;
                    $companyDirect[$sid][$companyKey][$code]['revenue'] = ($companyDirect[$sid][$companyKey][$code]['revenue'] ?? 0) + $row['revenue'];
                    $companyDirect[$sid][$companyKey][$code]['cost'] = ($companyDirect[$sid][$companyKey][$code]['cost'] ?? 0) + $row['cost'];
                }
                $accounts[$code] ??= ['code' => $code, 'name' => $row['name'], 'account_type' => $row['account_type']];
            }
        }

        // Řádky s hodnotou, kterou firma nevidí (firma mezitím skupinu opustila),
        // se nesmí ztratit — patří k řádku „bez hodnoty".
        foreach (array_keys($direct) as $key) {
            if ($key !== '' && !isset($known[(int) $key])) {
                foreach ($direct[$key] as $code => $sum) {
                    $direct[''][$code]['revenue'] = ($direct[''][$code]['revenue'] ?? 0) + $sum['revenue'];
                    $direct[''][$code]['cost'] = ($direct[''][$code]['cost'] ?? 0) + $sum['cost'];
                }
                unset($direct[$key]);
            }
        }

        $byParent = [];
        foreach ($values as $v) {
            $parent = $v['parent_id'] !== null && isset($known[$v['parent_id']]) ? $v['parent_id'] : 0;
            $byParent[$parent][] = $v;
        }

        if ($rootValueId > 0) {
            if (!isset($known[$rootValueId])) {
                throw new ReportException('not_found', 'Hodnota dimenze nenalezena.', 404);
            }
            $roots = [$known[$rootValueId]];
        } elseif ($responsible > 0) {
            $roots = self::responsibleRoots($values, $known, $responsible);
        } else {
            $roots = $byParent[0] ?? [];
        }
        $restricted = $rootValueId > 0 || $responsible > 0;

        $rows = [];
        $rootTotals = [];
        $walk = function (array $value, int $depth) use (&$walk, &$rows, $byParent, $direct): array {
            $index = count($rows);
            $rows[] = null;
            $own = $direct[(string) $value['id']] ?? [];
            $total = $own;
            foreach ($byParent[$value['id']] ?? [] as $child) {
                $total = self::addAccounts($total, $walk($child, $depth + 1));
            }
            $rows[$index] = [
                'value_id' => $value['id'],
                'parent_id' => $value['parent_id'],
                'code' => $value['code'],
                'name' => $value['name'],
                'is_active' => $value['is_active'],
                'responsible_user_id' => $value['responsible_user_id'] ?? null,
                'responsible_user_name' => $value['responsible_user_name'] ?? null,
                'depth' => $depth,
                'has_children' => isset($byParent[$value['id']]),
                'own' => self::money(self::collapse($own)),
                'total' => self::money(self::collapse($total)),
            ];
            return $total;
        };
        foreach ($roots as $root) {
            $rootTotals[$root['id']] = $walk($root, 0);
        }

        $unassigned = $restricted ? [] : ($direct[''] ?? []);
        $all = $unassigned;
        foreach ($rootTotals as $t) {
            $all = self::addAccounts($all, $t);
        }

        $out = [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'supplier_ids' => $supplierIds,
            'basis' => $this->basis($supplierId, (int) substr($from, 0, 4)),
            'value_id' => $rootValueId > 0 ? $rootValueId : null,
            'responsible_user_id' => $responsible > 0 ? $responsible : null,
            'restricted' => $restricted,
            'rows' => $rows,
            'unassigned' => self::money(self::collapse($unassigned)),
            'totals' => self::money(self::collapse($all)),
        ];
        if ($withAccounts) {
            $out['matrix'] = self::matrix($accounts, $roots, $rootTotals, $restricted ? null : $unassigned, $all);
        }
        if ($withCompanies) {
            $out['companies'] = [];
            foreach ($supplierIds as $sid) {
                $companyTotal = $restricted ? [] : ($companyDirect[$sid][''] ?? []);
                foreach ($rows as $row) {
                    $companyTotal = self::addAccounts($companyTotal, $companyDirect[$sid][(string) $row['value_id']] ?? []);
                }
                $out['companies'][] = [
                    'id' => $sid,
                    'name' => $this->dimensions->supplierName($sid),
                ] + self::money(self::collapse($companyTotal));
            }
        }
        return $out;
    }

    /**
     * Roční grafy používají stejné zaúčtované řádky a rozdělení haléřů jako výsledovka.
     * @param list<array{id:int,company_name:string}> $companies
     * @return array<string,mixed>
     */
    public function analytics(int $supplierId, int $typeId, int $year, array $companies): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $ids = array_column($companies, 'id');
        $report = $this->build($supplierId, $typeId, $from, $to, $ids);
        $values = $this->dimensions->listValues($supplierId, $typeId);
        $byId = array_column($values, null, 'id');
        $roots = [];
        foreach ($values as $value) {
            $id = (int) $value['id'];
            $parent = $value['parent_id'];
            $seen = [$id => true];
            while ($parent !== null && isset($byId[$parent]) && !isset($seen[$parent])) {
                $id = (int) $parent;
                $seen[$id] = true;
                $parent = $byId[$id]['parent_id'];
            }
            $roots[$value['id']] = (string) $id;
        }

        $monthly = [];
        $previous = [];
        $companyTotals = [];
        $companyValueTotals = [];
        $valueMonths = [];
        $valueTotals = [];
        $yearTotals = [];
        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $monthly[$key] = ['revenue' => 0, 'cost' => 0];
            $previous[sprintf('%04d-%02d', $year - 1, $month)] = ['revenue' => 0, 'cost' => 0];
        }
        foreach ($companies as $company) {
            $sid = $company['id'];
            $companyTotals[$sid] = ['id' => $sid, 'name' => $company['company_name'], 'revenue' => 0, 'cost' => 0];
            foreach ($this->sums($sid, $typeId, sprintf('%04d-01-01', $year - 1), $to, true) as $row) {
                $key = $row['month_key'];
                if (isset($monthly[$key])) {
                    self::addAnalyticsAmount($monthly[$key], $row);
                    self::addAnalyticsAmount($yearTotals, $row);
                    self::addAnalyticsAmount($companyTotals[$sid], $row);
                    $root = $roots[(int) $row['value_key']] ?? '';
                    $valueMonths[$root][$key] ??= [];
                    $valueTotals[$root] ??= [];
                    $companyValueTotals[$sid][$root] ??= [];
                    self::addAnalyticsAmount($valueMonths[$root][$key], $row);
                    self::addAnalyticsAmount($valueTotals[$root], $row);
                    self::addAnalyticsAmount($companyValueTotals[$sid][$root], $row);
                } elseif (isset($previous[$key])) {
                    self::addAnalyticsAmount($previous[$key], $row);
                }
            }
        }

        $toMoney = static fn (string $key, array $sum): array => ['month' => $key] + self::analyticsMoney($sum);
        $series = [];
        foreach ($valueMonths as $valueId => $months) {
            $series[(string) $valueId] = array_map(
                static fn (string $key): array => $toMoney($key, $months[$key] ?? ['revenue' => 0, 'cost' => 0]),
                array_keys($monthly),
            );
        }
        $companyValues = [];
        foreach ($companyValueTotals as $sid => $values) {
            foreach ($values as $valueId => $sum) {
                $companyValues[$sid][(string) $valueId] = self::analyticsMoney($sum);
            }
            $companyValues[$sid] = (object) $companyValues[$sid];
        }

        return [
            'type' => $report['type'],
            'year' => $year,
            'supplier_ids' => $ids,
            'basis' => $this->basis($supplierId, $year),
            'rows' => $report['rows'],
            'unassigned' => self::analyticsMoney($valueTotals[''] ?? []),
            'totals' => self::analyticsMoney($yearTotals),
            'value_totals' => (object) array_map([self::class, 'analyticsMoney'], $valueTotals),
            'monthly' => array_map($toMoney, array_keys($monthly), array_values($monthly)),
            'previous_monthly' => array_map($toMoney, array_keys($previous), array_values($previous)),
            'value_monthly' => (object) $series,
            'companies' => array_map(static fn (array $company): array => [
                'id' => $company['id'],
                'name' => $company['name'],
            ] + self::analyticsMoney($company), array_values($companyTotals)),
            'company_value_totals' => (object) $companyValues,
        ];
    }

    /**
     * Hodnoty s odpovědnou osobou, které neleží ve větvi jiné takové hodnoty.
     *
     * @param list<array<string,mixed>> $values
     * @param array<int,array<string,mixed>> $known
     * @return list<array<string,mixed>>
     */
    private static function responsibleRoots(array $values, array $known, int $userId): array
    {
        $matched = [];
        foreach ($values as $v) {
            if ((int) ($v['responsible_user_id'] ?? 0) === $userId) {
                $matched[$v['id']] = true;
            }
        }
        $roots = [];
        foreach ($values as $v) {
            if (!isset($matched[$v['id']])) {
                continue;
            }
            $parent = $v['parent_id'];
            $seen = [];
            while ($parent !== null && isset($known[$parent]) && !isset($seen[$parent])) {
                if (isset($matched[$parent])) {
                    continue 2;
                }
                $seen[$parent] = true;
                $parent = $known[$parent]['parent_id'];
            }
            $roots[] = $v;
        }
        return $roots;
    }

    /**
     * @param array<string,array{code:string,name:string,account_type:string}> $accounts
     * @param list<array<string,mixed>> $roots
     * @param array<int,array<string,array{revenue:int,cost:int}>> $rootTotals
     * @param array<string,array{revenue:int,cost:int}>|null $unassigned
     * @param array<string,array{revenue:int,cost:int}> $all
     * @return array<string,mixed>
     */
    private static function matrix(array $accounts, array $roots, array $rootTotals, ?array $unassigned, array $all): array
    {
        $columns = [];
        foreach ($roots as $root) {
            $columns[] = ['key' => (string) $root['id'], 'value_id' => $root['id'], 'code' => $root['code'], 'name' => $root['name']];
        }
        if ($unassigned !== null) {
            $columns[] = ['key' => '', 'value_id' => null, 'code' => '', 'name' => null];
        }
        uasort($accounts, static function (array $a, array $b): int {
            // Výnosy nahoře, pak náklady; uvnitř podle čísla účtu.
            $order = ($a['account_type'] === 'revenue' ? 0 : 1) <=> ($b['account_type'] === 'revenue' ? 0 : 1);
            return $order !== 0 ? $order : strcmp($a['code'], $b['code']);
        });
        $amount = static function (?array $sum, string $type): float {
            if ($sum === null) {
                return 0.0;
            }
            return ($type === 'revenue' ? $sum['revenue'] : $sum['cost']) / 100;
        };
        $rows = [];
        foreach ($accounts as $code => $acc) {
            $code = (string) $code;
            $cells = [];
            $nonZero = false;
            foreach ($roots as $root) {
                $cells[] = $v = $amount($rootTotals[$root['id']][$code] ?? null, $acc['account_type']);
                $nonZero = $nonZero || $v !== 0.0;
            }
            if ($unassigned !== null) {
                $cells[] = $v = $amount($unassigned[$code] ?? null, $acc['account_type']);
                $nonZero = $nonZero || $v !== 0.0;
            }
            if (!$nonZero) {
                continue;
            }
            $rows[] = [
                'code' => $code,
                'name' => $acc['name'],
                'account_type' => $acc['account_type'],
                'cells' => $cells,
                'total' => $amount($all[$code] ?? null, $acc['account_type']),
            ];
        }
        $results = [];
        foreach ($roots as $root) {
            $results[] = self::money(self::collapse($rootTotals[$root['id']]))['result'];
        }
        if ($unassigned !== null) {
            $results[] = self::money(self::collapse($unassigned))['result'];
        }
        return [
            'columns' => $columns,
            'rows' => $rows,
            'results' => $results,
            'total_result' => self::money(self::collapse($all))['result'],
        ];
    }

    /**
     * Součty v haléřích podle hodnoty ('' = řádek bez hodnoty) a syntetického účtu.
     *
     * @return list<array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}>
     */
    private function sums(int $supplierId, int $typeId, string $from, string $to, bool $monthly = false): array
    {
        // Rok v daňové evidenci nemá deník; sestava stojí na peněžním deníku
        // ({@see CashJournalDimensionService}). Podvojné roky jdou beze změny z deníku.
        $out = [];
        $journalFrom = null;
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            $start = max($from, sprintf('%04d-01-01', $year));
            $end = min($to, sprintf('%04d-12-31', $year));
            if ($this->modes->forYear($supplierId, $year) === 'tax_evidence') {
                if ($journalFrom !== null) {
                    array_push($out, ...$this->journalSums($supplierId, $typeId, $journalFrom, sprintf('%04d-12-31', $year - 1), $monthly));
                    $journalFrom = null;
                }
                array_push($out, ...$this->cashJournal->sums($supplierId, $typeId, $start, $end, $monthly));
            } else {
                $journalFrom ??= $start;
            }
        }
        if ($journalFrom !== null) {
            array_push($out, ...$this->journalSums($supplierId, $typeId, $journalFrom, $to, $monthly));
        }
        return $out;
    }

    /**
     * Zda firma vede v roce daňovou evidenci — sestava pak ukazuje příjmy a výdaje
     * peněžního deníku místo výnosů a nákladů.
     */
    public function basis(int $supplierId, int $year): string
    {
        return $this->modes->forYear($supplierId, $year) === 'tax_evidence' ? 'cash_journal' : 'journal';
    }

    /**
     * @return list<array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}>
     */
    private function journalSums(int $supplierId, int $typeId, string $from, string $to, bool $monthly = false): array
    {
        $monthSelect = $monthly ? "DATE_FORMAT(e.entry_date, '%Y-%m') AS month_key, " : '';
        $monthGroup = $monthly ? "DATE_FORMAT(e.entry_date, '%Y-%m'), " : '';
        $nonDeductible = NonDeductibleCostsService::predicate();
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT {$monthSelect}COALESCE(jd.dimension_value_id, ccv.id) AS value_id,
                   COALESCE(p.account_code, a.account_code) AS code,
                   COALESCE(p.name, a.name) AS name,
                   a.account_type,
                   SUM(CASE WHEN a.account_type = 'revenue'
                            THEN CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS revenue,
                   SUM(CASE WHEN a.account_type = 'expense'
                            THEN CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS cost,
                   SUM(CASE WHEN a.account_type = 'expense' AND {$nonDeductible}
                            THEN CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS non_deductible_cost,
                   SUM(CASE WHEN a.account_type = 'expense' AND a.account_code LIKE '59%'
                            THEN CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) AS income_tax_cost
              FROM journal_entry_lines l
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
         LEFT JOIN purchase_invoices pi ON " . JournalTaxOrigin::sourceTypeSql() . " = 'purchase_invoice'
                                       AND pi.id = " . JournalTaxOrigin::sourceIdSql() . "
                                       AND pi.supplier_id = e.supplier_id
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
         LEFT JOIN journal_entry_line_dimensions jd
                ON jd.line_id = l.id AND jd.dimension_type_id = ?
         LEFT JOIN cost_centers cc
                ON jd.line_id IS NULL AND l.cost_center IS NOT NULL
               AND cc.supplier_id = l.supplier_id AND cc.code = l.cost_center
         LEFT JOIN dimension_values ccv ON ccv.cost_center_id = cc.id AND ccv.type_id = ?
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND a.account_type IN ('revenue', 'expense')
               AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s
                                WHERE s.line_id = l.id AND s.dimension_type_id = ?)
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY ' . $monthGroup . 'COALESCE(jd.dimension_value_id, ccv.id), COALESCE(p.account_code, a.account_code),
                      COALESCE(p.name, a.name), a.account_type'
        );
        $stmt->execute([$typeId, $typeId, $supplierId, $from, $to, $typeId, ClosingSourceId::STOCK_SLOT_BASE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::sumRow($r);
        }
        return [...$out, ...$this->splitSums($supplierId, $typeId, $from, $to, $monthly)];
    }

    /**
     * Řádky s rozpadem mezi víc hodnot typu: díl každé hodnoty z téhož pravidla jako
     * filtr výkazů ({@see DimensionSplitAllocation}), takže součet sestavy dál sedí
     * na výsledek firmy a řádek hodnoty na výkaz filtrovaný na tutéž hodnotu.
     *
     * @return list<array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}>
     */
    private function splitSums(int $supplierId, int $typeId, string $from, string $to, bool $monthly = false): array
    {
        $monthSelect = $monthly ? "DATE_FORMAT(e.entry_date, '%Y-%m') AS month_key, " : '';
        $monthGroup = $monthly ? "DATE_FORMAT(e.entry_date, '%Y-%m'), " : '';
        $nonDeductible = NonDeductibleCostsService::predicate();
        [$partsSql, $partsParams] = DimensionSplitAllocation::partsSql($typeId, null, $supplierId);
        // Rozdělení haléřů zůstává kladné; znaménko storna se přidá až k dílu řádku.
        $signedPart = '(CASE WHEN l.is_red_storno = 1 THEN -sp.amount ELSE sp.amount END)';
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT {$monthSelect}sp.value_id,
                   COALESCE(p.account_code, a.account_code) AS code,
                   COALESCE(p.name, a.name) AS name,
                   a.account_type,
                   SUM(CASE WHEN a.account_type = 'revenue'
                            THEN CASE WHEN l.side = 'credit' THEN {$signedPart} ELSE -{$signedPart} END ELSE 0 END) AS revenue,
                   SUM(CASE WHEN a.account_type = 'expense'
                            THEN CASE WHEN l.side = 'debit' THEN {$signedPart} ELSE -{$signedPart} END ELSE 0 END) AS cost,
                   SUM(CASE WHEN a.account_type = 'expense' AND {$nonDeductible}
                            THEN CASE WHEN l.side = 'debit' THEN {$signedPart} ELSE -{$signedPart} END ELSE 0 END) AS non_deductible_cost,
                   SUM(CASE WHEN a.account_type = 'expense' AND a.account_code LIKE '59%'
                            THEN CASE WHEN l.side = 'debit' THEN {$signedPart} ELSE -{$signedPart} END ELSE 0 END) AS income_tax_cost
              FROM ({$partsSql}) sp
              JOIN journal_entry_lines l ON l.id = sp.line_id
              JOIN journal_entries e ON e.id = l.entry_id
              " . JournalTaxOrigin::join() . "
         LEFT JOIN purchase_invoices pi ON " . JournalTaxOrigin::sourceTypeSql() . " = 'purchase_invoice'
                                       AND pi.id = " . JournalTaxOrigin::sourceIdSql() . "
                                       AND pi.supplier_id = e.supplier_id
              JOIN chart_of_accounts a ON a.id = l.account_id
         LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
             WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
               AND e.entry_date BETWEEN ? AND ?
               AND a.account_type IN ('revenue', 'expense')
               AND " . JournalTaxOrigin::includedSql() . '
             GROUP BY ' . $monthGroup . 'sp.value_id, COALESCE(p.account_code, a.account_code), COALESCE(p.name, a.name), a.account_type'
        );
        $stmt->execute([...$partsParams, $supplierId, $from, $to, ClosingSourceId::STOCK_SLOT_BASE]);
        return array_map([self::class, 'sumRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $r
     * @return array{value_key:string, code:string, name:string, account_type:string, revenue:int, cost:int}
     */
    private static function sumRow(array $r): array
    {
        return [
            'value_key' => $r['value_id'] === null ? '' : (string) (int) $r['value_id'],
            'month_key' => (string) ($r['month_key'] ?? ''),
            'code' => (string) $r['code'],
            'name' => (string) $r['name'],
            'account_type' => (string) $r['account_type'],
            'revenue' => (int) round(((float) $r['revenue']) * 100),
            'cost' => (int) round(((float) $r['cost']) * 100),
            'non_deductible_cost' => (int) round(((float) $r['non_deductible_cost']) * 100),
            'income_tax_cost' => (int) round(((float) $r['income_tax_cost']) * 100),
        ];
    }

    /**
     * @param array<string,array{revenue:int,cost:int}> $a
     * @param array<string,array{revenue:int,cost:int}> $b
     * @return array<string,array{revenue:int,cost:int}>
     */
    private static function addAccounts(array $a, array $b): array
    {
        foreach ($b as $code => $sum) {
            $a[$code]['revenue'] = ($a[$code]['revenue'] ?? 0) + $sum['revenue'];
            $a[$code]['cost'] = ($a[$code]['cost'] ?? 0) + $sum['cost'];
        }
        return $a;
    }

    /**
     * @param array<string,array{revenue:int,cost:int}> $byAccount
     * @return array{revenue:int,cost:int}
     */
    private static function collapse(array $byAccount): array
    {
        $sum = ['revenue' => 0, 'cost' => 0];
        foreach ($byAccount as $s) {
            $sum['revenue'] += $s['revenue'];
            $sum['cost'] += $s['cost'];
        }
        return $sum;
    }

    /**
     * @param array{revenue:int,cost:int} $cents
     * @return array{revenue:float,cost:float,result:float}
     */
    private static function money(array $cents): array
    {
        return [
            'revenue' => $cents['revenue'] / 100,
            'cost' => $cents['cost'] / 100,
            'result' => ($cents['revenue'] - $cents['cost']) / 100,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function addAnalyticsAmount(array &$sum, array $row): void
    {
        foreach (['revenue', 'cost', 'non_deductible_cost', 'income_tax_cost'] as $key) {
            $sum[$key] = ($sum[$key] ?? 0) + ($row[$key] ?? 0);
        }
    }

    /** @param array<string,mixed> $cents */
    private static function analyticsMoney(array $cents): array
    {
        $revenue = (int) ($cents['revenue'] ?? 0);
        $cost = (int) ($cents['cost'] ?? 0);
        $nonDeductible = (int) ($cents['non_deductible_cost'] ?? 0);
        $incomeTax = (int) ($cents['income_tax_cost'] ?? 0);
        return [
            'revenue' => $revenue / 100,
            'cost' => $cost / 100,
            'result' => ($revenue - $cost) / 100,
            'tax_deductible_cost' => ($cost - $nonDeductible - $incomeTax) / 100,
            'non_deductible_cost' => $nonDeductible / 100,
            'income_tax_cost' => $incomeTax / 100,
        ];
    }
}
