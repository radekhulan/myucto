<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class Statement504Builder
{
    public function __construct(private readonly ?array $definitions = null, private readonly ?array $maps = null) {}

    /** @param list<array{code:string,activity:?string,balance_cents:int}> $postedRows */
    public function incomeStatement(array $postedRows, string $scope): array
    {
        $this->assertScope($scope);
        $values = ['main' => [], 'economic' => []];
        $profit = 0;
        foreach ($postedRows as $line) {
            if (!in_array($line['activity'], ['main', 'economic'], true)) {
                throw new ReportException('statement_activity_invalid', 'Zařazení činnosti na zaúčtovaném řádku chybí nebo není platné.');
            }
            $amount = $line['balance_cents'];
            $mapping = $this->mapping('income_statement', $line['code'], $amount);
            $number = (int) substr($mapping['row_code'], 3);
            $profit -= $amount;
            $values[$line['activity']][$number] = ($values[$line['activity']][$number] ?? 0)
                + $amount * (int) $mapping['sign'];
        }
        $rows = $this->rows('UT');
        foreach ($values as &$column) {
            $column = $this->rollup($rows, $column);
            $column = self::profitTotals($column);
        }
        unset($column);
        $out = [];
        foreach ($rows as $row) {
            if ($scope !== 'full' && !$row['reduced']) {
                continue;
            }
            $n = $row['number'];
            $out[] = $this->rowMetadata($row) + [
                'main' => $values['main'][$n] / 100.0,
                'economic' => $values['economic'][$n] / 100.0,
                'total' => ($values['main'][$n] + $values['economic'][$n]) / 100.0,
            ];
        }
        return ['rows' => $out, 'checks' => [
            'profit_current' => $profit / 100.0,
            'profit_matches' => $profit === $values['main'][63] + $values['economic'][63],
        ]];
    }

    /**
     * @param list<array{code:string,opening_cents:int,closing_cents:int}> $balances
     * @param list<array{code:string,activity:?string,balance_cents:int}> $postedRows
     */
    public function balanceSheet(array $balances, array $postedRows, string $scope): array
    {
        $this->assertScope($scope);
        $profit = $this->incomeStatement($postedRows, 'full');
        $profitCents = array_sum(array_map(static fn (array $r): int => -$r['balance_cents'], $postedRows));
        $values = ['UR' => ['start' => [], 'end' => []], 'US' => ['start' => [], 'end' => [7 => $profitCents]]];
        $pendingEnd = $currentStart = $currentEnd = 0;
        foreach ($balances as $balance) {
            foreach (['start' => 'opening_cents', 'end' => 'closing_cents'] as $column => $key) {
                $amount = $balance[$key];
                if ($amount === 0) {
                    continue;
                }
                $mapping = $this->mapping('balance_sheet', $balance['code'], $amount);
                $rowCode = $mapping['row_code'];
                if ($rowCode === 'US.7') {
                    if ($column === 'start') $currentStart -= $amount;
                    else $currentEnd -= $amount;
                    continue;
                }
                if ($rowCode === 'US.8' && $column === 'end') {
                    $pendingEnd -= $amount;
                    continue;
                }
                [$sentence, $number] = explode('.', $rowCode);
                $values[$sentence][$column][(int) $number] = ($values[$sentence][$column][(int) $number] ?? 0)
                    + $amount * (int) $mapping['sign'];
            }
        }
        if ($pendingEnd !== 0) {
            throw new ReportException('statement_result_pending_approval_unresolved', 'Před sestavením oficiální rozvahy 504 je nutné vypořádat výsledek ve schvalovacím řízení.');
        }
        if ($currentStart !== 0 || ($currentEnd !== 0 && $currentEnd !== $profitCents)) {
            throw new ReportException('statement_current_result_inconsistent', 'Účet výsledku běžného období nesouhlasí s výsledovkou.');
        }
        $out = [];
        foreach (['UR' => 'assets', 'US' => 'liabilities'] as $sentence => $section) {
            $rows = $this->rows($sentence);
            foreach (['start', 'end'] as $column) {
                $values[$sentence][$column] = $this->rollup($rows, $values[$sentence][$column]);
            }
            $out[$section] = [];
            foreach ($rows as $row) {
                if ($scope !== 'full' && !$row['reduced']) {
                    continue;
                }
                $n = $row['number'];
                $out[$section][] = $this->rowMetadata($row) + [
                    'start' => $sentence === 'US' && $n === 7 ? null : $values[$sentence]['start'][$n] / 100.0,
                    'end' => $sentence === 'US' && $n === 8 ? null : $values[$sentence]['end'][$n] / 100.0,
                ];
            }
        }
        $out['totals'] = [
            'assets_start' => $values['UR']['start'][85] / 100.0,
            'assets_end' => $values['UR']['end'][85] / 100.0,
            'liabilities_start' => $values['US']['start'][49] / 100.0,
            'liabilities_end' => $values['US']['end'][49] / 100.0,
        ];
        $out['checks'] = [
            'balanced' => $values['UR']['start'][85] === $values['US']['start'][49]
                && $values['UR']['end'][85] === $values['US']['end'][49],
            'profit_current' => $profit['checks']['profit_current'],
            'profit_matches' => $values['US']['end'][7] === $profitCents,
        ];
        return $out;
    }

    public function rollup(array $rows, array $values): array
    {
        $index = [];
        foreach ($rows as $row) {
            $values[$row['number']] ??= 0;
            if ($row['display_code'] !== '') {
                $index[rtrim($row['display_code'], '.')] = $row['number'];
            }
        }
        foreach (array_reverse($rows) as $row) {
            $code = rtrim($row['display_code'], '.');
            $dot = strrpos($code, '.');
            if ($dot !== false) {
                $parent = $index[substr($code, 0, $dot)] ?? null;
                if ($parent === null) {
                    throw new \LogicException('Invalid 504 statement hierarchy.');
                }
                $values[$parent] += $values[$row['number']];
            }
        }
        if ($rows[0]['sentence'] === 'UR') {
            $values[85] = $values[1] + $values[41];
        } elseif ($rows[0]['sentence'] === 'US') {
            $values[49] = $values[1] + $values[10];
        }
        return $values;
    }

    public static function profitTotals(array $column): array
    {
        $column[39] = $column[1];
        $column[61] = $column[40];
        $column[63] = $column[61] - $column[39];
        $column[62] = $column[63] + $column[37];
        return $column;
    }

    private function rowMetadata(array $row): array
    {
        return [
            'row_code' => $row['sentence'] . '.' . $row['number'],
            'adis_sentence' => $row['sentence'],
            'adis_row' => $row['number'],
            'display_code' => $row['display_code'],
            'label' => $row['label'],
        ];
    }

    private function rows(string $sentence): array
    {
        return $this->definitions[$sentence] ?? Statement504Catalog::rows($sentence);
    }

    public function assetsNet(array $balances): float
    {
        $total = 0;
        foreach ($balances as $balance) {
            if ($balance['closing_cents'] === 0) continue;
            $mapping = $this->mapping('balance_sheet', $balance['code'], $balance['closing_cents']);
            if (str_starts_with($mapping['row_code'], 'UR.')) $total += $balance['closing_cents'] * (int) $mapping['sign'];
        }
        return $total / 100.0;
    }

    private function mapping(string $type, string $code, int $balance): array
    {
        $map = $this->maps[$type] ?? Statement504Catalog::accountMap($type);
        $matches = (new StatementMapper())->entriesFor($map, $code, $balance);
        if (count($matches) !== 1) {
            throw new ReportException('statement_account_unmapped', 'Účet nemá jednoznačné přiřazení k řádku výkazu 504.');
        }
        return $matches[0];
    }

    private function assertScope(string $scope): void
    {
        if (!in_array($scope, ['full', 'small', 'micro'], true)) {
            throw new ReportException('invalid_statement_scope', 'Neplatný rozsah účetního výkazu.');
        }
    }
}
