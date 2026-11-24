<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use PHPUnit\Framework\TestCase;

final class IssuedCzkAmountsTest extends TestCase
{
    public function testPohledavkaZPrevoduCerpanaPoCastechZachovaPresnouCenuPredpisu(): void
    {
        $invoice = ['amount_to_pay' => '0.03', 'import_amount_to_pay_czk' => '0.73', 'exchange_rate' => '24.025'];
        self::assertSame(0.73, IssuedCzkAmounts::due($invoice));
        self::assertSame(0.73, IssuedCzkAmounts::allocated($invoice, 0.03, 24.025));
        self::assertSame(0.24, IssuedCzkAmounts::allocated($invoice, 0.01, 24.025));
        self::assertSame(0.25, IssuedCzkAmounts::allocated($invoice, 0.01, 24.025, 0.01));
        self::assertSame(0.24, IssuedCzkAmounts::allocated($invoice, 0.01, 24.025, 0.02));
        self::assertSame(0.73, round(0.03 * IssuedCzkAmounts::settlementRate($invoice), 2));
    }

    public function testNativniFakturaPouzijeKurzPredpisu(): void
    {
        $invoice = ['amount_to_pay' => '100.00', 'exchange_rate' => '24.025'];
        self::assertSame(2402.50, IssuedCzkAmounts::due($invoice));
        self::assertSame(500.0, IssuedCzkAmounts::allocated($invoice, 20.0, 25.0, 80.0));
        self::assertSame(24.025, IssuedCzkAmounts::settlementRate($invoice));
    }

    public function testSnimekSazbyRozlisujeNulovouDanAOdpoctovouPolozku(): void
    {
        $line = ['total_without_vat' => '-10.01', 'import_tax_base_czk' => '-240.30', 'total_vat' => '-0.01', 'import_tax_vat_czk' => '0.00'];
        self::assertSame(-240.30, IssuedCzkAmounts::itemBase($line, 24.025));
        self::assertSame(0.0, IssuedCzkAmounts::itemVat($line, 24.025));
    }
}
