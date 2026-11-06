<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Charges;

use MyInvoice\Service\Property\Charges\ChargePublicProjection;
use PHPUnit\Framework\TestCase;

final class ChargePublicProjectionTest extends TestCase
{
    public function testFrozenFinancialProjectionPreservesExactSignedAmountsWithoutDecisionOrPartyHistory(): void
    {
        $component = ['id' => 7, 'code' => 'SYNTHETIC', 'name' => 'Synthetic charge', 'kind' => 'contribution',
            'service_id' => null, 'account_code' => '684', 'effective_at' => '2026-01-01T00:00:00.000001Z',
            'created_by' => 42, 'request' => ['title_ref' => 'Private component decision']];
        $line = ['unit_id' => 11, 'basis' => ['num' => '1', 'den' => '3'],
            'exact_cents' => ['num' => '-9007199254740993001', 'den' => '3'],
            'amount_cents' => '-3002399751580331000', 'residual_cent' => true, 'private_note' => 'Private allocation'];
        $row = ['id' => 19, 'house_id' => 3, 'revision_no' => 2, 'effective_from' => '2026-07-01T00:00:00.000001Z',
            'effective_to' => null, 'recorded_at' => '2026-06-01T00:00:00.000002Z', 'preview_hash' => str_repeat('a', 64),
            'created_by' => 42, 'request' => ['basis_at' => '2026-07-01T00:00:00.000001Z', 'frequency' => 'monthly',
                'decision_ref' => 'Private decision', 'reason' => 'Private reason', 'idempotency_key' => 'Private retry'],
            'preview' => ['registry' => ['ownerships' => ['Private person']], 'calculation' => [
                'total_cents' => $line['amount_cents'], 'unit_totals' => [['unit_id' => 11, 'amount_cents' => $line['amount_cents'], 'private_note' => 'Private total']],
                'issuance_facts' => ['Private proof'], 'rates' => [['component' => $component,
                    'rate' => ['method' => 'share', 'mode' => 'rate_per_basis', 'amount' => '-90071992547409930.01',
                        'unit_ids' => [11], 'rounding' => 'aggregate_half_up_largest_remainder_unit_id_v1', 'scope_ref' => 'Private scope'],
                    'calculation' => ['total_cents' => $line['amount_cents'], 'lines' => [$line]]]]]]];
        $before = $row;
        $actual = ChargePublicProjection::version($row);
        self::assertSame($before, $row);
        self::assertSame($line['amount_cents'], $actual['total_cents']);
        self::assertSame($line['exact_cents'], $actual['components'][0]['lines'][0]['exact_cents']);
        self::assertSame($row['request']['basis_at'], $actual['basis_at']);
        self::assertNull($actual['effective_to']);
        self::assertSame('CZK', $actual['currency']);
        self::assertSame([['unit_id' => 11, 'amount_cents' => $line['amount_cents']]], $actual['unit_totals']);
        self::assertStringNotContainsString('Private', json_encode($actual, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('created_by', $actual);
        self::assertSame(array_slice($component, 0, 7), $actual['components'][0]['component']);
    }
}
