<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;

final class DimensionAnalyticsTables
{
    /** @return array{title:string,subtitle:string,headers:list<string>,rows:list<list<string|float>>,totals:list<string|float>,filename:string} */
    public static function build(array $data, string $table, string $valueId = 'total', string $metric = 'result'): array
    {
        $year = (int) $data['year'];
        $typeName = (string) $data['type']['name'];
        $values = [];
        foreach ($data['rows'] as $row) {
            if ($row['depth'] !== 0) continue;
            $key = (string) $row['value_id'];
            $values[$key] = $row['code'] === $row['name'] ? $row['name'] : DimensionValueLabel::full($row);
        }
        $values['unassigned'] = 'Bez hodnoty';
        if ($valueId !== 'total' && !array_key_exists($valueId, $values)) {
            throw new \InvalidArgumentException('Hodnota dimenze nenalezena.');
        }
        $selected = $valueId === 'total' ? 'Celkem' : $values[$valueId];
        $key = $valueId === 'unassigned' ? '' : $valueId;
        $subtitle = $typeName . ' · ' . $year . ' · ' . $selected;
        if (count($data['supplier_ids']) > 1) $subtitle .= ' · ' . count($data['supplier_ids']) . ' firem';

        if ($table === 'comparison') {
            $columns = ['revenue' => 'Výnosy', 'cost' => 'Náklady'];
            $totals = $data['totals'];
            if ((float) $totals['non_deductible_cost'] !== 0.0 || (float) $totals['income_tax_cost'] !== 0.0) {
                if ((float) $totals['tax_deductible_cost'] !== 0.0) $columns['tax_deductible_cost'] = 'Daňové náklady';
                if ((float) $totals['non_deductible_cost'] !== 0.0) $columns['non_deductible_cost'] = 'Nedaňové náklady';
                if ((float) $totals['income_tax_cost'] !== 0.0) $columns['income_tax_cost'] = 'Daň z příjmů';
            }
            $columns['result'] = 'Zisk / ztráta';
            $columns['margin'] = 'Zisková marže (%)';
            $entries = [];
            foreach ($values as $id => $label) {
                $amounts = self::lookup($data['value_totals'], $id === 'unassigned' ? '' : (string) $id);
                if (self::empty($amounts)) continue;
                $entries[] = [$label, $amounts];
            }
            usort($entries, static fn (array $a, array $b): int => abs($b[1][$metric]) <=> abs($a[1][$metric]));
            $rows = array_map(static fn (array $entry): array => [$entry[0], ...self::amounts($entry[1], array_keys($columns))], $entries);
            $title = 'Porovnání hodnot dimenze';
            $headers = [$typeName, ...array_values($columns)];
        } elseif ($table === 'companies') {
            $rows = [];
            foreach ($data['companies'] as $company) {
                $companyValues = self::lookup($data['company_value_totals'], (string) $company['id']);
                $amounts = $valueId === 'total' ? $company : self::lookup($companyValues, $key);
                if (self::empty($amounts)) continue;
                $rows[] = [$company['name'], (float) $amounts['revenue'], (float) $amounts['cost'], (float) $amounts['result']];
            }
            $title = 'Porovnání firem';
            $headers = ['Firma', 'Výnosy', 'Náklady', 'Zisk / ztráta'];
        } elseif ($table === 'monthly') {
            $months = $valueId === 'total' ? $data['monthly'] : self::lookup($data['value_monthly'], $key);
            $rows = [];
            foreach ($months as $month) {
                if (self::empty($month)) continue;
                $rows[] = [$month['month'], (float) $month['revenue'], (float) $month['cost'], (float) $month['result']];
            }
            $title = 'Měsíční souhrn';
            $headers = ['Měsíc', 'Výnosy', 'Náklady', 'Zisk / ztráta'];
        } else {
            throw new \InvalidArgumentException('Tabulka nenalezena.');
        }

        $totals = ['Celkem'];
        for ($column = 1; $column < count($headers) - ($table === 'comparison' ? 1 : 0); $column++) {
            $totals[] = array_sum(array_column($rows, $column));
        }
        if ($table === 'comparison') {
            $resultIndex = array_search('Zisk / ztráta', $headers, true);
            $totals[] = (float) $totals[1] === 0.0 ? '-' : round($totals[$resultIndex] / $totals[1] * 100, 1);
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'headers' => $headers,
            'rows' => $rows,
            'totals' => $totals,
            'filename' => 'dimenze-' . $table . '-' . $year,
        ];
    }

    private static function empty(array $amounts): bool
    {
        return (float) ($amounts['revenue'] ?? 0) === 0.0 && (float) ($amounts['cost'] ?? 0) === 0.0 && (float) ($amounts['result'] ?? 0) === 0.0;
    }

    private static function lookup(array|object $values, string $key): array
    {
        return (array) (((array) $values)[$key] ?? []);
    }

    /** @return list<float|string> */
    private static function amounts(array $row, array $columns): array
    {
        return array_map(static fn (string $column): float|string => $column === 'margin'
            ? ((float) $row['revenue'] === 0.0 ? '-' : round((float) $row['result'] / (float) $row['revenue'] * 100, 1))
            : (float) ($row[$column] ?? 0), $columns);
    }
}
