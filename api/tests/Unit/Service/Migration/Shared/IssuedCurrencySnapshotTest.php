<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\IssuedCurrencySnapshot;
use PHPUnit\Framework\TestCase;

final class IssuedCurrencySnapshotTest extends TestCase
{
    public function testSourceAmountsAreKeptWithForeignAmounts(): void
    {
        $amounts = self::amounts([['base' => 24000.04, 'vat' => 5040.01, 'foreign_base' => 998.96, 'foreign_vat' => 209.78]], 29040.05);
        $result = IssuedCurrencySnapshot::prepare($amounts, 24.025, 1208.74);

        self::assertIsArray($result);
        self::assertSame(209.78, $result['vat']);
        self::assertSame(1208.74, $result['total']);
        self::assertSame(24000.04, $result['items'][0]['home_base']);
        self::assertSame(5040.01, $result['items'][0]['home_vat']);
        self::assertSame(29040.05, $result['snapshot']['import_amount_to_pay_czk']);
    }

    public function testCreditNoteKeepsBothSignsAndSourceTotals(): void
    {
        $amounts = self::amounts([['base' => -251.20, 'vat' => -52.76, 'foreign_base' => -10.0, 'foreign_vat' => -2.10]], -303.96);
        $result = IssuedCurrencySnapshot::prepare($amounts, 25.12, -12.10);

        self::assertIsArray($result);
        self::assertSame(-12.10, $result['total']);
        self::assertSame(-52.76, $result['snapshot']['import_total_vat_czk']);
    }

    public function testLargeOrOffsettingDifferencesStayInCrowns(): void
    {
        $amounts = self::amounts([['base' => 251.20, 'vat' => 54.0, 'foreign_base' => 10.0, 'foreign_vat' => 2.10]], 305.20);
        self::assertStringContainsString('daň 1. položky', IssuedCurrencySnapshot::prepare($amounts, 25.12, 12.10));

        $items = array_fill(0, 8, ['base' => 25.40, 'vat' => 4.60, 'foreign_base' => 1.0, 'foreign_vat' => 0.20]);
        self::assertStringContainsString('součet odchylek', IssuedCurrencySnapshot::prepare(self::amounts($items, 240.0), 25.0, 9.60));
    }

    public function testAdvanceInCrownsOnlyStaysInCrowns(): void
    {
        $amounts = ['advance' => 100.0] + self::amounts([['base' => 251.20, 'vat' => 0.0, 'foreign_base' => 10.0, 'foreign_vat' => 0.0]], 251.20);
        self::assertStringStartsWith('odpočet nedaňové zálohy', IssuedCurrencySnapshot::prepare($amounts, 25.12, 10.0));
    }

    public function testExplicitAdvanceUsesSourceAmountInBothCurrencies(): void
    {
        $amounts = ['advance' => 126.0, 'foreign_advance' => 5.0]
            + self::amounts([['base' => 251.20, 'vat' => 0.0, 'foreign_base' => 10.0, 'foreign_vat' => 0.0]], 251.20);
        $result = IssuedCurrencySnapshot::prepare($amounts, 25.12, 5.0);

        self::assertIsArray($result);
        self::assertSame(10.0, $result['total']);
        self::assertSame(5.0, $result['advance']);
        self::assertSame(126.0, $result['snapshot']['import_advance_paid_czk']);
        self::assertSame(125.20, $result['snapshot']['import_amount_to_pay_czk']);
    }

    public function testIncompatibleSourceForeignTotalStaysInCrowns(): void
    {
        $amounts = self::amounts([['base' => 251.20, 'vat' => 0.0, 'foreign_base' => 10.0, 'foreign_vat' => 0.0]], 251.20);
        self::assertStringContainsString('celkem v měně', IssuedCurrencySnapshot::prepare($amounts, 25.12, 11.0));
    }

    public function testProvenLinkedAdvanceKeepsTheOriginalCrownSettlementAtDifferentRates(): void
    {
        $amounts = ['advance' => 2420.0] + self::amounts([['base' => 5200.0, 'vat' => 1092.0, 'foreign_base' => 200.0, 'foreign_vat' => 42.0]], 6292.0);
        self::assertIsString(IssuedCurrencySnapshot::prepare($amounts, 26.0, 145.2));

        $result = IssuedCurrencySnapshot::prepare($amounts, 26.0, 145.2, 96.8);

        self::assertIsArray($result);
        self::assertSame(242.0, $result['total']);
        self::assertSame(96.8, $result['advance']);
        self::assertSame(2420.0, $result['snapshot']['import_advance_paid_czk']);
        self::assertSame(3872.0, $result['snapshot']['import_amount_to_pay_czk']);
    }

    public function testLinkedProformaCanMatchOnGrossWhileKeepingTheSourceVatBreakdown(): void
    {
        $amounts = self::amounts([['base' => 2000.0, 'vat' => 420.0, 'foreign_base' => 81.0, 'foreign_vat' => 15.8]], 2420.0);
        self::assertIsString(IssuedCurrencySnapshot::prepare($amounts, 25.0, 96.8));

        $result = IssuedCurrencySnapshot::prepare($amounts, 25.0, 96.8, null, true);

        self::assertIsArray($result);
        self::assertSame(15.8, $result['vat']);
        self::assertSame(420.0, $result['items'][0]['home_vat']);
        self::assertIsString(IssuedCurrencySnapshot::prepare($amounts, 26.0, 96.8, null, true));
    }

    private static function amounts(array $items, float $total): array
    {
        return [
            'items' => $items, 'base' => array_sum(array_column($items, 'base')),
            'vat' => array_sum(array_column($items, 'vat')), 'total' => $total, 'rounding' => 0.0, 'advance' => 0.0,
        ];
    }
}
