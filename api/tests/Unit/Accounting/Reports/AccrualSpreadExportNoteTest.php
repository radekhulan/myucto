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
        self::assertStringStartsWith('Průběžné časové rozlišení mimo deník', $note);
        self::assertStringContainsString('6 100,00 Kč', $note);
        self::assertStringContainsString('24 300,00 Kč', $note);

        $bs = $this->load((new ReportXlsxExporter())->balanceSheet([
            'entity' => self::ENTITY, 'as_of' => '2094-10-31', 'scope' => 'full', 'assets' => [], 'liabilities' => [], 'checks' => [],
            'dimension' => null, 'accrual_spread' => self::SPREAD,
        ]));
        self::assertStringStartsWith('Průběžné časové rozlišení mimo deník', (string) $bs->getSheet(0)->getCell('A5')->getValue());

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
            self::assertStringContainsString('Průběžné časové rozlišení mimo deník', $html, $template);
            self::assertStringContainsString('6 100,00 Kč', $html, $template);
            self::assertStringNotContainsString('Průběžné časové rozlišení', $renderer->html($template, $base), $template);
        }
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
