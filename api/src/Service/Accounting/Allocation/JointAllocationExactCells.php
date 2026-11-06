<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

final class JointAllocationExactCells
{
    public static function normalize(mixed $input): array
    {
        if (!is_array($input) || count($input) > 200) {
            throw new JointAllocationException('allocation_invalid_cells', 'Zadejte přesné buňky po zdrojových řádcích.');
        }
        $out = [];
        foreach ($input as $source) {
            if (!is_array($source)) throw new JointAllocationException('allocation_invalid_cells', 'Zdroj přesných buněk musí být objekt.');
            $id = $source['source_line_id'] ?? null;
            $evidence = is_string($source['evidence'] ?? null) ? trim($source['evidence']) : '';
            if (!is_int($id) || $id <= 0 || isset($out[$id]) || $evidence === '' || mb_strlen($evidence) > 4000
                || !is_array($source['cells'] ?? null) || count($source['cells']) > JointAllocationCalculator::MAX_CELLS) {
                throw new JointAllocationException('allocation_exact_evidence_required', 'Přesné částky vyžadují jednoznačný zdroj, buňky a věcné doložení rozdělení v obou měnách.');
            }
            $cells = [];
            foreach ($source['cells'] as $cell) {
                if (!is_array($cell)) throw new JointAllocationException('allocation_invalid_cells', 'Přesná buňka musí být objekt.');
                $cellId = $cell['cell_id'] ?? null;
                if (!is_string($cellId) || !preg_match('/^[a-f0-9]{64}$/D', $cellId) || isset($cells[$cellId])
                    || !is_string($cell['amount'] ?? null) || (!is_string($cell['amount_foreign'] ?? null) && ($cell['amount_foreign'] ?? null) !== null)) {
                    throw new JointAllocationException('allocation_invalid_cells', 'Přesná buňka vyžaduje jedinečný identifikátor a desetinné částky.');
                }
                $cells[$cellId] = ['cell_id' => $cellId, 'amount' => JointAllocationCalculator::amount($cell['amount']),
                    'amount_foreign' => ($cell['amount_foreign'] ?? null) === null ? null : JointAllocationCalculator::amount($cell['amount_foreign'])];
            }
            ksort($cells, SORT_STRING);
            $out[$id] = ['source_line_id' => $id, 'evidence' => $evidence, 'cells' => array_values($cells)];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    public static function assertSources(array $sources, array $exact, string $mode): void
    {
        $ids = array_column($sources, 'id');
        sort($ids, SORT_NUMERIC);
        if (!in_array($mode, ['weighted', 'explicit_amounts'], true)
            || ($mode === 'weighted' && $exact !== [])
            || ($mode === 'explicit_amounts' && $ids !== array_keys($exact))) {
            throw new JointAllocationException('allocation_exact_sources_required', 'Režim verze klíče musí odpovídat úplnému seznamu přesných zdrojových buněk.');
        }
    }

    public static function allocate(array $source, array $cells, array $exact): array
    {
        $given = array_column($exact['cells'], null, 'cell_id');
        $expected = array_map(JointAllocationCalculator::cellId(...), $cells);
        if (count($given) !== count($expected) || array_diff($expected, array_keys($given)) !== []) {
            throw new JointAllocationException('allocation_exact_cells_required', 'Přesné částky musí obsahovat právě všechny buňky verze klíče.');
        }
        $allocations = [];
        foreach (['amount', 'amount_foreign'] as $field) {
            $total = $source[$field] ?? null;
            $parts = [];
            $sum = '0.00';
            foreach ($cells as $cell) {
                $amount = $given[JointAllocationCalculator::cellId($cell)][$field];
                if (($total === null) !== ($amount === null)
                    || ($total !== null && bccomp($amount, '0', 2) !== 0 && (bccomp($amount, '0', 2) < 0) !== (bccomp($total, '0', 2) < 0))) {
                    throw new JointAllocationException('allocation_invalid_amount', 'Buňky musí zachovat znaménko a přítomnost měny zdroje.');
                }
                if ($amount !== null) {
                    $sum = bcadd($sum, $amount, 2);
                    $parts[] = $cell + ['amount' => $amount, 'rounded_amount' => $amount, 'adjustment' => '0.00'];
                }
            }
            if ($total !== null && bccomp($sum, $total, 2) !== 0) {
                throw new JointAllocationException('allocation_exact_total_mismatch', 'Součet přesných buněk neodpovídá zdroji.', details: ['source_line_id' => $source['id'], 'amount_field' => $field, 'original' => $total, 'preview' => $sum]);
            }
            $allocations[] = $total === null ? null : ['amount' => JointAllocationCalculator::amount($total), 'rounding_policy' => 'explicit_amounts', 'residual' => '0.00', 'cells' => $parts];
        }
        return $allocations;
    }
}
