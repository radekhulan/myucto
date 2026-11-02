<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * XLSX výkazů po dimenzi: výsledovka po dimenzi (strom + list po účtech), peněžní tok
 * a řádek „Dimenze: …" u výkazu filtrovaného na hodnotu.
 */
final class DimensionReportXlsxTest extends TestCase
{
    private const ENTITY = ['name' => 'Testovací s.r.o.', 'ico' => '12345678', 'address' => 'Praha', 'prepared_at' => '2094-12-31'];

    public function testDimensionProfitHasTreeAndAccountSheet(): void
    {
        $ss = $this->load((new ReportXlsxExporter())->dimensionProfit([
            'entity' => self::ENTITY,
            'type' => ['name' => 'Středisko'],
            'from' => '2094-01-01',
            'to' => '2094-12-31',
            'supplier_ids' => [1, 2],
            'restricted' => false,
            'rows' => [
                ['code' => 'P', 'name' => 'Výroba', 'depth' => 0, 'has_children' => false, 'responsible_user_name' => 'Jana',
                 'total' => ['revenue' => 300.0, 'cost' => 100.0, 'result' => 200.0]],
            ],
            'unassigned' => ['revenue' => 0.0, 'cost' => 7.0, 'result' => -7.0],
            'totals' => ['revenue' => 300.0, 'cost' => 107.0, 'result' => 193.0],
            'companies' => [
                ['id' => 1, 'name' => 'Firma A', 'revenue' => 300.0, 'cost' => 100.0, 'result' => 200.0],
                ['id' => 2, 'name' => 'Firma B', 'revenue' => 0.0, 'cost' => 7.0, 'result' => -7.0],
                ['id' => 3, 'name' => 'Firma bez pohybu', 'revenue' => 0.0, 'cost' => 0.0, 'result' => 0.0],
            ],
            'matrix' => [
                'columns' => [['key' => '5', 'value_id' => 5, 'code' => 'P', 'name' => 'Výroba'], ['key' => '', 'value_id' => null, 'code' => '', 'name' => null]],
                'rows' => [
                    ['code' => '602', 'name' => 'Tržby', 'account_type' => 'revenue', 'cells' => [300.0, 0.0], 'total' => 300.0],
                    ['code' => '518', 'name' => 'Služby', 'account_type' => 'expense', 'cells' => [100.0, 7.0], 'total' => 107.0],
                ],
                'results' => [200.0, -7.0],
                'total_result' => 193.0,
            ],
        ]));

        $tree = $ss->getSheet(0);
        self::assertSame('Součet za 2 firem skupiny', $tree->getCell('A5')->getValue());
        self::assertSame('Jana', $tree->getCell('C7')->getValue());
        self::assertEqualsWithDelta(200.0, $tree->getCell('F7')->getValue(), 0.001);
        self::assertSame('Bez hodnoty', $tree->getCell('B8')->getValue());
        self::assertEqualsWithDelta(193.0, $tree->getCell('F9')->getValue(), 0.001);

        $matrix = $ss->getSheetByName('Po účtech');
        self::assertNotNull($matrix);
        self::assertSame('P Výroba', $matrix->getCell('C3')->getValue());
        self::assertSame('Bez hodnoty', $matrix->getCell('D3')->getValue());
        self::assertSame('Výnosy', $matrix->getCell('B4')->getValue());
        self::assertSame('602', $matrix->getCell('A5')->getValue());
        self::assertSame('Náklady', $matrix->getCell('B6')->getValue());
        self::assertEqualsWithDelta(107.0, $matrix->getCell('E7')->getValue(), 0.001);
        self::assertEqualsWithDelta(-7.0, $matrix->getCell('D8')->getValue(), 0.001);

        $companies = $ss->getSheetByName('Po firmách');
        self::assertNotNull($companies);
        self::assertSame('Firma A', $companies->getCell('A4')->getValue());
        self::assertSame('Firma B', $companies->getCell('A5')->getValue());
        self::assertSame('Celkem', $companies->getCell('A6')->getValue());
        self::assertEqualsWithDelta(193.0, $companies->getCell('D6')->getValue(), 0.001);
    }

