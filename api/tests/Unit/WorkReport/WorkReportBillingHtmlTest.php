<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** Cenová rekapitulace se tiskne i pro nečerpaný paušál a respektuje skrytí cen. */
final class WorkReportBillingHtmlTest extends TestCase
{
    public function testRecapUsesBillingRowsInsteadOfEntryRates(): void
    {
        $html = $this->render(true);
        self::assertStringContainsString('Paušál syntetický', $html);
        self::assertStringContainsString('Přesah syntetický', $html);
        self::assertStringContainsString('5:00', $html);
        self::assertStringNotContainsString('999', $html);
        self::assertStringContainsString('15 000,00', $html);
    }

    public function testHiddenPricesHideRecap(): void
    {
        $html = $this->render(false);
        self::assertStringNotContainsString('Cenová rekapitulace', $html);
        self::assertStringNotContainsString('Paušál syntetický', $html);
        self::assertStringNotContainsString('15 000', $html);
    }

    public function testInvalidBillingPrintsWorkReportWithoutRecap(): void
    {
        $html = $this->render(true, null, null);
        self::assertStringContainsString('Činnost', $html);
        self::assertStringNotContainsString('Cenová rekapitulace', $html);
    }

    public function testFixedFeeWithoutTimeEntriesStillPrints(): void
    {
        self::assertStringContainsString('Paušál syntetický', $this->render(true, []));
    }

    public function testEveryRenamedBillingRowLinksToReportEvenWithoutTimeEntries(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice'));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));
        $rows = [];
        foreach (['Paušál přejmenovaný', 'Pásmo přejmenované'] as $index => $name) {
            $rows[] = ['description' => $name, 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100,
                'total_without_vat' => 100, 'total_with_vat' => 100, 'item_kind' => 'standard',
                'linked_work_report_id' => 7, 'work_report_billing_key' => $index === 0 ? 'fixed' : 'band:0'];
        }
        $html = $twig->render('invoice.twig', [
            'invoice' => ['id' => 1, 'invoice_type' => 'invoice', 'currency' => 'CZK', 'items' => $rows, 'vat_recap' => [], 'vat_breakdown' => []],
            'supplier' => ['company_name' => 'Syntetická firma', 'is_vat_payer' => false],
            'work_report' => ['id' => 7, 'title' => 'Podrobný výkaz', 'billing_snapshot' => ['name' => 'Test'], 'items' => [], 'materials' => [], 'total_hours' => 0, 'total_amount' => 100, 'material_total' => 0],
            'decimal_sep' => ',', 'thousand_sep' => ' ', 'date_format' => 'j. n. Y',
        ]);
        self::assertSame(2, substr_count($html, 'href="#work-report"'));
        self::assertStringContainsString('<a name="work-report"></a>', $html);
    }

    private function render(bool $amount, ?array $items = null, ?array $preview = ['total_amount' => 15000, 'items' => [
        ['description' => 'Paušál syntetický', 'quantity' => 1, 'duration_minutes' => null, 'unit' => 'ks', 'unit_price_without_vat' => 10000, 'total_amount' => 10000],
        ['description' => 'Přesah syntetický', 'quantity' => 5, 'duration_minutes' => 300, 'unit' => 'h', 'unit_price_without_vat' => 1000, 'total_amount' => 5000],
    ]]): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice'));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));
        return $twig->render('work_report_items.twig', [
            'invoice' => ['currency' => 'CZK', 'prices_include_vat' => false], 'decimal_sep' => ',', 'thousand_sep' => ' ', 'date_format' => 'j. n. Y',
            'work_report' => [
                'billing_snapshot' => ['name' => 'Test'],
                'effective_display_options' => ['compact' => false, 'date' => true, 'hours' => true, 'rate' => true, 'amount' => $amount],
                'total_hours' => 15, 'total_amount' => 15000,
                'items' => $items ?? [['description' => 'Činnost', 'work_date' => null, 'hours' => 15, 'rate' => 999, 'total_amount' => 14985]],
                'billing_preview' => $preview,
            ],
        ]);
    }
}
