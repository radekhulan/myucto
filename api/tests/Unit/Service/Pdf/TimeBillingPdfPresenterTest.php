<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\TimeBillingPdfPresenter;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TimeBillingPdfPresenterTest extends TestCase
{
    public function testInvoiceMinuteItemGetsClockDurationAndPreciseHourlyRate(): void
    {
        $items = TimeBillingPdfPresenter::invoiceItems([[
            'duration_minutes' => 80,
            'quantity' => 1.333,
            'unit' => 'h',
            'unit_price_without_vat' => 333.333333,
            'total_without_vat' => 444.44,
        ]], false);

        self::assertSame('1:20', $items[0]['duration_display']);
        self::assertSame(333.333333, $items[0]['pdf_unit_price']);
        self::assertSame(6, $items[0]['unit_price_decimals']);
    }

    public function testCentPricesKeepTwoDecimalsAndSubCentStockPriceShowsItsOwn(): void
    {
        $items = TimeBillingPdfPresenter::invoiceItems([
            [
                'duration_minutes' => null,
                'quantity' => 1.5,
                'unit' => 'h',
                'unit_price_without_vat' => 1000.0,
                'total_without_vat' => 1500.0,
            ],
            [
                'duration_minutes' => null,
                'quantity' => 1.0,
                'unit' => 'ks',
                'stock_item_id' => 5,
                'unit_price_without_vat' => 12.5,
                'total_without_vat' => 12.5,
            ],
            [
                'duration_minutes' => null,
                'quantity' => 250000.0,
                'unit' => 'ks',
                'stock_item_id' => 5,
                'unit_price_without_vat' => 0.0108,
                'total_without_vat' => 2700.0,
            ],
        ], false);

        self::assertNull($items[0]['duration_display']);
        self::assertSame(2, $items[0]['unit_price_decimals']);
        self::assertSame(1.5, $items[0]['quantity']);
        self::assertNull($items[1]['duration_display']);
        self::assertSame(2, $items[1]['unit_price_decimals']);
        self::assertSame(4, $items[2]['unit_price_decimals']);
        self::assertSame(0.0108, $items[2]['pdf_unit_price']);
    }

    public function testPurchaseGrossSubCentItemKeepsPreciseNetRate(): void
    {
        $items = TimeBillingPdfPresenter::purchaseInvoiceItems([[
            'description' => 'Víčka',
            'duration_minutes' => null,
            'quantity' => 250000.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 0.004,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 826.45,
            'total_with_vat' => 1000.0,
        ]], true);

        self::assertSame(0.003306, $items[0]['pdf_unit_price']);
        self::assertSame(6, $items[0]['unit_price_decimals']);
    }

    public function testLegacyHourlyItemOptsIntoStoredSubCentRate(): void
    {
        $items = TimeBillingPdfPresenter::invoiceItems([[
            'duration_minutes' => null,
            'quantity' => 60.0,
            'unit' => 'hod',
            'unit_price_without_vat' => 333.33333,
            'total_without_vat' => 20000.0,
        ]], false);

        self::assertNull($items[0]['duration_display']);
        self::assertSame(5, $items[0]['unit_price_decimals']);
    }

    public function testPurchaseGrossLegacyHourlyItemKeepsSubCentNetRate(): void
    {
        $items = TimeBillingPdfPresenter::purchaseInvoiceItems([[
            'description' => 'Konzultace',
            'duration_minutes' => null,
            'quantity' => 3.0,
            'unit' => 'hod',
            'unit_price_without_vat' => 333.333333,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 826.45,
            'total_with_vat' => 1000.0,
        ]], true);

        self::assertNull($items[0]['duration_display']);
        self::assertSame(275.483333, $items[0]['pdf_unit_price']);
        self::assertSame(6, $items[0]['unit_price_decimals']);
        self::assertSame(1000.0, $items[0]['line_total']);
    }

    public function testPurchaseGrossOrdinaryItemKeepsLegacyTwoDecimalNetRate(): void
    {
        $items = TimeBillingPdfPresenter::purchaseInvoiceItems([[
            'description' => 'Materiál',
            'duration_minutes' => null,
            'quantity' => 3.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000.0,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 2479.34,
            'total_with_vat' => 3000.0,
        ]], true);

        self::assertSame(826.45, $items[0]['pdf_unit_price']);
        self::assertSame(2, $items[0]['unit_price_decimals']);
        self::assertSame(3000.0, $items[0]['line_total']);
    }

    public function testWorkReportUsesClockValuesOnlyForExactMinuteRows(): void
    {
        $report = TimeBillingPdfPresenter::workReport([
            'items' => [
                ['duration_minutes' => 1, 'hours' => 0.02, 'rate' => 1000.123456],
                ['duration_minutes' => 80, 'hours' => 1.33, 'rate' => 333.33],
            ],
            'total_hours' => 1.35,
        ]);

        self::assertSame('0:01', $report['items'][0]['duration_display']);
        self::assertSame(6, $report['items'][0]['rate_decimals']);
        self::assertSame('1:20', $report['items'][1]['duration_display']);
        self::assertArrayNotHasKey('rate_decimals', $report['items'][1]);
        self::assertSame('1:21', $report['total_duration_display']);
    }

    public function testMixedLegacyWorkReportKeepsLegacyTotalHours(): void
    {
        $report = TimeBillingPdfPresenter::workReport([
            'items' => [
                ['duration_minutes' => 30, 'hours' => 0.5, 'rate' => 1000.0],
                ['duration_minutes' => null, 'hours' => 0.33, 'rate' => 1000.0],
            ],
            'total_hours' => 0.83,
        ]);

        self::assertSame('0:30', $report['items'][0]['duration_display']);
        self::assertNull($report['items'][1]['duration_display']);
        self::assertArrayNotHasKey('rate_decimals', $report['items'][1]);
        self::assertNull($report['total_duration_display']);
        self::assertSame(0.83, $report['total_hours']);
    }

    public function testWorkReportTemplateRendersExactAndLegacyRowsWithoutChangingLegacyRateFormat(): void
    {
        $report = TimeBillingPdfPresenter::workReport([
            'title' => 'Syntetický výkaz',
            'items' => [
                [
                    'description' => 'Minutová práce',
                    'duration_minutes' => 1,
                    'hours' => 0.02,
                    'rate' => 1000.123456,
                    'total_amount' => 16.67,
                    'work_date' => null,
                ],
                [
                    'description' => 'Historická práce',
                    'duration_minutes' => null,
                    'hours' => 0.33,
                    'rate' => 1000.0,
                    'total_amount' => 330.0,
                    'work_date' => null,
                ],
            ],
            'total_hours' => 0.35,
            'total_amount' => 346.67,
            'material_total' => 0.0,
            'materials' => [],
        ]);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/invoice'), [
            'cache' => false,
            'strict_variables' => false,
        ]);
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));
        $html = $twig->render('work_report.twig', [
            'invoice' => ['id' => 1, 'varsymbol' => null, 'currency' => 'CZK'],
            'supplier' => ['company_name' => 'Test s.r.o.'],
            'work_report' => $report,
            'locale' => 'cs',
            'date_format' => 'j. n. Y',
            'decimal_sep' => ',',
            'thousand_sep' => "\u{00A0}",
            'css' => '',
            'logo_path' => null,
            'logo_show_name' => false,
        ]);

        self::assertStringContainsString('>0:01</td>', $html);
        self::assertStringContainsString('>0,33</td>', $html);
        self::assertStringContainsString('>1' . "\u{00A0}" . '000,123456 CZK</td>', $html);
        self::assertStringContainsString('>1' . "\u{00A0}" . '000,00 CZK</td>', $html);
        self::assertStringContainsString('>0,35 h</td>', $html);
    }
}
