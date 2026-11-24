<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Vat;

use MyInvoice\Service\Accounting\Vat\VatPostingAccounts;
use MyInvoice\Service\Vat\VatRateResolver;
use PHPUnit\Framework\TestCase;

final class VatPostingAccountsTest extends TestCase
{
    public function testRateKeysAndFallback(): void
    {
        self::assertSame(['output', '12.5'], VatPostingAccounts::parseRateRule('invoice.vat.output.12.5'));
        self::assertNull(VatPostingAccounts::parseRateRule('invoice.vat.output.12.50'));
        self::assertSame('21', VatRateResolver::rateKey(21.00));
        $rates = [21 => 210.0, 12 => 120.0];
        self::assertSame(['343.200' => 330.0], VatPostingAccounts::split($rates, [], '343.200', 330.0));
        self::assertSame(['343.200' => 210.0, '343.320' => 120.0],
            VatPostingAccounts::split($rates, [12 => '343.320'], '343.200', 330.0));
    }

    public function testSignedAmountsAndRoundingStayOnTheDocument(): void
    {
        $accounts = [21 => '343.310', 12 => '343.320'];
        self::assertSame(['343.310' => -30.0, '343.320' => 30.0],
            VatPostingAccounts::split([21 => -30.0, 12 => 30.0], $accounts, '343.200', 0.0));
        self::assertSame(['343.310' => 0.01, '343.320' => 0.02],
            VatPostingAccounts::split([21 => 0.03, 12 => 0.03], $accounts, '343.100', 0.03, 0.5));
        self::assertSame(['343.310' => 330.0],
            VatPostingAccounts::split([21 => 210.0, 12 => 120.0], [21 => '343.310', 12 => '343.310'], '343.200', 330.0));
    }

    public function testValidationUsesChartHierarchyAndSettlementAccount(): void
    {
        $chart = [
            ['id' => 1, 'account_code' => '343', 'parent_id' => null, 'is_synthetic' => true, 'is_active' => true],
            ['id' => 2, 'account_code' => 'DPH-A', 'parent_id' => 1, 'is_synthetic' => false, 'is_active' => true],
            ['id' => 3, 'account_code' => '333', 'parent_id' => null, 'is_synthetic' => true, 'is_active' => true],
            ['id' => 4, 'account_code' => '343.900', 'parent_id' => 1, 'is_synthetic' => false, 'is_active' => true],
        ];
        self::assertNull(VatPostingAccounts::validationError($chart, [], 'output', null, 'DPH-A'));
        self::assertNull(VatPostingAccounts::validationError($chart, [], 'output', null, null));
        self::assertNotNull(VatPostingAccounts::validationError($chart, [], 'output', null, '333'));
        self::assertNotNull(VatPostingAccounts::validationError($chart, [], 'output', null, '343.900'));
        self::assertNotNull(VatPostingAccounts::validationError($chart, [], 'output', 'DPH-A', null));
    }
}
