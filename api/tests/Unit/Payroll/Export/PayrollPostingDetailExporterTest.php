<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Export;

use MyInvoice\Service\Payroll\Export\PayrollPostingDetailExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class PayrollPostingDetailExporterTest extends TestCase
{
    public function testXlsxHasSummaryAboveTheEmployeeBreakdown(): void
    {
        $out = (new PayrollPostingDetailExporter())->xlsx($this->report(), 'Vzorová firma s.r.o.');
        self::assertSame('zauctovani-mezd-2026-09.xlsx', $out['filename']);

        $file = tempnam(sys_get_temp_dir(), 'paypost_test_') . '.xlsx';
        file_put_contents($file, $out['bytes']);
        try {
            $sheet = IOFactory::load($file)->getActiveSheet();
            $cells = $sheet->toArray(null, false, false, false);
        } finally {
            @unlink($file);
        }

        self::assertSame('Zaúčtování mezd 09/2026, Vzorová firma s.r.o.', $cells[0][0]);
        self::assertSame('A. Souhrnné zaúčtování za firmu', $cells[2][0]);
        self::assertSame(['Druh mzdy', 'Účet MD', 'Účet Dal', 'Hodiny', 'Částka Kč'], array_slice($cells[3], 0, 5));
        self::assertEquals(['Základní mzda', '521', '331', 12.5, 500.0], array_slice($cells[4], 0, 5));
        self::assertEquals(['Sociální pojištění zaměstnance', '331', '336.100', null, 35.5], array_slice($cells[5], 0, 5));
        self::assertSame('Celkem (2 řádků)', $cells[6][0]);
        self::assertEquals(535.5, $cells[6][4]);
        self::assertSame('B. Podrobné zaúčtování po zaměstnancích a druzích mezd', $cells[9][0]);
        self::assertSame(PayrollPostingDetailExporter::DETAIL_HEADERS, array_slice($cells[10], 0, 8));
        self::assertEquals(['7', 'Nováková Jana', 'Základní mzda', '521', '331', 'VYROBA', 12.5, 500.0], array_slice($cells[11], 0, 8));
        self::assertSame("'=1+1", "'" . $cells[12][1], 'Text, který vypadá jako vzorec, zůstane textem.');
        self::assertEquals(535.5, $cells[13][7]);
    }

    public function testCsvCarriesTheDetailRows(): void
    {
        $out = (new PayrollPostingDetailExporter())->csv($this->report());
        self::assertSame('zauctovani-mezd-2026-09.csv', $out['filename']);
        $lines = explode("\n", trim(substr($out['bytes'], 3)));

        self::assertSame('"Osobní číslo";Jméno;"Druh mzdy";"Účet MD";"Účet Dal";Středisko;Hodiny;"Částka Kč"', $lines[0]);
        self::assertSame('7;"Nováková Jana";"Základní mzda";521;331;VYROBA;12,50;500,00', $lines[1]);
        self::assertSame("7;'=1+1;\"Sociální pojištění zaměstnance\";331;336.100;;;35,50", $lines[2]);
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return [
            'period' => '2026-09',
            'runs' => [],
            'summary' => [
                ['label' => 'Základní mzda', 'debit_account' => '521', 'credit_account' => '331', 'hours' => 12.5, 'amount_minor' => 50_000],
                ['label' => 'Sociální pojištění zaměstnance', 'debit_account' => '331', 'credit_account' => '336.100', 'hours' => null, 'amount_minor' => 3_550],
            ],
            'rows' => [
                [
                    'employee_id' => 1, 'personal_number' => '7', 'name' => 'Nováková Jana', 'label' => 'Základní mzda',
                    'component_code' => 'MZDA', 'debit_account' => '521', 'credit_account' => '331', 'cost_center' => 'VYROBA',
                    'hours' => 12.5, 'amount_minor' => 50_000,
                ],
                [
                    'employee_id' => 1, 'personal_number' => '7', 'name' => '=1+1', 'label' => 'Sociální pojištění zaměstnance',
                    'component_code' => null, 'debit_account' => '331', 'credit_account' => '336.100', 'cost_center' => null,
                    'hours' => null, 'amount_minor' => 3_550,
                ],
            ],
            'totals' => ['amount_minor' => 53_550, 'hours' => 12.5],
        ];
    }
}
