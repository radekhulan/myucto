<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Sets\ProductSetDefinition;
use MyInvoice\Service\Eshop\Sets\ProductSetGraph;
use MyInvoice\Service\Eshop\Sets\ProductSetQuoteCalculator;
use PHPUnit\Framework\TestCase;

final class ProductSetQuoteCalculatorTest extends TestCase
{
    public function testDiscountAndComponentAllocationUseExactMoney(): void
    {
        $definitions = [10 => ProductSetDefinition::normalize([
            'components' => [['item_id' => 1, 'quantity' => '1'], ['item_id' => 2, 'quantity' => '2']],
            'prices' => ['CZK' => ['mode' => 'discount', 'discount_pct' => '10']],
        ])];
        $tree = (new ProductSetGraph())->expand(10, '1', $definitions)['tree'];
        $quote = (new ProductSetQuoteCalculator())->calculate($tree, $definitions, [1 => '1000', 2 => '200'], 'CZK');
        self::assertSame('1260.00', $quote['amount']);
        self::assertSame(['900.00', '360.00'], array_column($quote['components'], 'amount'));
        $changed = (new ProductSetQuoteCalculator())->calculate($tree, $definitions, [1 => '1000', 2 => '250'], 'CZK');
        self::assertSame('1350.00', $changed['amount']);
    }

    public function testNestedFixedPriceIsPreservedAndOuterDiscountAllocated(): void
    {
        $definitions = [
            10 => ProductSetDefinition::normalize(['components' => [['item_id' => 20, 'quantity' => '2']], 'prices' => ['CZK' => ['mode' => 'discount', 'discount_pct' => '10']]]),
            20 => ProductSetDefinition::normalize(['components' => [['item_id' => 1, 'quantity' => '1'], ['item_id' => 2, 'quantity' => '1']], 'prices' => ['CZK' => ['mode' => 'fixed', 'fixed_price' => '99.99']]]),
        ];
        $tree = (new ProductSetGraph())->expand(10, '1', $definitions)['tree'];
        $quote = (new ProductSetQuoteCalculator())->calculate($tree, $definitions, [1 => '10', 2 => '10'], 'CZK');
        self::assertSame('179.98', $quote['amount']);
        self::assertSame(['89.99', '89.99'], array_column($quote['components'], 'amount'));
    }

    public function testRoundingRemainderHasDeterministicRecipient(): void
    {
        self::assertSame(['0.34', '0.33', '0.33'], ProductSetQuoteCalculator::allocate('1.00', ['1', '1', '1']));
        self::assertSame(['0.00', '1.00'], ProductSetQuoteCalculator::allocate('1.00', ['0', '1']));
    }
}
