<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use MyInvoice\Service\WorkReport\WorkReportBilling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkReportBillingTest extends TestCase
{
    public static function settings(): array
    {
        return ['name' => 'Syntetické ujednání', 'currency' => 'CZK', 'fixed_fee' => 1000,
            'included_hours' => 2, 'fixed_description' => '{nazev} {obdobi} paušál {hodiny}',
            'bands' => [['up_to_hours' => 5, 'rate' => 300, 'description' => '{nazev} {od}–{do}: {hodiny}'],
                ['up_to_hours' => null, 'rate' => 200, 'description' => '{zakazka}: {hodiny}']]];
    }

    #[DataProvider('thresholds')]
    public function testMarginalBandsAndFullFixedFee(float $hours, float $expected, array $quantities): void
    {
        $preview = WorkReportBilling::calculate(['title' => 'Podpora', 'billing_snapshot' => self::settings(), 'items' => [['hours' => $hours]], 'billing_period' => '2026-11']);
        self::assertEquals($expected, $preview['total_amount']);
        self::assertEquals($quantities, array_column($preview['items'], 'quantity'));
        self::assertSame('Podpora 2026-11 paušál 2', $preview['items'][0]['description']);
    }

    public static function thresholds(): array
    {
        return [[0, 1000, [1]], [1, 1000, [1]], [2, 1000, [1]], [3, 1300, [1, 1]], [5, 1900, [1, 3]], [7, 2300, [1, 3, 2]]];
    }

    public function testExactMinutesAndGrossPrices(): void
    {
        $settings = self::settings();
        $settings['fixed_fee'] = null;
        $settings['included_hours'] = 0;
        $settings['bands'] = [['up_to_hours' => null, 'rate' => 121, 'description' => '{nazev}']];
        $result = WorkReportBilling::calculate(['billing_snapshot' => $settings, 'items' => [['duration_minutes' => 20, 'hours' => 0.33]]], ['prices_include_vat' => true], 21);
        self::assertSame(20, $result['items'][0]['duration_minutes']);
        self::assertSame(40.33, $result['totals']['with_vat']);
        self::assertSame(33.33, $result['totals']['without_vat']);
        self::assertSame(7.0, $result['totals']['vat']);
    }

    public function testMixedDecimalAndMinuteHoursUseInvoiceQuantityPrecision(): void
    {
        $settings = self::settings();
        $settings['fixed_fee'] = 0;
        $settings['included_hours'] = 0.015;
        $settings['bands'] = [['up_to_hours' => null, 'rate' => 1000, 'description' => '{hodiny} h']];
        $result = WorkReportBilling::calculate(['billing_period' => '2026-11', 'billing_snapshot' => $settings,
            'items' => [['hours' => 0.01], ['duration_minutes' => 20]]]);
        self::assertNull($result['items'][1]['duration_minutes']);
        self::assertSame(0.328, $result['items'][1]['quantity']);
        self::assertSame(328.0, $result['total_amount']);
        self::assertSame('0.328 h', $result['items'][1]['description']);
    }

    public function testNonzeroSegmentBelowInvoicePrecisionIsRejected(): void
    {
        $settings = self::settings();
        $settings['included_hours'] = 0.9999;
        $this->expectException(\InvalidArgumentException::class);
        WorkReportBilling::calculate(['billing_period' => '2026-11', 'billing_snapshot' => $settings, 'items' => [['hours' => 1]]]);
    }

    public function testInvalidBandsAreRejected(): void
    {
        $settings = self::settings();
        $settings['bands'][0]['up_to_hours'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        WorkReportBilling::validate($settings);
    }

    public function testPeriodPlaceholderRequiresExplicitPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkReportBilling::calculate(['billing_snapshot' => self::settings()]);
    }

    public function testDifferentCurrencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkReportBilling::calculate(['billing_snapshot' => self::settings()], ['currency' => 'EUR']);
    }

    public function testReportContentChangesFingerprintEvenWhenTotalHoursDoNotChange(): void
    {
        $report = ['billing_period' => '2026-11', 'billing_snapshot' => self::settings(), 'items' => [['description' => 'Původní', 'hours' => 2]]];
        $first = WorkReportBilling::calculate($report)['fingerprint'];
        $report['items'][0]['description'] = 'Upravená práce';
        self::assertNotSame($first, WorkReportBilling::calculate($report)['fingerprint']);
    }
}
