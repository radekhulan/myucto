<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Service\Property\Payments\PaymentException;

final class ClearingRevisionCells
{
    public static function plan(array $cells, array $consumed, array $moves): array
    {
        ClearingCells::available($cells, $consumed);
        if (!array_is_list($moves) || $moves === []) PaymentException::reject('clearing_revision_moves');
        $before = array_column($cells, null, 'key');
        $after = $before;
        $normalized = []; $selected = []; $sum = '0';
        foreach ($moves as $move) {
            if (!is_array($move) || array_diff(array_keys($move), ['from_key', 'amount_cents', 'to']) !== []
                || !is_string($move['from_key'] ?? null) || !isset($before[$move['from_key']])
                || !is_string($move['amount_cents'] ?? null) || !is_array($move['to'] ?? null)
                || array_diff(array_keys($move['to']), ['key', 'dimensions']) !== []) PaymentException::reject('clearing_revision_moves');
            $target = ClearingCells::normalize($move['amount_cents'], [$move['to'] + ['amount_cents' => $move['amount_cents']]])[0];
            if (isset($after[$target['key']])) PaymentException::reject('clearing_cell_duplicate', ['cell_key' => $target['key']]);
            $source = $before[$move['from_key']];
            $line = $source['line'];
            if (($line['currency_code'] ?? null) !== null || ($line['amount_foreign'] ?? null) !== null || ($line['fx_rate'] ?? null) !== null
                || !empty($line['is_red_storno']) || $line['account_code'] !== '379.900' || !in_array($line['side'], ['debit', 'credit'], true)) PaymentException::reject('clearing_revision_source_unsupported');
            $origins = [];
            foreach ($target['dimensions'] as $type => $value) $origins[$type] = ($line['dimensions'][$type] ?? null) === $value
                ? ($line['dimension_origins'][$type] ?? ['origin' => 'unknown', 'ref' => null]) : ['origin' => 'explicit', 'ref' => null];
            unset($line['id']);
            $line['amount'] = bcdiv($target['amount_cents'], '100', 2);
            $line['dimensions'] = $target['dimensions']; $line['dimension_splits'] = [];
            $line['dimension_origins'] = $origins; $line['dimension_split_origins'] = [];
            $after[$target['key']] = ['key' => $target['key'], 'amount_cents' => $target['amount_cents'], 'line' => $line];
            $selected[$source['key']] = bcadd($selected[$source['key']] ?? '0', $target['amount_cents'], 0);
            $sum = bcadd($sum, $target['amount_cents'], 0);
            $normalized[$target['key']] = ['from_key' => $source['key'], 'amount_cents' => $target['amount_cents'], 'to' => ['key' => $target['key'], 'dimensions' => $target['dimensions']]];
        }
        $selection = [];
        foreach ($selected as $key => $amount) $selection[] = ['key' => $key, 'amount_cents' => $amount];
        ClearingCells::consume($cells, $consumed, $sum, $selection);
        foreach ($selected as $key => $amount) $after[$key]['amount_cents'] = bcsub($after[$key]['amount_cents'], $amount, 0);
        ksort($normalized, SORT_STRING);
        $lines = [];
        foreach ($normalized as $move) {
            $line = $before[$move['from_key']]['line']; unset($line['id']);
            $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
            $line['amount'] = bcdiv($move['amount_cents'], '100', 2);
            $lines[] = $line; $lines[] = $after[$move['to']['key']]['line'];
        }
        ksort($after, SORT_STRING);
        ClearingCells::available(array_values($after), $consumed);
        return ['moves' => array_values($normalized), 'cells' => array_values($after), 'lines' => $lines];
    }
}
