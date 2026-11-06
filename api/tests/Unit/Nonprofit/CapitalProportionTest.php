<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Nonprofit;
use MyInvoice\Service\Nonprofit\Capital\CapitalPolicy;
use PHPUnit\Framework\TestCase;
final class CapitalProportionTest extends TestCase
{
    public function testGrantExampleAndCumulativeRemaindersUseExactCents():void
    {
        self::assertSame(6000000,CapitalPolicy::proportional(10000000,30000000,50000000));
        self::assertSame(0,CapitalPolicy::proportional(1,1,3));self::assertSame(1,CapitalPolicy::proportional(2,1,3));self::assertSame(1,CapitalPolicy::proportional(3,1,3));
        self::assertSame(599999999999998,CapitalPolicy::proportional(999999999999998,599999999999999,999999999999999));
    }
}
