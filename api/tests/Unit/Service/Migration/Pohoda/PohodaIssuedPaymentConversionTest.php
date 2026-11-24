<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaIssuedPaymentConversion;
use PHPUnit\Framework\TestCase;

final class PohodaIssuedPaymentConversionTest extends TestCase
{
    public function testSharedMovementClosesToTheCent(): void
    {
        self::assertSame([7 => 0.02, 9 => 0.01, 3 => 0.02],
            PohodaIssuedPaymentConversion::distribute(0.05, [7 => 1.00, 9 => 1.00, 3 => 1.00]));
        self::assertSame([12 => 169.40, 20 => 398.09],
            PohodaIssuedPaymentConversion::distribute(567.49, [12 => 4255.33, 20 => 10000.00]));
    }

    public function testAmountFromCrownShareUsesExactForeignTotalForFullPayment(): void
    {
        self::assertSame(398.09, PohodaIssuedPaymentConversion::proportional(10000.0, 10000.0, 398.09));
        self::assertSame(42.35, PohodaIssuedPaymentConversion::proportional(1063.83, 4255.33, 169.40));
        self::assertSame(-398.09, PohodaIssuedPaymentConversion::proportional(-10000.0, -10000.0, -398.09));
        self::assertSame(0.0, PohodaIssuedPaymentConversion::proportional(0.0, 0.0, 0.0));
    }
}
