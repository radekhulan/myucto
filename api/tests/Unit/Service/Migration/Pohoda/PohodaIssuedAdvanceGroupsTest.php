<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaIssuedAdvanceGroups;
use PHPUnit\Framework\TestCase;

final class PohodaIssuedAdvanceGroupsTest extends TestCase
{
    private const ADVANCE = ['number' => 'ZF1', 'home' => 2420.0, 'foreign' => 96.8, 'currency' => 'EUR'];

    public function testAdvanceDeductionUsesSourceCurrencyOrExactShare(): void
    {
        self::assertSame(96.8, PohodaIssuedAdvanceGroups::deduction(self::deduction(2420.0), self::ADVANCE));
        self::assertSame(40.0, PohodaIssuedAdvanceGroups::deduction(self::deduction(1000.0), self::ADVANCE));
        self::assertSame(39.99, PohodaIssuedAdvanceGroups::deduction(self::deduction(1000.0, 39.99), self::ADVANCE));
        self::assertStringContainsString('na haléř', PohodaIssuedAdvanceGroups::deduction(self::deduction(1000.01), self::ADVANCE));
    }

    public function testDeductionRejectsDifferentAdvanceOrCurrencyAndAmountsAboveTheAdvance(): void
    {
        self::assertIsString(PohodaIssuedAdvanceGroups::deduction(self::deduction(1000.0, 40.0, number: 'ZF2'), self::ADVANCE));
        self::assertIsString(PohodaIssuedAdvanceGroups::deduction(self::deduction(1000.0, 40.0, currency: 'USD'), self::ADVANCE));
        self::assertIsString(PohodaIssuedAdvanceGroups::deduction(self::deduction(2500.0), self::ADVANCE));
        self::assertIsString(PohodaIssuedAdvanceGroups::deduction(self::deduction(2400.0, 97.0), self::ADVANCE));
    }

    private static function deduction(float $home, ?float $foreign = null, string $number = 'ZF1', string $currency = 'EUR'): array
    {
        return ['advance_rows' => [['home' => $home, 'foreign' => $foreign, 'number' => $number, 'currency' => $currency, 'item_index' => null]]];
    }
}
