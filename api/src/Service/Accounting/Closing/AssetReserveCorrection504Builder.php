<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

final class AssetReserveCorrection504Builder
{
    public static function lines(array $transfers, array $source): array
    {
        $lines = [];
        $seen = [];
        foreach ($transfers as $transfer) {
            if (!is_int($transfer['source_entry_id'] ?? null) || $transfer['source_entry_id'] <= 0 || isset($seen[$transfer['source_entry_id']])) throw AssetReserveSourceDecision::changed();
            $seen[$transfer['source_entry_id']] = true;
            $total = 0;
            foreach ($transfer['before'] as $cell) $total += ProfitDistribution504Builder::allocationCents($cell['amount']);
            $after = $total === 0 ? [] : AssetReserveSourceDecision::allocations($source, $total);
            if ($after !== $transfer['after']) throw AssetReserveSourceDecision::changed();
            foreach (['before' => 'debit', 'after' => 'credit'] as $key => $side) {
                foreach ($transfer[$key] as $cell) {
                    if (ProfitDistribution504Builder::allocationCents($cell['amount']) <= 0) throw AssetReserveSourceDecision::changed();
                    $lines[] = ['account_code' => '941', 'side' => $side, 'amount' => $cell['amount'],
                        'dimensions' => $cell['dimensions'], 'cost_center' => $cell['cost_center'] ?? null,
                        'project_id' => $cell['project_id'] ?? null];
                }
            }
        }
        return $lines;
    }
}
