<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\TripImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Import knihy jízd ze sešitu: platné XLSX/XLS/ODS projdou (datum z Excel seriálu),
 * sešit s buňkou na nejvyšší souřadnici ani ODS s opakovaným řádkem nevyčerpá paměť.
 */
#[Group('integration')]
final class TripImportSpreadsheetTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void
    {
        $this->bootLogbook();
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    /** @return array<string, array{string, string}> */
    public static function formatProvider(): array
    {
        return ['xlsx' => ['Xlsx', 'jizdy.xlsx'], 'xls' => ['Xls', 'jizdy.xls'], 'ods' => ['Ods', 'jizdy.ods']];
    }

    #[DataProvider('formatProvider')]
    public function testValidWorkbookImports(string $writerType, string $filename): void
    {
        $this->car($this->supplierA, '9SS 0001', true);
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray(['datum', 'auto', 'km_zacatek', 'km_konec', 'ucel'], null, 'A1');
        $sheet->setCellValue('A2', ExcelDate::PHPToExcel(new \DateTimeImmutable('2099-06-01')));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $sheet->fromArray(['9SS 0001', 100, 142.5, 'Schůzka'], null, 'B2');
        $sheet->fromArray(['02.06.2099', '9SS 0001', 142.5, 150, 'Pošta'], null, 'A4'); // řádek 3 prázdný

        $report = $this->service()->import($this->supplierA, $this->userId, $this->save($ss, $writerType), $filename, true);

        self::assertTrue($report['ok'], (string) ($report['error'] ?? ''));
        self::assertSame(2, $report['created'], json_encode($report['rows'], JSON_UNESCAPED_UNICODE));
        self::assertSame('2099-06-01', $report['rows'][0]['trip_date']);
        self::assertSame(42.5, $report['rows'][0]['distance_km']);
        self::assertSame(4, $report['rows'][1]['line']);
        self::assertSame('2099-06-02', $report['rows'][1]['trip_date']);
    }

    public function testCellAtHighestCoordinateDoesNotExhaustMemory(): void
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray(['datum', 'auto', 'ujeto'], null, 'A1');
        $sheet->setCellValue('XFD1048576', 'x');
        $bytes = $this->save($ss, 'Xlsx');

        $before = memory_get_usage(true);
        $report = $this->service()->import($this->supplierA, $this->userId, $bytes, 'bomb.xlsx', true);

        self::assertTrue($report['ok']);
        self::assertSame(0, $report['created']);
        self::assertLessThan(64 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    public function testOdsRepeatedDataRowIsRejected(): void
    {
        $report = $this->service()->import($this->supplierA, $this->userId, $this->odsWithRepeatedRow(1_000_000), 'bomb.ods', true);

        self::assertFalse($report['ok']);
        self::assertStringContainsString('řádků', (string) $report['error']);
    }

    public function testTooManyRowsIsRejected(): void
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setCellValue('A1', 'datum');
        $sheet->setCellValue('A' . (TripImportService::MAX_ROWS + 2), '01.01.2099');

        $report = $this->service()->import($this->supplierA, $this->userId, $this->save($ss, 'Xlsx'), 'big.xlsx', true);

        self::assertFalse($report['ok']);
        self::assertStringContainsString((string) TripImportService::MAX_ROWS, (string) $report['error']);
    }

    public function testNonSpreadsheetContentIsRejectedWithoutTempLeftovers(): void
    {
        $tmpDir = sys_get_temp_dir();
        $before = glob($tmpDir . DIRECTORY_SEPARATOR . 'ssimp_*') ?: [];

        $report = $this->service()->import($this->supplierA, $this->userId, '<html>nic</html>', 'jizdy.xlsx', true);

        self::assertFalse($report['ok']);
        self::assertSame($before, glob($tmpDir . DIRECTORY_SEPARATOR . 'ssimp_*') ?: []);
    }

    private function service(): TripImportService
    {
        return $this->container->get(TripImportService::class);
    }

    private function save(Spreadsheet $ss, string $type): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tripss_');
        try {
            IOFactory::createWriter($ss, $type)->save($path);
            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** Minimální ODS, jehož datový řádek je opakovaný přes table:number-rows-repeated. */
    private function odsWithRepeatedRow(int $repeat): string
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2">'
            . '<office:body><office:spreadsheet><table:table table:name="List1">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>datum</text:p></table:table-cell></table:table-row>'
            . '<table:table-row table:number-rows-repeated="' . $repeat . '">'
            . '<table:table-cell office:value-type="string" table:number-columns-repeated="20"><text:p>x</text:p></table:table-cell>'
            . '</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>';
        $path = tempnam(sys_get_temp_dir(), 'tripods_');
        try {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
            $zip->addFromString('content.xml', $content);
            $zip->addFromString('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
                . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
                . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
                . '</manifest:manifest>');
            $zip->close();
            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
