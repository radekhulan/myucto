<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxAccountingImporterReportTest extends TestCase
{
    #[DataProvider('reports')]
    public function testPartialImportReflectsWarningsAndSkippedRecords(array $report, bool $expected): void
    {
        self::assertSame($expected, StereoNxAccountingImporter::isPartialReport($report));
    }

    public static function reports(): iterable
    {
        yield 'prázdný protokol' => [[], false];
        yield 'pouze potvrzené počáteční stavy' => [['warnings' => [
            ['level' => 'info', 'code' => 'bank_opening_balance'], ['level' => 'info', 'code' => 'cash_opening_balance']]], false];
        yield 'neověřený stav' => [['warnings' => [['level' => 'warning', 'code' => 'cash_opening_unverified']]], true];
        yield 'historická zpráva bez úrovně' => [['warnings' => [['code' => 'pending_reconciliation']]], true];
        yield 'nepřevedená agenda' => [['warnings' => [['level' => 'info']], 'not_transferred' => [['table' => 'CPokl', 'count' => 1]]], true];
        yield 'informace a varování' => [['warnings' => [['level' => 'info'], ['level' => 'warning']]], true];
    }
}
