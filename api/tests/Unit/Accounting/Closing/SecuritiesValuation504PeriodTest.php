<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\SecuritiesValuation504Evidence;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecuritiesValuation504PeriodTest extends TestCase
{
    public static function periods(): array
    {
        return [
            'six months require small' => ['2025-07-01', '12000000.00', 'small', true],
            'six months reject micro' => ['2025-07-01', '12000000.00', 'micro', false],
            'eighteen months remain micro' => ['2024-07-01', '30000000.00', 'micro', true],
            'eighteen months reject small' => ['2024-07-01', '30000000.00', 'small', false],
            'twelve months unchanged' => ['2025-01-01', '24000000.00', 'small', true],
        ];
    }

    #[DataProvider('periods')]
    public function testInventoryUsesActualPeriodForCategory(string $start, string $turnover, string $category, bool $complete): void
    {
        $end = '2025-12-31';
        $snapshot = [
            'findings' => [], 'hash' => 'synthetic-inventory', 'opening_entry_ids' => [], 'as_of' => $end,
            'entries' => [], 'available_accounts' => [], 'rates' => ['CZK' => ['rate' => '1.000000']],
            'profile_snapshot' => [
                'periods' => [['id' => 1, 'starts_on' => $start, 'ends_on' => $end]], 'history' => [],
                'thresholds' => [2025 => ['year' => 2025, 'limits' => TaxConstants::forYear(2026)['entity_category_thresholds']]],
            ],
            'cells' => [[
                'cell_key' => 'synthetic-cell', 'account_id' => 1, 'currency_code' => 'CZK',
                'dimensions' => [], 'cost_center' => null, 'project_id' => null,
                'entry_date' => $start, 'czk_cents' => 2400000, 'foreign_cents' => 0,
            ]],
        ];
        $evidence = [
            'profile' => [
                'scope' => 'simplified', 'category' => $category, 'micro_exception' => 'none',
                'reference' => 'SYN-CATEGORY', 'as_of' => $end, 'scope_reference' => 'SYN-SCOPE',
                'inception_reference' => 'SYN-INCEPTION', 'first_period_start' => $start,
                'category_facts' => [[
                    'period_id' => 1, 'starts_on' => $start, 'ends_on' => $end,
                    'assets_net' => '12000000.00', 'net_turnover' => $turnover, 'average_employees' => '0',
                    'statement_reference' => 'SYN-STATEMENT', 'employees_reference' => 'SYN-EMPLOYEES',
                ]],
            ],
            'valuations' => [[
                'instrument' => [
                    'kind' => 'equity', 'identity' => 'SYN-HOLDING', 'classification' => 'trading',
                    'method' => 'cost_equity', 'reference' => 'SYN-ACQUISITION',
                    'classification_reference' => 'SYN-CLASSIFICATION', 'designated_on' => $start,
                ],
                'as_of' => $end,
                'evidence' => [
                    'reference' => 'SYN-CUSTODY', 'valuation_date' => $end, 'quantity' => '10.000000',
                    'unit_cost_foreign' => '2400.000000', 'acquisition_cost_czk' => '24000.00',
                    'acquisition_date' => $start, 'acquired_quantity' => '10.000000',
                    'recoverability_reference' => 'SYN-RECOVERABILITY', 'recoverability_date' => $end,
                    'recoverable_unit_value_foreign' => '2400.000000',
                ],
                'members' => [['source_cell_hash' => 'synthetic-cell', 'book_czk' => '24000.00', 'book_foreign' => '0.00']],
                'target_czk' => '24000.00', 'posted_proof' => [],
            ]],
        ];
        $result = SecuritiesValuation504Evidence::evaluate($snapshot, $evidence, []);
        self::assertSame($complete, $result['complete'], json_encode($result['findings'], JSON_THROW_ON_ERROR));
        if ($complete) {
            self::assertSame([], $result['findings']);
            self::assertSame('24000.00', $result['valuations'][0]['target_czk']);
            self::assertSame('0.00', $result['valuations'][0]['change_czk']);
        } else {
            self::assertSame(['securities_category_mismatch'], array_column($result['findings'], 'code'));
        }
    }
}
