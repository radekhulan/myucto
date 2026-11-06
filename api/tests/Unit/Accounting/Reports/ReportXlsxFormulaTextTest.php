<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Název účtu z účtové osnovy je uživatelský text: v přehledu o peněžních tocích
 * i o změnách vlastního kapitálu se musí zapsat jako řetězec, ne jako vzorec.
 * Částky zůstávají čísly.
 */
final class ReportXlsxFormulaTextTest extends TestCase
{
    private const ENTITY = ['name' => 'Testovací s.r.o.', 'ico' => '12345678', 'address' => 'Praha', 'prepared_at' => '2094-12-31'];
    private const FORMULA = '=HYPERLINK("https://example.test","klik")';

    public function testCashFlowWritesAccountNameAsText(): void
    {
        $sheet = $this->load((new ReportXlsxExporter())->cashFlow([
            'entity' => self::ENTITY,
            'period' => ['starts_on' => '2094-01-01', 'ends_on' => '2094-12-31'],
            'opening' => 0.0, 'operating' => 50.0, 'investing' => 0.0, 'financing' => 0.0,
            'unclassified' => 0.0, 'net_change' => 50.0, 'closing' => 50.0, 'reconciles' => true,
            'breakdown' => ['operating' => [['account_code' => '602', 'name' => self::FORMULA, 'amount' => 50.0]]],
        ]))->getSheet(0);

        $cell = $this->findCell($sheet, self::FORMULA);
        self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $amount = $sheet->getCell('C' . $cell->getRow());
        self::assertSame(DataType::TYPE_NUMERIC, $amount->getDataType());
        self::assertEqualsWithDelta(50.0, $amount->getValue(), 0.001);
    }

    public function testEquityChangesWritesAccountNameAsText(): void
    {
        $sheet = $this->load((new ReportXlsxExporter())->equityChanges([
            'entity' => self::ENTITY,
            'period' => ['starts_on' => '2094-01-01', 'ends_on' => '2094-12-31'],
            'rows' => [['account_code' => '411', 'name' => self::FORMULA, 'opening' => 100.0, 'increase' => 0.0, 'decrease' => 0.0, 'closing' => 100.0]],
            'totals' => ['opening' => 100.0, 'increase' => 0.0, 'decrease' => 0.0, 'closing' => 100.0],
            'reconciles' => true,
        ]))->getSheet(0);

        $cell = $this->findCell($sheet, self::FORMULA);
        self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $closing = $sheet->getCell('F' . $cell->getRow());
        self::assertSame(DataType::TYPE_NUMERIC, $closing->getDataType());
        self::assertEqualsWithDelta(100.0, $closing->getValue(), 0.001);
    }

    private function findCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $value): \PhpOffice\PhpSpreadsheet\Cell\Cell
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === $value) {
                    return $cell;
                }
            }
        }
        self::fail('Název účtu se do XLSX nezapsal jako text: ' . $value);
    }

    /** @param array{bytes:string} $out */
    private function load(array $out): Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rxf_') . '.xlsx';
        file_put_contents($tmp, $out['bytes']);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
