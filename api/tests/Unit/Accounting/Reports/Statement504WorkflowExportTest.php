<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\{Statement504Builder, ReportXlsxExporter};
use MyInvoice\Service\Pdf\{BalanceSheetPdfRenderer, IncomeStatementPdfRenderer};
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class Statement504WorkflowExportTest extends TestCase
{
    public static function data(string $type): array
    {
        $pl = [['code'=>'602','activity'=>'main','balance_cents'=>200000], ['code'=>'649','activity'=>'economic','balance_cents'=>-350000]];
        $builder = new Statement504Builder();
        $values = $type === 'balance_sheet'
            ? $builder->balanceSheet([['code'=>'221','opening_cents'=>100000,'closing_cents'=>275000],
                ['code'=>'082','opening_cents'=>0,'closing_cents'=>-25000], ['code'=>'901','opening_cents'=>-100000,'closing_cents'=>-100000]], $pl, 'full')
            : $builder->incomeStatement($pl, 'full');
        return ['accounting_framework'=>'cz_504','version_code'=>'vyhl504-2002/2016','statement_type'=>$type,
            'as_of'=>'2099-06-30','scope'=>'full','period'=>['id'=>1,'fiscal_year'=>2099,'starts_on'=>'2098-07-01','ends_on'=>'2099-06-30'],
            'entity'=>['name'=>'Synthetic association','ico'=>'','address'=>'Test 1','prepared_at'=>'01.07.2099 10:00']] + $values;
    }

    public function testBalanceXlsxKeepsSignedOpeningAndUnavailableCells(): void
    {
        $out = (new ReportXlsxExporter())->balanceSheet(self::data('balance_sheet'));
        $rows = $this->xlsxRows($out['bytes']);
        $byCode = array_column(array_filter($rows, static fn(array $r):bool => is_string($r[0] ?? null)), null, 0);
        self::assertSame(-250.0, $byCode['A.IV.7.'][3]);
        self::assertNull($byCode['A.II.1.'][2]);
        self::assertSame(1500.0, $byCode['A.II.1.'][3]);
        self::assertStringNotContainsString('Brutto', json_encode($rows));
        self::assertStringContainsString('504/2002', json_encode($rows, JSON_UNESCAPED_SLASHES));
        self::assertStringContainsString('01.07.2099 10:00', json_encode($rows));
    }

    public function testIncomeXlsxHasThreeActivityColumnsWithLoss(): void
    {
        $rows = $this->xlsxRows((new ReportXlsxExporter())->incomeStatement(self::data('income_statement'))['bytes']);
        $result = array_values(array_filter($rows, static fn(array $r):bool => ($r[0] ?? '') === 'D.'))[0];
        self::assertSame([-2000.0,3500.0,1500.0], array_slice($result,2,3));
    }

    public function testActualPdfExportsExistForBoth504Statements(): void
    {
        self::assertStringStartsWith('%PDF-', (new BalanceSheetPdfRenderer())->render(self::data('balance_sheet')));
        self::assertStringStartsWith('%PDF-', (new IncomeStatementPdfRenderer())->render(self::data('income_statement')));
    }

    private function xlsxRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'statement504_');
        try {
            file_put_contents($path, $bytes);
            $book = IOFactory::load($path);
            return $book->getActiveSheet()->toArray(null, false, false);
        } finally {
            if (isset($book)) $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
