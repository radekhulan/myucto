<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\AccountBalanceSign;

/**
 * Rozvaha a výsledovka po účtech ve sloupcích: aktiva vedle pasiv a náklady vedle výnosů,
 * u každého účtu počáteční stav, obrat měsíce, obrat od začátku období a konečný stav.
 *
 * Všechny sloupce jsou se znaménkem podle typu účtu ({@see AccountBalanceSign}), takže
 * u každého řádku platí PS + obrat od začátku období = KS. Skrývají se jen účty, které mají
 * ve všech čtyřech sloupcích nulu, takže skrytí nezmění žádný součet.
 *
 * Tvar řádků (`lines`) skládá jen tahle třída. Obrazovka i XLSX je kreslí beze změny, proto
 * mají stejný tvar i při zapnutých přepínačích.
 */
final class StatementAccountColumns
{
    private const AMOUNTS = ['ps', 'month', 'ytd', 'ks'];

    /**
     * @param list<array<string,mixed>> $raw řádky trialBalanceRows s analytikami
     * @param list<array{account_id:int, month:string, md:float, d:float}> $monthRows obraty měsíce
     * @param callable(string):string $sectionOf skupina výsledkového účtu podle mapy VZZ
     * @param list<string> $sectionKeys skupiny výsledovky v pořadí výkazu
     * @param array{hide_zero:bool, analytics:bool, from:string, month_from:string, as_of:string} $options
     * @return array<string,mixed>
     */
    public static function build(array $raw, array $monthRows, callable $sectionOf, array $sectionKeys, array $options): array
    {
        $month = [];
        foreach ($monthRows as $m) {
            $month[$m['account_id']] = ($month[$m['account_id']] ?? 0) + self::cents($m['md']) - self::cents($m['d']);
        }

        $balance = [];
        $profitLoss = array_fill_keys($sectionKeys, []);
        foreach ($raw as $r) {
            $type = (string) $r['account_type'];
            if (in_array($type, ['asset', 'liability', 'equity'], true)) {
                $balance[] = self::leaf($r, $month);
            } elseif ($type === 'expense' || $type === 'revenue') {
                $section = $sectionOf((string) $r['account_code']);
                $profitLoss[$section][] = self::leaf($r, $month);
            }
        }

        $balanceGroup = self::group('balance', $balance);
        $sections = [];
        foreach ($sectionKeys as $key) {
            $sections[$key] = self::group($key, $profitLoss[$key] ?? []);
        }

        $profit = self::sum([]);
        $subtotals = [];
        foreach (['operating' => 'operating_profit', 'financial' => 'financial_profit', 'unassigned' => null, 'tax' => null, 'transfer' => null] as $key => $label) {
            $profit = self::add($profit, self::diff($sections[$key]['right_total'], $sections[$key]['left_total']));
            if ($label !== null) {
                $subtotals[$label] = self::diff($sections[$key]['right_total'], $sections[$key]['left_total']);
            }
            if ($key === 'unassigned') {
                $subtotals['profit_before_tax'] = $profit;
            } elseif ($key === 'tax') {
                $subtotals['profit_after_tax'] = $profit;
            }
        }
        $balanceResult = self::diff($balanceGroup['left_total'], $balanceGroup['right_total']);

        return [
            'as_of'      => $options['as_of'],
            'month_from' => $options['month_from'],
            'ytd_from'   => $options['from'],
            'hide_zero'  => $options['hide_zero'],
            'analytics'  => $options['analytics'],
            'balance' => [
                'lines' => [
                    ...self::cellLines($balanceGroup, true, $options),
                    ['kind' => 'total', 'key' => 'balance', 'left' => self::money($balanceGroup['left_total']), 'right' => self::money($balanceGroup['right_total'])],
                    ['kind' => 'result', 'key' => 'profit_row', 'amounts' => self::money($balanceResult)],
                    ['kind' => 'total', 'key' => 'balance_with_result', 'left' => self::money($balanceGroup['left_total']),
                        'right' => self::money(self::add($balanceGroup['right_total'], $balanceResult))],
                ],
                'left_total'  => self::money($balanceGroup['left_total']),
                'right_total' => self::money($balanceGroup['right_total']),
                'result'      => self::money($balanceResult),
            ],
            'profit_loss' => [
                'lines' => [
                    ...self::sectionLines($sections, $options),
                    ...array_map(static fn (string $key, array $amounts): array => ['kind' => 'result', 'key' => $key, 'amounts' => self::money($amounts)], array_keys($subtotals), $subtotals),
                    ['kind' => 'result', 'key' => 'profit_row', 'amounts' => self::money($profit)],
                ],
                'left_total'  => self::money(array_reduce($sections, static fn (array $sum, array $s): array => self::add($sum, $s['left_total']), self::sum([]))),
                'right_total' => self::money(array_reduce($sections, static fn (array $sum, array $s): array => self::add($sum, $s['right_total']), self::sum([]))),
                'result'      => self::money($profit),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @param array<int,int> $month MD − D měsíce v haléřích podle účtu
     * @return array<string,mixed>
     */
    private static function leaf(array $r, array $month): array
    {
        $type = (string) $r['account_type'];
        $ps = self::cents($r['ps_md']) - self::cents($r['ps_d']);
        $ytd = self::cents($r['to_md']) - self::cents($r['to_d']);
        return [
            'account_id'     => (int) $r['id'],
            'account_code'   => (string) $r['account_code'],
            'name'           => (string) $r['name'],
            'account_type'   => $type,
            'normal_side'    => $r['normal_side'] !== null ? (string) $r['normal_side'] : null,
            'synthetic_id'   => $r['parent_id'] ?? (int) $r['id'],
            'synthetic_code' => (string) ($r['parent_code'] ?? $r['account_code']),
            'synthetic_name' => (string) ($r['parent_name'] ?? $r['name']),
            'synthetic_normal_side' => ($r['parent_id'] ?? null) !== null
                ? ($r['parent_normal_side'] !== null ? (string) $r['parent_normal_side'] : null)
                : ($r['normal_side'] !== null ? (string) $r['normal_side'] : null),
            'raw' => ['ps' => $ps, 'month' => $month[(int) $r['id']] ?? 0, 'ytd' => $ytd, 'ks' => $ps + $ytd],
        ];
    }

    /**
     * Listové účty skupiny rozdělené na levou a pravou stranu a složené pod syntetiky.
     *
     * @param list<array<string,mixed>> $leaves
     * @return array{key:string, left:list<array<string,mixed>>, right:list<array<string,mixed>>, left_total:array<string,int>, right_total:array<string,int>}
     */
    private static function group(string $key, array $leaves): array
    {
        $sides = ['left' => [], 'right' => []];
        foreach ($leaves as $leaf) {
            $side = AccountBalanceSign::factor($leaf['account_type']) > 0 ? 'left' : 'right';
            $sides[$side][$leaf['synthetic_code']][] = $leaf;
        }
        $out = ['key' => $key];
        foreach ($sides as $side => $synthetics) {
            ksort($synthetics, SORT_STRING);
            $cells = [];
            $total = self::sum([]);
            foreach ($synthetics as $code => $group) {
                $factor = AccountBalanceSign::factor($group[0]['account_type']);
                $raw = self::sum(array_column($group, 'raw'));
                $signed = self::scale($raw, $factor);
                $total = self::add($total, $signed);
                $analytics = [];
                foreach ($group as $leaf) {
                    if ((int) $leaf['account_id'] === (int) $leaf['synthetic_id'] && count($group) === 1) {
                        continue;
                    }
                    $analytics[] = self::cell('analytic', $leaf['account_id'], $leaf['account_code'], $leaf['name'], $leaf['account_type'], $leaf['normal_side'], $leaf['raw']);
                }
                $cell = self::cell('synthetic', (int) $group[0]['synthetic_id'], (string) $code, $group[0]['synthetic_name'], $group[0]['account_type'], $group[0]['synthetic_normal_side'], $raw);
                $cells[] = ['analytics' => $analytics] + $cell;
            }
            $out[$side] = $cells;
            $out[$side . '_total'] = $total;
        }
        return $out;
    }

    /**
     * @param array<string,int> $raw hodnoty MD − D v haléřích
     * @return array<string,mixed>
     */
    private static function cell(string $kind, int $id, string $code, string $name, string $type, ?string $normalSide, array $raw): array
    {
        $factor = AccountBalanceSign::factor($type);
        return [
            'kind'         => $kind,
            'account_id'   => $id,
            'account_code' => $code,
            'name'         => $name,
            'account_type' => $type,
            'normal_side'  => $normalSide,
            'ps'           => self::money1($raw['ps'] * $factor),
            'month'        => self::money1($raw['month'] * $factor),
            'ytd'          => self::money1($raw['ytd'] * $factor),
            'ks'           => self::money1($raw['ks'] * $factor),
            'ps_unusual'   => AccountBalanceSign::isUnusual($raw['ps'] / 100, $normalSide),
            'ks_unusual'   => AccountBalanceSign::isUnusual($raw['ks'] / 100, $normalSide),
            'is_zero'      => $raw['ps'] === 0 && $raw['month'] === 0 && $raw['ytd'] === 0 && $raw['ks'] === 0,
        ];
    }

    /**
     * @param array<string,mixed> $group výstup {@see self::group()}
     * @param array{hide_zero:bool, analytics:bool} $options
     * @return list<array<string,mixed>>
     */
    private static function cellLines(array $group, bool $withClasses, array $options): array
    {
        $left = self::flatten($group['left'], $withClasses, $options);
        $right = self::flatten($group['right'], $withClasses, $options);
        $lines = [];
        for ($i = 0, $n = max(count($left), count($right)); $i < $n; $i++) {
            $lines[] = ['kind' => 'cells', 'left' => $left[$i] ?? null, 'right' => $right[$i] ?? null];
        }
        return $lines;
    }

    /**
     * @param array<string,array<string,mixed>> $sections
     * @param array{hide_zero:bool, analytics:bool} $options
     * @return list<array<string,mixed>>
     */
    private static function sectionLines(array $sections, array $options): array
    {
        $lines = [];
        foreach ($sections as $key => $section) {
            $cells = self::cellLines($section, false, $options);
            if ($cells === []) {
                continue;
            }
            $lines = [
                ...$lines,
                ['kind' => 'section', 'key' => $key],
                ...$cells,
                ['kind' => 'total', 'key' => 'section', 'section' => $key,
                    'left' => self::money($section['left_total']), 'right' => self::money($section['right_total'])],
            ];
        }
        return $lines;
    }

    /**
     * Jedna strana řádek po řádku: třída (rozvaha), syntetika, její analytiky.
     *
     * @param list<array<string,mixed>> $cells
     * @param array{hide_zero:bool, analytics:bool} $options
     * @return list<array<string,mixed>>
     */
    private static function flatten(array $cells, bool $withClasses, array $options): array
    {
        $visible = [];
        foreach ($cells as $cell) {
            $analytics = array_values(array_filter($cell['analytics'], static fn (array $a): bool => !$options['hide_zero'] || !$a['is_zero']));
            if ($options['hide_zero'] && $cell['is_zero'] && $analytics === []) {
                continue;
            }
            $visible[] = ['analytics' => $analytics] + $cell;
        }

        $out = [];
        $currentClass = null;
        foreach ($visible as $cell) {
            $class = substr($cell['account_code'], 0, 1);
            if ($withClasses && $class !== $currentClass) {
                $currentClass = $class;
                $sum = ['ps' => 0, 'month' => 0, 'ytd' => 0, 'ks' => 0];
                foreach ($visible as $other) {
                    if (substr($other['account_code'], 0, 1) === $class) {
                        foreach (self::AMOUNTS as $k) {
                            $sum[$k] += self::cents($other[$k]);
                        }
                    }
                }
                $out[] = ['kind' => 'class', 'class' => $class] + self::money($sum);
            }
            $analytics = $cell['analytics'];
            unset($cell['analytics']);
            $out[] = $cell;
            if ($options['analytics']) {
                foreach ($analytics as $analytic) {
                    $out[] = $analytic;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,int>> $rows
     * @return array<string,int>
     */
    private static function sum(array $rows): array
    {
        $out = array_fill_keys(self::AMOUNTS, 0);
        foreach ($rows as $row) {
            $out = self::add($out, $row);
        }
        return $out;
    }

    /** @param array<string,int> $a @param array<string,int> $b @return array<string,int> */
    private static function add(array $a, array $b): array
    {
        foreach (self::AMOUNTS as $k) {
            $a[$k] += $b[$k];
        }
        return $a;
    }

    /** @param array<string,int> $a @param array<string,int> $b @return array<string,int> */
    private static function diff(array $a, array $b): array
    {
        return self::add($a, self::scale($b, -1));
    }

    /** @param array<string,int> $a @return array<string,int> */
    private static function scale(array $a, int $factor): array
    {
        foreach (self::AMOUNTS as $k) {
            $a[$k] *= $factor;
        }
        return $a;
    }

    /** @param array<string,int> $a @return array<string,float> */
    private static function money(array $a): array
    {
        $out = [];
        foreach (self::AMOUNTS as $k) {
            $out[$k] = self::money1($a[$k]);
        }
        return $out;
    }

    private static function money1(int $cents): float
    {
        return $cents === 0 ? 0.0 : $cents / 100;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
