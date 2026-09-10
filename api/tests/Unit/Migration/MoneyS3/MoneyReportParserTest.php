<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Reconciler;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

final class MoneyReportParserTest extends TestCase
{
    public function testParsesMoneyTrialBalanceExportIntoNetValues(): void
    {
        $parsed = (new MoneyReportParser())->parse(SyntheticAgenda::trialBalanceCsv2024());

        self::assertSame([10000.0, -1500.0, 8500.0], $parsed['accounts']['211']);
        self::assertSame([0.0, -2100.0, -2100.0], $parsed['accounts']['343']);
        self::assertSame([0.0, 0.0, 0.0], $parsed['accounts']['701']);
        self::assertArrayNotHasKey('', $parsed['accounts']);
        self::assertCount(12, $parsed['accounts']);
    }

    public function testSyntheticLineWinsOverItsAnalytics(): void
    {
        $csv = "221;Banka;100,00;0;0;0;100,00;0\n221001;Běžný;60,00;0;0;0;60,00;0\n221002;Spořicí;40,00;0;0;0;40,00;0\n"
            . "518001;Služby A;0;0;10;0;10;0\n518002;Služby B;0;0;5,50;0;5,50;0\n";
        $parsed = (new MoneyReportParser())->parse($csv);

        self::assertSame([100.0, 0.0, 100.0], $parsed['accounts']['221']);
        self::assertSame([0.0, 15.5, 15.5], $parsed['accounts']['518']);
    }

    public function testNetThreeColumnFormatAndTrailingMinus(): void
    {
        $parsed = (new MoneyReportParser())->parse("411\tZákladní kapitál\t60 000,00-\t0,00\t60 000,00-\n");
        self::assertSame([-60000.0, 0.0, -60000.0], $parsed['accounts']['411']);
    }

    public function testCompareReportsOnlyDifferingAccounts(): void
    {
        $diffs = MoneyS3Reconciler::compare(
            ['211' => [1.0, 2.0, 3.0], '221' => [0.0, 5.0, 5.0]],
            ['211' => [1.0, 2.0, 3.0], '221' => [0.0, 5.01, 5.01], '311' => [0.0, 0.0, 0.0]],
        );
        self::assertCount(1, $diffs);
        self::assertSame('221', $diffs[0]['account']);
    }
}
