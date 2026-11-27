<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Pdf\ReportPdfRendererBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

/**
 * Výkaz s průběžným časovým rozlišením mimo deník nese v XLSX i PDF zřetelnou poznámku,
 * že úprava není zaúčtovaná. Výkaz bez volby poznámku nemá.
 */
final class AccrualSpreadExportNoteTest extends TestCase
{
    private const ENTITY = ['name' => 'Testovací s.r.o.', 'ico' => '12345678', 'address' => 'Praha', 'prepared_at' => '2094-12-31'];
    private const SPREAD = ['applied' => true, 'skipped_reason' => null, 'prepaid_expense' => 6100.0, 'deferred_revenue' => 24300.0, 'documents' => 2, 'prev' => null];

    public function testXlsxIncomeStatementAndBalanceSheetCarryNote(): void
    {
        $is = $this->load((new ReportXlsxExporter())->incomeStatement([
            'entity' => self::ENTITY, 'as_of' => '2094-10-31', 'scope' => 'full', 'rows' => [], 'checks' => [],
            'dimension' => null, 'accrual_spread' => self::SPREAD,
        ]));
        $note = (string) $is->getSheet(0)->getCell('A5')->getValue();
        self::assertStringStartsWith('Průběžné časové rozlišení a odpisy mimo deník', $note);
        self::assertStringContainsString('6 100,00 Kč', $note);
        self::assertStringContainsString('24 300,00 Kč', $note);

        $bs = $this->load((new ReportXlsxExporter())->balanceSheet([
            'entity' => self::ENTITY, 'as_of' => '2094-10-31', 'scope' => 'full', 'assets' => [], 'liabilities' => [], 'checks' => [],
            'dimension' => null, 'accrual_spread' => self::SPREAD,
        ]));
        self::assertStringStartsWith('Průběžné časové rozlišení a odpisy mimo deník', (string) $bs->getSheet(0)->getCell('A5')->getValue());

        $plain = $this->load((new ReportXlsxExporter())->incomeStatement([
            'entity' => self::ENTITY, 'as_of' => '2094-10-31', 'scope' => 'full', 'rows' => [], 'checks' => [], 'dimension' => null,
        ]));
        self::assertNull($plain->getSheet(0)->getCell('A5')->getValue());
    }

    public function testPdfTemplatesCarryNote(): void
    {
        $renderer = new class extends ReportPdfRendererBase {
            public function render(array $data): string
            {
                return '';
            }

            /** @param array<string,mixed> $data */
            public function html(string $template, array $data): string
            {
                return $this->renderTemplate($template, $data);
            }
        };
        $base = [
            'entity' => self::ENTITY, 'as_of' => '2094-10-31', 'scope' => 'full', 'period' => ['fiscal_year' => 2094],
            'prev_period' => null, 'version_code' => 'test', 'rows' => [], 'assets' => [], 'liabilities' => [], 'checks' => [],
        ];
        foreach (['income_statement.twig', 'balance_sheet.twig'] as $template) {
            $html = $renderer->html($template, $base + ['accrual_spread' => self::SPREAD]);
            self::assertStringContainsString('Průběžné časové rozlišení a odpisy mimo deník', $html, $template);
            self::assertStringContainsString('6 100,00 Kč', $html, $template);
            self::assertStringNotContainsString('Průběžné časové rozlišení', $renderer->html($template, $base), $template);
        }
    }

    /**
     * Rozvaha a výsledovka po účtech s volbou: poznámka s pozdějšími zápisy a dopadem na VH,
     * upravený účet označený hvězdičkou a výplní, poslední řádek „Výsledek hospodaření k datu".
     */
    public function testXlsxAccountViewMarksAdjustedAccounts(): void
    {
        $raw = [
            ['id' => 1, 'account_code' => '384', 'name' => 'Výnosy příštích období', 'account_type' => 'liability', 'normal_side' => 'credit',
                'parent_id' => null, 'parent_code' => null, 'parent_name' => null, 'parent_normal_side' => null,
                'ps_md' => 0.0, 'ps_d' => 1000.0, 'to_md' => 300.0, 'to_d' => 0.0, 'spread' => 300.0],
            ['id' => 2, 'account_code' => '602', 'name' => 'Tržby z prodeje služeb', 'account_type' => 'revenue', 'normal_side' => 'credit',
                'parent_id' => null, 'parent_code' => null, 'parent_name' => null, 'parent_normal_side' => null,
                'ps_md' => 0.0, 'ps_d' => 0.0, 'to_md' => 0.0, 'to_d' => 300.0, 'spread' => -300.0],
            ['id' => 3, 'account_code' => '221', 'name' => 'Bankovní účty', 'account_type' => 'asset', 'normal_side' => 'debit',
                'parent_id' => null, 'parent_code' => null, 'parent_name' => null, 'parent_normal_side' => null,
                'ps_md' => 1000.0, 'ps_d' => 0.0, 'to_md' => 0.0, 'to_d' => 0.0],
        ];
        $columns = \MyInvoice\Service\Accounting\Reports\StatementAccountColumns::build($raw, [],
            static fn (string $code): string => 'operating', ['operating', 'financial', 'unassigned', 'tax', 'transfer'],
            ['hide_zero' => true, 'analytics' => true, 'from' => '2094-01-01', 'month_from' => '2094-10-01', 'as_of' => '2094-10-31']);
        $spread = self::SPREAD + ['vh_effect' => 300.0, 'later_dated' => ['deferred_revenue' => 300.0, 'prepaid_expense' => 0.0, 'depreciation' => 0.0, 'entries' => 1]];
        $data = ['entity' => self::ENTITY, 'as_of' => '2094-10-31', 'period' => ['fiscal_year' => 2094], 'dimension' => null,
            'checks' => ['profit_matches' => true], 'columns' => $columns, 'accrual_spread' => $spread];

        $sheet = $this->load((new ReportXlsxExporter())->accountView($data, 'balance'))->getSheet(0);
        $note = (string) $sheet->getCell('A6')->getValue();
        self::assertStringContainsString('zaúčtované s datem po rozvahovém dni', $note);
        self::assertStringContainsString('mění o 300,00 Kč', $note);
        $marked = [];
        $profitRow = null;
        foreach ($sheet->getRowIterator(9) as $row) {
            $r = $row->getRowIndex();
            foreach (['A', 'G'] as $col) {
                if (str_ends_with((string) $sheet->getCell($col . $r)->getValue(), ' *')) {
                    $marked[] = trim((string) $sheet->getCell($col . $r)->getValue());
                    self::assertSame('FEF3C7', $sheet->getStyle(chr(ord($col) + 5) . $r)->getFill()->getStartColor()->getRGB());
                }
            }
            if ((string) $sheet->getCell('G' . $r)->getValue() === 'Výsledek hospodaření k 31.10.2094') {
                $profitRow = $r;
            }
        }
        self::assertSame(['384 *'], $marked);
        self::assertNotNull($profitRow);

        $plain = $data;
        unset($plain['accrual_spread']);
        self::assertStringNotContainsString('Průběžné', (string) $this->load((new ReportXlsxExporter())->accountView($plain, 'balance'))->getSheet(0)->getCell('A6')->getValue());
    }

    /** @param array{bytes:string} $out */
    private function load(array $out): Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'accr_') . '.xlsx';
        file_put_contents($tmp, $out['bytes']);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
