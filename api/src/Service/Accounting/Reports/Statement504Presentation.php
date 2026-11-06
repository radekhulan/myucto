<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class Statement504Presentation
{
    public static function accepts(array $data): bool
    {
        return ($data['accounting_framework'] ?? '') === 'cz_504'
            && in_array($data['statement_type'] ?? '', ['balance_sheet', 'income_statement', 'account_view'], true);
    }

    public static function build(array $data, string $unit = 'czk', string $part = 'balance'): array
    {
        if (!self::accepts($data) || !isset($data['period']['starts_on'], $data['period']['ends_on'], $data['as_of'])
            || !in_array($unit, ['czk', 'thousands'], true)) {
            throw new ReportException('statement_export_invalid', 'Podklady výkazu 504 pro export nejsou úplné nebo platné.');
        }
        $type = $data['statement_type'];
        $management = $type === 'account_view';
        $income = $type === 'income_statement' || ($management && $part === 'profit_loss');
        $required = $management ? ['balances', 'profit_loss'] : ($income ? ['rows'] : ['assets', 'liabilities']);
        if (($management && !in_array($part, ['balance', 'profit_loss'], true))
            || array_filter($required, static fn (string $key): bool => !isset($data[$key]) || !is_array($data[$key])) !== []) {
            throw new ReportException('statement_export_invalid', 'Podklady výkazu 504 pro export nejsou úplné nebo platné.');
        }
        $tables = [];
        if ($income) {
            $tables[] = ['title' => 'Výkaz zisku a ztráty', 'headers' => ['Označení', 'Položka', 'Hlavní činnost', 'Hospodářská činnost', 'Celkem'],
                'rows' => self::statementRows($data[$management ? 'profit_loss' : 'rows'] ?? [], ['main', 'economic', 'total'], $unit)];
        } elseif ($management) {
            $tables[] = ['title' => 'Zůstatky rozvahových účtů', 'headers' => ['Účet', 'Název', 'Počáteční zůstatek MD', 'Konečný zůstatek MD'],
                'rows' => array_map(static fn (array $r): array => [$r['code'], $r['name'], $r['opening_debit_balance'], $r['closing_debit_balance']], $data['balances'] ?? [])];
        } else {
            foreach (['assets' => 'Aktiva', 'liabilities' => 'Pasiva'] as $key => $title) {
                $tables[] = ['title' => $title, 'headers' => ['Označení', 'Položka', 'Stav k prvnímu dni', 'Stav k rozvahovému dni'],
                    'rows' => self::statementRows(array_values(array_filter($data[$key] ?? [], static fn (array $r): bool => ($r['start'] ?? 0) != 0 || ($r['end'] ?? 0) != 0 || !empty($r['components']))), ['start', 'end'], $unit)];
            }
        }
        foreach ($tables as &$table) {
            foreach ($table['rows'] as &$row) {
                foreach ($row as $i => &$value) {
                    if ($i >= 2 && $value !== null) $value = $unit === 'thousands' ? (int) round($value / 1000) : (float) $value;
                }
                unset($value);
            }
            unset($row);
        }
        unset($table);
        $date = static fn (string $v): string => (new \DateTimeImmutable($v))->format('d.m.Y');
        return (isset($data['deferred_tax_method']) ? ['deferred_tax_note' => DeferredTax504Statement::methodNote($data['deferred_tax_method'])] : []) + ['title' => $management ? 'Průběžný účetní přehled' : ($income ? 'Výkaz zisku a ztráty' : 'Rozvaha'),
            'legal' => 'Vyhláška č. 504/2002 Sb.', 'unit_label' => $unit === 'thousands' ? 'v celých tisících Kč' : 'v Kč',
            'scope_label' => ($data['scope'] ?? 'full') === 'full' ? 'Plný rozsah' : 'Zkrácený rozsah',
            'period_label' => $date($data['period']['starts_on']) . ' - ' . $date($data['period']['ends_on']),
            'date_label' => $date($data['as_of']), 'entity' => $data['entity'] ?? [], 'tables' => $tables,
            'management' => $management, 'interim' => $data['as_of'] !== $data['period']['ends_on'],
            'checks' => $data['checks'] ?? [], 'dimension' => $data['dimension'] ?? null,
            'rounding_note' => $unit === 'thousands' ? 'Hodnoty jsou zaokrouhleny po řádcích podle § 4 odst. 3 vyhlášky č. 504/2002 Sb. Součty vycházejí z částek v Kč.' : null];
    }

    private static function statementRows(array $source, array $columns, string $unit): array
    {
        $out = [];
        foreach ($source as $row) {
            $out[] = [$row['display_code'], $row['label'], ...array_map(static fn (string $column): mixed => $row[$column], $columns)];
            $components = $row['components'] ?? [];
            if ($components === []) continue;
            foreach ($columns as $column) {
                $total = (int) round($row[$column] * 100);
                $sum = array_sum(array_map(static fn (array $part): int => (int) round($part[$column] * 100), $components));
                if ($sum !== $total) throw new ReportException('statement_export_invalid', 'Podpoložky odložené daně nesouhlasí s částkou výkazu.');
                if ($unit === 'thousands') {
                    $rounded = array_map(static fn (array $part): int => (int) round($part[$column] / 1000), $components);
                    $rounded[0] += (int) round($row[$column] / 1000) - array_sum($rounded);
                    foreach ($rounded as $i => $value) $components[$i][$column] = $value * 1000;
                }
            }
            foreach ($components as $part) $out[] = ['', $part['label'], ...array_map(static fn (string $column): mixed => $part[$column], $columns)];
        }
        return $out;
    }
}
