<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Action\Accounting\Closing\ClosingAction;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\Logbook\FuelingExportService;
use MyInvoice\Service\Logbook\LogbookSummaryExportService;
use MyInvoice\Service\Logbook\TripExportService;
use MyInvoice\Service\Payment\PaymentOrderCsvWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class SecurityReportExportTest extends TestCase
{
    public function testCsvGuardHandlesMalformedUtf8AndNewlines(): void
    {
        self::assertSame("'=1+1\xFF", CsvWriter::safe("=1+1\xFF"));
        self::assertSame("'\n=1+1", CsvWriter::safe("\n=1+1"));
    }

    public function testPaymentOrderCsvUsesTheSameSafeEncoding(): void
    {
        $csv = new PaymentOrderCsvWriter()->build(['items' => [[
            'payee_name' => "=1+1\xFF", 'message' => 'text\\";=1+1', 'amount' => 12.5,
        ]]]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, separator: ';', escape: '');
        $row = fgetcsv($stream, separator: ';', escape: '');
        self::assertCount(13, $row);
        self::assertSame("'=1+1\xFF", $row[0]);
        self::assertSame('text\\";=1+1', $row[11]);
        self::assertSame('12.50', $row[5]);
        fclose($stream);
    }

    public function testClosingCsvKeepsUntrustedValuesInOneTextCell(): void
    {
        $csv = new \ReflectionMethod(ClosingAction::class, 'findingsToCsv')->invoke(null, [
            'value' => ['findings' => [['doc_no' => '=1+1', 'partner_name' => "Partner\r\n=1+1", 'amount' => -12.5]]],
        ]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, separator: ';', escape: '');
        $row = fgetcsv($stream, separator: ';', escape: '');
        self::assertCount(9, $row);
        self::assertSame("'=1+1", $row[2]);
        self::assertSame("Partner\r\n=1+1", $row[4]);
        self::assertSame('-12.5', $row[5]);
        self::assertFalse(fgetcsv($stream, separator: ';', escape: ''));
        fclose($stream);
    }

    public function testTripExportWritesTextNotFormula(): void
    {
        $this->assertTextCells(TripExportService::class, [[[
            'trip_date' => '2026-01-01', 'car_registration' => '=1+1', 'origin' => '=1+1',
            'destination' => '=1+1', 'purpose' => '=1+1', 'category_label' => '=1+1',
            'odometer_start' => 0, 'odometer_end' => 1, 'distance_km' => 1,
        ]], '', '=1+1'], ['A2', 'B6', 'C6', 'D6', 'E6', 'F6']);
    }

    public function testFuelingExportWritesTextNotFormula(): void
    {
        $this->assertTextCells(FuelingExportService::class, [[[
            'fueled_date' => '2026-01-01', 'car_registration' => '=1+1', 'fuel_type' => '=1+1',
            'station' => '=1+1', 'quantity' => 1, 'unit_price' => 1, 'amount_without_vat' => 1,
            'amount_with_vat' => 1,
        ]], '', '=1+1'], ['A2', 'B6', 'C6', 'I6']);
    }

    public function testSummaryExportWritesTextNotFormula(): void
    {
        $row = array_fill_keys(['trips_count', 'km', 'business_km', 'uncategorized_km', 'private_km',
            'private_ratio', 'odometer_start', 'odometer_end', 'liters', 'avg_consumption',
            'fuel_cost', 'continuity_issues', 'pausal_year'], 0);
        $this->assertTextCells(LogbookSummaryExportService::class,
            [['vehicles' => [$row + ['registration' => '=1+1']], 'totals' => $row], 2026, '=1+1'], ['A2', 'A5']);
    }

    private function assertTextCells(string $class, array $arguments, array $cells): void
    {
        $service = new \ReflectionClass($class)->newInstanceWithoutConstructor();
        $bytes = new \ReflectionMethod($class, 'xlsx')->invokeArgs($service, $arguments);
        $path = tempnam(sys_get_temp_dir(), 'security-export-');
        try {
            file_put_contents($path, $bytes);
            $spreadsheet = IOFactory::load($path);
            foreach ($cells as $address) {
                $cell = $spreadsheet->getActiveSheet()->getCell($address);
                self::assertSame(DataType::TYPE_STRING, $cell->getDataType(), $class . ':' . $address);
                self::assertSame('=1+1', $cell->getValue());
            }
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
