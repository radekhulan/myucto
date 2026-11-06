<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxDocumentRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class NonprofitTaxDocumentRendererTest extends TestCase
{
    public static function payload(): array
    {
        return ['current_confirmed' => false, 'dppo_supported' => false, 'current_check' => ['complete' => false, 'findings' => [['code' => 'assessment_stale']], 'input_hash' => str_repeat('b', 64)],
            'revision' => ['id' => 42, 'supplier_id' => 7, 'revision_no' => 2, 'status' => 'confirmed', 'created_by' => 9, 'created_at' => '2026-10-17 09:00:00',
                'reason' => '=HYPERLINK("https://invalid.example", "Syntetický důkaz")', 'ruleset' => 'cz-nonprofit-assessment-2024-2026-v1', 'input_hash' => str_repeat('a', 64),
                'snapshot' => ['period' => ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']],
                'assessment' => ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Doložené syntetické stanovy'], 'activities' => [1 => ['evidence' => 'Sportovní činnost']], 'funding_sources' => [], 'funding_costs' => []],
                'result' => ['accounting_result_cents' => 13040000, 'excluded_revenue_cents' => 10940000, 'cost_addbacks_cents' => 7900000, 'assessed_result_cents' => 10000000,
                    'activities' => [['activity_kind_id' => '1', 'cohort' => '', 'regime' => 'subject', 'revenue_cents' => 10000000, 'cost_cents' => 9500000, 'contribution_cents' => 500000]],
                    'rows' => [['id' => 'line:8', 'line_id' => 8, 'account_code' => '602', 'date' => '2026-06-01', 'activity_kind_id' => 1, 'axes' => ['activity_kind' => ['name' => 'Sportovní činnost']],
                        'classification' => ['regime' => 'subject', 'legal_basis' => 'zdp_18a_1a_3'], 'amount_cents' => 10000000, 'deductible_cents' => 0, 'adjustment_cents' => 0,
                        'decision' => ['evidence' => 'Syntetický doklad <test>', 'facts' => []], 'origin' => ['line_id' => 8]]],
                    'funding' => [['source_id' => 'line:10', 'cost_id' => 'line:11', 'amount_cents' => 3000000, 'evidence' => 'Syntetické čerpání']],
                    'funding_usage' => [['anchor' => 'line:10', 'members' => ['line:10', 'line:12'], 'net_source_cents' => 8000000, 'prior_drawdown_cents' => 4000000, 'drawdown_cents' => 3000000, 'remaining_cents' => 1000000]],
                    'external_adjustments' => [['funding_anchor' => 'line:15', 'adjustment_cents' => 12345, 'evidence' => 'Doložená samostatná úprava', 'legal_basis' => 'Vlastní doložené posouzení §23', 'included_in_bridge' => false]]]]];
    }

    public function testXlsxContainsExactNonzeroBridgeAndSeparateAdjustmentsForStaleRevision(): void
    {
        $renderer = new NonprofitTaxDocumentRenderer();
        $path = tempnam(sys_get_temp_dir(), 'nonprofit_export_');
        try {
            file_put_contents($path, $renderer->xlsx(self::payload()));
            $book = IOFactory::load($path);
            self::assertSame(130400.0, (float) $book->getSheetByName('Přechod výsledku')->getCell('B2')->getValue());
            self::assertSame(100000.0, (float) $book->getSheetByName('Přechod výsledku')->getCell('B5')->getValue());
            self::assertSame('n', $book->getSheetByName('Přechod výsledku')->getCell('B5')->getDataType());
            self::assertSame(123.45, (float) $book->getSheetByName('Oddělené úpravy')->getCell('B2')->getValue());
            self::assertSame('Historická nebo neaktuální revize', $book->getSheetByName('Posouzení')->getCell('B6')->getValue());
            self::assertSame('42 / 2', $book->getSheetByName('Posouzení')->getCell('B4')->getValue());
            self::assertSame('s', $book->getSheetByName('Posouzení')->getCell('B9')->getDataType());
            self::assertSame(self::payload()['revision']['reason'], $book->getSheetByName('Posouzení')->getCell('B9')->getValue());
            self::assertSame(30000.0, (float) $book->getSheetByName('Čerpání zdrojů')->getCell('E2')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }

    public function testManualExportClearlyDisclosesItsEvidenceAndUnverifiedTaxRegime(): void
    {
        $payload = self::payload();
        $payload['revision']['assessment']['profile']['kind'] = 'documented';
        $payload['revision']['assessment']['method'] = ['mode' => 'documented_manual', 'valid_from' => '2023-01-01', 'valid_to' => '2023-12-31',
            'legal_basis' => 'Synthetic law applicable to historical period', 'evidence' => 'Synthetic signed classification'];
        $payload['revision']['result']['assessment_method'] = 'documented_manual';
        $payload['revision']['result']['activities'] = [];
        $meta = array_column((new NonprofitTaxDocumentRenderer())->tables($payload)['Posouzení'][1], 1, 0);
        self::assertStringContainsString('bez automatického ověření', $meta['Způsob posouzení']);
        self::assertSame('Synthetic law applicable to historical period', $meta['Právní důvod ruční metody']);
        self::assertSame('Synthetic signed classification', $meta['Podklad ruční metody']);
        self::assertSame('2023-01-01 - 2023-12-31', $meta['Účinnost ruční metody']);
    }

    public function testMissingTotalCannotBecomeZeroInPdfOrWorkbook(): void
    {
        $payload = self::payload();
        unset($payload['revision']['result']['cost_addbacks_cents']);
        $this->expectException(\UnexpectedValueException::class);
        (new NonprofitTaxDocumentRenderer())->tables($payload);
    }

    public function testPdfContainsRenderedDocumentAndPreservesAuthoritativePayload(): void
    {
        $payload = self::payload();
        $before = $payload;
        $pdf = (new NonprofitTaxDocumentRenderer())->render($payload);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(10000, strlen($pdf));
        self::assertSame($before, $payload);
    }
}
