<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReduction;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoReductionTest extends TestCase
{
    public static function limits(): array
    {
        return [
            [100000,'standard',100000], [200000,'standard',200000],
            [800000,'standard',300000], [2100000,'standard',630000],
            [3600000,'standard',1000000], [0,'standard',0],
            [800000,'university_research',800000], [2100000,'university_research',1000000],
            [3600000,'university_research',1080000], [12000000,'university_research',3000000],
            [100000,'ineligible',0], [99999999999999,'standard',1000000],
        ];
    }

    #[DataProvider('limits')]
    public function testStatutoryPercentageFloorAndCap(int $base, string $band, int $expected): void
    {
        self::assertSame($expected, NonprofitDppoReduction::limit($base, $band, \MyInvoice\Service\Tax\TaxConstants::forYear(2026)));
    }

    public static function addbacks(): array
    {
        return [[200000,42000,31500,50000], [100000,21000,0,100000],
            [100000,21000,21000,0], [100000,0,0,0], [1,2,1,1],
            [99999999999999,99999999999999,0,99999999999999]];
    }

    #[DataProvider('addbacks')]
    public function testUnusedSavingAddsBackReductionProportionRatherThanSaving(int $reduction, int $saving, int $used, int $expected): void
    {
        self::assertSame($expected, NonprofitDppoReduction::unusedAddback($reduction, $saving, $used));
    }

    public function testUnsupportedCategoryCannotReceiveAStandardAllowance(): void
    {
        $this->expectException(TaxReturnException::class);
        NonprofitDppoReduction::limit(100000, 'unknown', \MyInvoice\Service\Tax\TaxConstants::forYear(2026));
    }

    public function testUseBeyondActualSavingIsRejected(): void
    {
        $this->expectException(TaxReturnException::class);
        NonprofitDppoReduction::unusedAddback(100000, 21000, 21001);
    }
}
