<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class JointAllocationPlanBuilder
{
    public static function build(array $sources, array $cells, array $typeIds, array $exactCells = [], string $mode = 'weighted'): array
    {
        $cells = JointAllocationCalculator::cells($cells);
        foreach (JointAllocationCalculator::AXES as $axis) {
            if (!isset($typeIds[$axis]) || !is_int($typeIds[$axis]) || $typeIds[$axis] <= 0) {
                throw new JointAllocationException('allocation_invalid_types', 'Chybí jednoznačná systémová osa.', details: ['axis' => $axis]);
            }
        }
        if (count(array_unique(array_values($typeIds))) !== 3 || count($typeIds) !== 3 || $sources === []) {
            throw new JointAllocationException('allocation_invalid_sources', 'Vyberte zdrojové řádky a tři různé systémové osy.');
        }
        $seen = [];
        $result = [];
        $exactCells = JointAllocationExactCells::normalize($exactCells);
        JointAllocationExactCells::assertSources($sources, $exactCells, $mode);
        foreach ($sources as $source) {
            $id = $source['id'] ?? null;
            if (!is_int($id) || $id <= 0 || isset($seen[$id]) || !in_array($source['side'] ?? null, ['debit', 'credit'], true)) {
                throw new JointAllocationException('allocation_invalid_sources', 'Zdrojové řádky musí být jednoznačné řádky deníku.');
            }
            $seen[$id] = true;
            $amount = JointAllocationCalculator::amount($source['amount']);
            if (bccomp($amount, '0', 2) < 0) {
                throw new JointAllocationException('allocation_invalid_amount', 'Znaménko zdrojového řádku musí být vyjádřeno červeným stornem.');
            }
            $local = JointAllocationCalculator::allocate($amount, $cells);
            $foreign = ($source['amount_foreign'] ?? null) === null ? null : JointAllocationCalculator::allocate($source['amount_foreign'], $cells);
            if ($mode === 'explicit_amounts') {
                [$local, $foreign] = JointAllocationExactCells::allocate($source, $cells, $exactCells[$id]);
            }
            if ($amount === '0.00' && ($foreign === null || $foreign['amount'] === '0.00')) {
                throw new JointAllocationException('allocation_empty_source', 'Zdrojový řádek nemá částku v účetní ani cizí měně.');
            }
            $base = array_intersect_key($source, array_flip([
                'account_id', 'side', 'amount', 'is_red_storno', 'currency_code', 'fx_rate', 'amount_foreign',
                'cost_center', 'project_id', 'dimensions', 'dimension_splits', 'dimension_origins', 'dimension_split_origins',
            ]));
            $base['amount'] = $amount;
            $base['amount_foreign'] = $foreign['amount'] ?? null;
            $compensation = JournalLineAmount::reversal($base);
            $targets = [];
            foreach ($local['cells'] as $index => $cell) {
                $line = $base;
                $line['amount'] = $cell['amount'];
                $line['amount_foreign'] = $foreign['cells'][$index]['amount'] ?? null;
                $dimensions = $cell['additional_dimensions'] ?? [];
                foreach ($typeIds as $axis => $typeId) {
                    if (isset($dimensions[$typeId])) {
                        throw new JointAllocationException('allocation_invalid_types', 'Systémová osa nesmí být uvedena podruhé mezi dalšími osami.');
                    }
                    $dimensions[$typeId] = $cell[$axis];
                }
                foreach ($dimensions as $typeId => $valueId) {
                    $line['dimensions'][$typeId] = $valueId;
                    $line['dimension_origins'][$typeId] = ['origin' => 'module', 'ref' => null];
                    unset($line['dimension_splits'][$typeId], $line['dimension_split_origins'][$typeId]);
                }
                $targets[] = $line;
            }
            self::assertNonTargetProjections($base, $targets, $typeIds, $id, $cells[0]['additional_dimensions'] ?? []);
            $result[] = [
                'source_line_id' => $id,
                'additional_type_ids' => array_keys($cells[0]['additional_dimensions'] ?? []),
                'compensation' => $compensation,
                'targets' => $targets,
                'local_allocation' => $local,
                'foreign_allocation' => $foreign,
            ];
        }
        usort($result, static fn (array $a, array $b): int => $a['source_line_id'] <=> $b['source_line_id']);
        return ['allocation_mode' => $mode, 'rounding_policy' => $mode === 'explicit_amounts' ? 'explicit_amounts' : JointAllocationCalculator::ROUNDING_POLICY, 'sources' => $result];
    }

    private static function assertNonTargetProjections(array $source, array $targets, array $typeIds, int $sourceId, array $declared): void
    {
        $fractional = [];
        foreach ($source['dimension_splits'] ?? [] as $typeId => $shares) {
            if (!in_array((int) $typeId, $typeIds, true) && count($shares) > 1) {
                $fractional[] = (int) $typeId;
            }
        }
        $missing = array_values(array_diff($fractional, array_keys($declared)));
        if ($missing !== []) {
            sort($fractional, SORT_NUMERIC);
            throw new JointAllocationException('allocation_non_target_joint_required', 'Rozdělení dalších os vyžaduje doložené společné kombinace.', details: [
                'source_line_id' => $sourceId, 'type_ids' => $missing,
                'resolution' => 'explicit_joint_cells_or_source_correction',
            ]);
        }
        $retained = $source['dimension_splits'] ?? [];
        foreach ($source['dimensions'] ?? [] as $typeId => $valueId) {
            if (!isset($retained[$typeId])) $retained[$typeId] = [$valueId => '100'];
        }
        foreach ($declared as $typeId => $_) {
            if (!isset($retained[$typeId])) {
                throw new JointAllocationException('allocation_additional_axis_not_in_source', 'Další osa klíče musí být doložena ve zdrojovém řádku.', details: ['source_line_id' => $sourceId, 'type_id' => $typeId]);
            }
        }
        foreach ($retained as $typeId => $shares) {
            if (in_array((int) $typeId, $typeIds, true)) {
                continue;
            }
            ksort($shares, SORT_NUMERIC);
            $weights = array_map(static fn (mixed $share): string => is_float($share) ? number_format($share, 6, '.', '') : (string) $share, array_values($shares));
            foreach (['amount', 'amount_foreign'] as $field) {
                if (($source[$field] ?? null) === null) {
                    continue;
                }
                $signed = static fn (array $line): string => empty($line['is_red_storno']) ? $line[$field] : bcsub('0', $line[$field], 2);
                $original = array_column(JointAllocationCalculator::apportion($signed($source), $weights)['parts'], 'amount');
                $preview = array_fill_keys(array_keys($shares), '0.00');
                foreach ($targets as $line) {
                    $valueId = $line['dimensions'][$typeId] ?? null;
                    if (!isset($declared[$typeId])) continue;
                    if (!array_key_exists($valueId, $preview)) {
                        throw new JointAllocationException('allocation_additional_value_not_in_source', 'Buňka obsahuje hodnotu mimo zdrojové rozdělení.', details: ['source_line_id' => $sourceId, 'type_id' => $typeId, 'value_id' => $valueId]);
                    }
                    $preview[$valueId] = bcadd($preview[$valueId], $signed($line), 2);
                }
                if (isset($declared[$typeId]) && array_values($preview) !== $original) {
                    throw new JointAllocationException(
                        'allocation_non_target_rounding_drift',
                        'Náhled mění částky jiné dimenze. Doložte úplné společné kombinace nebo opravte zdroj přípustným účetním postupem.',
                        details: [
                            'source_line_id' => $sourceId,
                            'type_id' => (int) $typeId,
                            'amount_field' => $field,
                            'original' => array_combine(array_keys($shares), $original),
                            'preview' => $preview,
                            'resolution' => 'explicit_joint_cells_or_source_correction',
                        ],
                    );
                }
            }
        }
    }
}
