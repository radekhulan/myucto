<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class CatalogImportReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function file(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-reader-');
        $this->files[] = $path;
        file_put_contents($path, $content);
        return $path;
    }

    public function testCsvPreservesIdentifiersFormulaTextAndQuotedMultilineCells(): void
    {
        $path = $this->file("\xEF\xBB\xBFsku;description;price\r\n000012;\"První\nDruhý; text\";100.00\r\n000013;=1+1;100\r\n");
        $rows = iterator_to_array((new CatalogImportReader())->rows($path, 'csv'));
        self::assertSame(['sku', 'description', 'price'], $rows[1]);
        self::assertSame(['000012', "První\nDruhý; text", '100.00'], $rows[2]);
        self::assertSame(['000013', '=1+1', '100'], $rows[3]);
    }

    public function testLegacyEncodingIsExplicitAndConverted(): void
    {
        $path = $this->file(iconv('UTF-8', 'Windows-1250', "sku;nazev\n001;Žluťoučký\n"));
        $rows = iterator_to_array((new CatalogImportReader())->rows($path, 'csv', ['encoding' => 'Windows-1250']));
        self::assertSame('Žluťoučký', $rows[2][1]);
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new CatalogImportReader())->rows($path, 'csv'));
    }

    public function testTenThousandRowsStreamWithoutIdentifierConversion(): void
    {
        $path = $this->file();
        $stream = fopen($path, 'wb');
        fwrite($stream, "sku;name\n");
        for ($i = 1; $i <= 10000; $i++) {
            fputcsv($stream, [str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'Fixture'], ';', '"', '');
        }
        fclose($stream);
        $count = 0;
        foreach ((new CatalogImportReader())->rows($path, 'csv') as $line => $values) {
            $count++;
            if ($line > 1) {
                self::assertSame(str_pad((string) ($line - 1), 8, '0', STR_PAD_LEFT), $values[0]);
            }
        }
        self::assertSame(10001, $count);
    }

    public function testXlsxCrossesChunkBoundaryAndNeverEvaluatesFormula(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', 'sku');
        for ($row = 2; $row <= 502; $row++) {
            $sheet->setCellValueExplicit('A' . $row, str_pad((string) $row, 8, '0', STR_PAD_LEFT), DataType::TYPE_STRING);
        }
        $sheet->setCellValue('B501', '=1+1');
        $path = $this->file();
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        $rows = iterator_to_array((new CatalogImportReader())->rows($path, 'xlsx'));
        self::assertCount(502, $rows);
        self::assertSame('00000500', $rows[500][0]);
        self::assertSame(['00000501', '=1+1'], $rows[501]);
        self::assertSame('00000502', $rows[502][0]);
        $resumed = iterator_to_array((new CatalogImportReader())->rows($path, 'xlsx', [], 498));
        self::assertSame(array_slice($rows, 498, null, true), $resumed);
    }

    public function testOversizedCellIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new CatalogImportReader())->rows($this->file(str_repeat('x', 100001)), 'csv'));
    }

    public function testZipBombIsRejectedBeforeSpreadsheetLoading(): void
    {
        $path = $this->file();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('oversized.xml', str_repeat('x', 50_000_001));
        $zip->addFromString('oversized2.xml', str_repeat('x', 50_000_001));
        $zip->close();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('import_xlsx_too_large');
        iterator_to_array((new CatalogImportReader())->rows($path, 'xlsx'));
    }
}
