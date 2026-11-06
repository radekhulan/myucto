<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\AccountViewPdfRenderer;
use MyInvoice\Service\Pdf\BalanceSheetPdfRenderer;
use MyInvoice\Service\Pdf\IncomeStatementPdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Statement504ExportTest extends TestCase
{
    public static function exporters(): array
    {
        return [
            [BalanceSheetPdfRenderer::class, 'render', []],
            [IncomeStatementPdfRenderer::class, 'render', []],
            [AccountViewPdfRenderer::class, 'render', []],
            [ReportXlsxExporter::class, 'balanceSheet', []],
            [ReportXlsxExporter::class, 'incomeStatement', []],
            [ReportXlsxExporter::class, 'accountView', ['balance_sheet']],
        ];
    }

    #[DataProvider('exporters')]
    public function test504CannotReachA500Template(string $class, string $method, array $args): void
    {
        try {
            (new $class())->$method(['accounting_framework' => 'cz_504', 'version_code' => 'vyhl504-2002/2016'], ...$args);
            self::fail('A 500 template must not render 504 data.');
        } catch (ReportException $e) {
            self::assertSame('statement_export_unsupported', $e->errorCode);
        }
    }
}
