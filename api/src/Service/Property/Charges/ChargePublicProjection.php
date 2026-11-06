<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges;

final class ChargePublicProjection
{
    public static function component(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'code', 'name', 'kind', 'service_id', 'account_code', 'effective_at']));
    }

    public static function version(array $row): array
    {
        $calculation = $row['preview']['calculation'];
        return array_intersect_key($row, array_flip(['id', 'house_id', 'revision_no', 'effective_from', 'effective_to', 'recorded_at', 'preview_hash']))
            + ['basis_at' => $row['request']['basis_at'], 'frequency' => $row['request']['frequency'], 'currency' => 'CZK',
                'total_cents' => $calculation['total_cents'],
                'unit_totals' => array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['unit_id', 'amount_cents'])), $calculation['unit_totals']),
                'components' => array_map(static fn (array $rate): array => [
                    'component' => self::component($rate['component']),
                    'method' => $rate['rate']['method'], 'mode' => $rate['rate']['mode'], 'rate_amount' => $rate['rate']['amount'],
                    'unit_ids' => $rate['rate']['unit_ids'], 'rounding' => $rate['rate']['rounding'],
                    'total_cents' => $rate['calculation']['total_cents'],
                    'lines' => array_map(static fn (array $line): array => array_intersect_key($line,
                        array_flip(['unit_id', 'basis', 'exact_cents', 'amount_cents', 'residual_cent'])), $rate['calculation']['lines']),
                ], $calculation['rates'])];
    }
}
