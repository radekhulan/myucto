<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Payments;

use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\Workflow\ClearingCells;
use MyInvoice\Service\Property\Payments\Workflow\ClearingRevisionCells;
use PHPUnit\Framework\TestCase;

final class ClearingRevisionCellsTest extends TestCase
{
    public function testOnlyFreeFortyMovesAndReversingOldSixtyRestoresItsOriginalCell(): void
    {
        $plan = ClearingRevisionCells::plan($this->cells(), ['A' => '6000'], [['from_key' => 'A', 'amount_cents' => '4000', 'to' => ['key' => 'B', 'dimensions' => [1 => 102]]]]);
        self::assertSame(['6000', '4000'], array_column($plan['cells'], 'amount_cents'));
        self::assertSame([1 => 101], $plan['cells'][0]['line']['dimensions']);
        self::assertSame([1 => 102], $plan['cells'][1]['line']['dimensions']);
        self::assertSame(['debit', 'credit'], array_column($plan['lines'], 'side'));
        self::assertSame(['40.00', '40.00'], array_column($plan['lines'], 'amount'));
        self::assertSame([['key' => 'A', 'amount_cents' => '6000']], ClearingCells::consume($plan['cells'], [], '6000', [['key' => 'A', 'amount_cents' => '6000']]));
        self::assertSame('legacy@example.test', $plan['lines'][0]['dimension_origins'][1]['ref']);
        self::assertSame(['origin' => 'explicit', 'ref' => null], $plan['lines'][1]['dimension_origins'][1]);
    }

    public function testFullMoveRetainsZeroCapacityIdentityForHistoricalReferences(): void
    {
        $plan = ClearingRevisionCells::plan($this->cells(), [], [['from_key' => 'A', 'amount_cents' => '10000', 'to' => ['key' => 'B', 'dimensions' => [1 => 102]]]]);
        self::assertSame(['0', '10000'], array_column($plan['cells'], 'amount_cents'));
        self::assertSame([['key' => 'B', 'amount_cents' => '10000']], ClearingCells::consume($plan['cells'], [], '10000', [['key' => 'B', 'amount_cents' => '10000']]));
    }

    public function testTwoMovesCannotConsumeTheSameFreeCapacityTwice(): void
    {
        try {
            ClearingRevisionCells::plan($this->cells(), ['A' => '6000'], [
                ['from_key' => 'A', 'amount_cents' => '3000', 'to' => ['key' => 'B', 'dimensions' => [1 => 102]]],
                ['from_key' => 'A', 'amount_cents' => '3000', 'to' => ['key' => 'C', 'dimensions' => [1 => 103]]],
            ]);
            self::fail('Spent capacity was silently reclassified.');
        } catch (PaymentException $e) { self::assertSame('property_payment_clearing_capacity', $e->errorCode); }
    }

    public function testNewCellCannotReuseAnExistingHistoricalIdentity(): void
    {
        $this->expectException(PaymentException::class);
        ClearingRevisionCells::plan($this->cells(), [], [['from_key' => 'A', 'amount_cents' => '4000', 'to' => ['key' => 'A', 'dimensions' => [1 => 102]]]]);
    }

    private function cells(): array
    {
        return [['key' => 'A', 'amount_cents' => '10000', 'line' => ['id' => 44, 'account_code' => '379.900', 'side' => 'credit', 'amount' => '100.00',
            'currency_code' => null, 'amount_foreign' => null, 'fx_rate' => null, 'is_red_storno' => false,
            'dimensions' => [1 => 101], 'dimension_splits' => [], 'dimension_origins' => [1 => ['origin' => 'explicit', 'ref' => 'legacy@example.test']], 'dimension_split_origins' => []]]];
    }
}