    /**
     * Sloupec „Kód" je data pro strojové zpracování exportu: i u systémové hodnoty
     * zůstává uložený kód (stejně jako před skrytím interních kódů v UI). Jen lidský
     * nadpis sloupce v listu po účtech ukazuje systémovou hodnotu názvem.
     */
    public function testDimensionProfitKeepsStoredCodeOfSystemValue(): void
    {
        $zero = ['revenue' => 0.0, 'cost' => 0.0, 'result' => 0.0];
        $ss = $this->load((new ReportXlsxExporter())->dimensionProfit([
            'entity' => self::ENTITY,
            'type' => ['name' => 'Činnost'],
            'from' => '2094-01-01',
            'to' => '2094-12-31',
            'supplier_ids' => [1],
            'restricted' => true,
            'rows' => [
                ['code' => 'economic', 'name' => 'Hospodářská činnost', 'semantic_key' => 'economic', 'depth' => 0, 'has_children' => false, 'total' => $zero],
                ['code' => 'P', 'name' => 'Projekt', 'semantic_key' => null, 'depth' => 0, 'has_children' => false, 'total' => $zero],
            ],
            'totals' => $zero,
            'matrix' => [
                'columns' => [
                    ['key' => '1', 'value_id' => 1, 'code' => 'economic', 'name' => 'Hospodářská činnost', 'semantic_key' => 'economic'],
                    ['key' => '2', 'value_id' => 2, 'code' => 'P', 'name' => 'Projekt', 'semantic_key' => null],
                ],
                'rows' => [],
                'results' => [0.0, 0.0],
                'total_result' => 0.0,
            ],
        ]));

        $tree = $ss->getSheet(0);
        self::assertSame('economic', $tree->getCell('A7')->getValue());
        self::assertSame('Hospodářská činnost', $tree->getCell('B7')->getValue());
        self::assertSame('P', $tree->getCell('A8')->getValue());

        $matrix = $ss->getSheetByName('Po účtech');
        self::assertNotNull($matrix);
        self::assertSame('Hospodářská činnost', $matrix->getCell('C3')->getValue());
        self::assertSame('P Projekt', $matrix->getCell('D3')->getValue());
    }

    public function testDimensionProfitMatrixHeadersOfSameNamedUnitsStayDistinct(): void
    {
        $zero = ['revenue' => 0.0, 'cost' => 0.0, 'result' => 0.0];
        $ss = $this->load((new ReportXlsxExporter())->dimensionProfit([
            'entity' => self::ENTITY,
            'type' => ['name' => 'Jednotka'],
            'from' => '2094-01-01',
            'to' => '2094-12-31',
            'supplier_ids' => [1],
            'restricted' => false,
            'rows' => [],
            'unassigned' => $zero,
            'totals' => $zero,
            'matrix' => [
                'columns' => [
                    ['key' => '1', 'value_id' => 1, 'code' => 'unit:12', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:12'],
                    ['key' => '2', 'value_id' => 2, 'code' => 'unit:15', 'name' => 'Jednotka 1', 'semantic_key' => 'unit:15'],
                    ['key' => '', 'value_id' => null, 'code' => '', 'name' => null, 'semantic_key' => null],
                ],
                'rows' => [],
                'results' => [0.0, 0.0, 0.0],
                'total_result' => 0.0,
            ],
        ]));

        $matrix = $ss->getSheetByName('Po účtech');
        self::assertNotNull($matrix);
        self::assertSame('Jednotka 1 (unit:12)', $matrix->getCell('C3')->getValue());
        self::assertSame('Jednotka 1 (unit:15)', $matrix->getCell('D3')->getValue());
        self::assertSame('Bez hodnoty', $matrix->getCell('E3')->getValue());
    }

    public function testCashFlowAndDimensionLineOnFilteredStatement(): void
    {
        $group = ['total' => 0.0, 'accounts' => []];
        $ss = $this->load((new ReportXlsxExporter())->dimensionCashFlow([
            'entity' => self::ENTITY,
            'from' => '2094-01-01',
            'to' => '2094-12-31',
            'supplier_ids' => [1],
            'dimension' => ['label' => 'Projekt: G-1 Stavba'],
            'profit' => 510.0,
            'non_cash' => ['total' => 90.0, 'accounts' => [['account_code' => '082', 'name' => 'Oprávky', 'amount' => 90.0]]],
            'working_capital' => $group,
            'operating' => 600.0,
            'investing' => $group,
            'financing' => $group,
            'net_cash_flow' => 600.0,
            'cash_movement' => 450.0,
            'untagged_cash' => 150.0,
            'reconciles' => false,
        ]));
        $sheet = $ss->getSheet(0);
        self::assertSame('Dimenze: Projekt: G-1 Stavba', $sheet->getCell('A5')->getValue());
        self::assertSame('082', $sheet->getCell('A9')->getValue());
        self::assertEqualsWithDelta(150.0, $sheet->getCell('C' . $sheet->getHighestRow())->getValue(), 0.001);

        $is = $this->load((new ReportXlsxExporter())->incomeStatement([
            'entity' => self::ENTITY, 'as_of' => '2094-12-31', 'scope' => 'full', 'rows' => [], 'checks' => [],
            'dimension' => ['label' => 'Středisko: R-A Výroba'],
        ]));
        self::assertStringStartsWith('Dimenze: Středisko: R-A Výroba', (string) $is->getSheet(0)->getCell('A5')->getValue());

        $plain = $this->load((new ReportXlsxExporter())->incomeStatement([
            'entity' => self::ENTITY, 'as_of' => '2094-12-31', 'scope' => 'full', 'rows' => [], 'checks' => [], 'dimension' => null,
        ]));
        self::assertNull($plain->getSheet(0)->getCell('A5')->getValue(), 'Nefiltrovaný výkaz řádek dimenze nemá.');
    }

    /** @param array{bytes:string} $out */
    private function load(array $out): Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dimx_') . '.xlsx';
        file_put_contents($tmp, $out['bytes']);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
