<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\AccountStatementPdfRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class AccountStatementDimensionExportTest extends TestCase
{
    public function testPdfIncludesDimensionBreakdownAndDiscrepancy(): void
    {
        $renderer = new AccountStatementPdfRenderer();
        $data = self::report();
        $html = (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, 'account_statement.twig', $data);

        self::assertStringContainsString('Rozpad podle: Středisko', $html);
        self::assertStringContainsString('Bez dimenze', $html);
        self::assertStringContainsString('Součet rozpadu nesouhlasí', $html);
        self::assertStringContainsString('Dimenze: Středisko &amp; výroba', $html);
        self::assertStringStartsWith('%PDF', $renderer->render($data));
    }

    public function testXlsxHasSeparateSheetWithNumericTotals(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'acc-dim-');
        try {
            file_put_contents($file, (new ReportXlsxExporter())->accountStatement(self::report())['bytes']);
            $workbook = IOFactory::load($file);
            self::assertStringContainsString('Dimenze: Středisko & výroba', (string) $workbook->getActiveSheet()->getCell('A4')->getValue());
            $sheet = $workbook->getSheetByName('Rozpad podle dimenze');
            self::assertNotNull($sheet);
            self::assertSame('Bez dimenze', $sheet->getCell('A6')->getValue());
            self::assertSame('Celkem', $sheet->getCell('A7')->getValue());
            self::assertSame(12.34, $sheet->getCell('C7')->getValue());
            self::assertSame(-10.34, $sheet->getCell('E7')->getValue());
        } finally {
            unlink($file);
        }
    }

    private static function report(): array
    {
        $sums = ['opening_balance_signed' => 2.0, 'turnover_md' => 12.34, 'turnover_d' => 0.0, 'closing_balance_signed' => -10.34];
        return [
            'account' => ['code' => '321', 'name' => 'Závazky'],
            'from' => '2099-01-01', 'to' => '2099-01-31',
            'items' => [],
            'scope_notes' => ['Dimenze: Středisko & výroba'],
            'dimension_breakdown' => [
                'type_id' => 1, 'type_name' => 'Středisko', 'matches' => false,
                'totals' => $sums,
                'rows' => [
                    ['value_id' => 1, 'code' => 'A', 'name' => 'Výroba'] + $sums,
                    ['value_id' => null, 'code' => null, 'name' => null] + array_fill_keys(array_keys($sums), 0.0),
                ],
            ],
        ] + $sums;
    }
}
