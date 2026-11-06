<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use MyInvoice\Service\WorkReport\WorkReportDisplayOptions as Display;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class WorkReportDisplayOptionsTest extends TestCase
{
    public function testInheritancePreservesExplicitFalseAndSupportsPartialOverrides(): void
    {
        self::assertSame(Display::DEFAULTS, Display::resolve());
        $options = Display::resolve('{"compact":true,"rate":false,"amount":false}', ['rate' => true]);
        self::assertTrue($options['compact']);
        self::assertTrue($options['rate']);
        self::assertFalse($options['amount']);
        self::assertFalse(Display::resolve(['rate' => false], null)['rate']);
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidApiOptions(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Display::validate($value);
    }

    public static function invalidOptions(): iterable
    {
        yield ['compact'];
        yield [['hours' => 'false']];
        yield [['rate' => 0]];
        yield [['unknown' => true]];
        yield [[true]];
    }

    public function testPublicProjectionRemovesHiddenValuesWithoutChangingTheSource(): void
    {
        $report = $this->report(['date' => false, 'hours' => false, 'rate' => false, 'amount' => false]);
        $public = Display::forPublic($report);
        foreach (['work_date', 'hours', 'duration_minutes', 'rate', 'total_amount'] as $key) {
            self::assertNull($public['items'][0][$key]);
        }
        self::assertNull($public['total_hours']);
        self::assertNull($public['total_amount']);
        self::assertSame(1234.0, $report['items'][0]['rate']);
        self::assertSame(2468.0, $report['total_amount']);
    }

    public function testPublicBillingProjectionDoesNotExposeSnapshotOrHiddenPrices(): void
    {
        $report = $this->report(['hours' => false, 'rate' => false, 'amount' => false]);
        $report['billing_snapshot'] = ['name' => 'Syntetická dohoda', 'fixed_fee' => 9876, 'bands' => [['rate' => 4321]]];
        $report['billing_template_id'] = 12;
        $report['billing_transferred_fingerprint'] = 'internal-fingerprint';
        $report['billing_stale'] = true;
        $report['billing_preview'] = [
            'items' => [['billing_key' => 'fixed', 'description' => 'Služby', 'unit' => 'ks', 'quantity' => 1.0,
                'unit_price_without_vat' => 9876.0, 'total_amount' => 9876.0, 'total_with_vat' => 11949.96, 'linked_work_report_id' => 99]],
            'total_hours' => 8.0, 'included_hours' => 4.0, 'extra_hours' => 4.0, 'total_amount' => 9876.0,
            'fingerprint' => 'internal-fingerprint', 'totals' => ['with_vat' => 11949.96], 'vat_breakdown' => [21 => 2073.96],
        ];
        $public = Display::forPublic($report);
        foreach (['billing_snapshot', 'billing_template_id', 'billing_transferred_fingerprint', 'billing_stale'] as $key) self::assertArrayNotHasKey($key, $public);
        self::assertTrue($public['billing_enabled']);
        foreach (['quantity', 'unit_price_without_vat', 'total_amount'] as $key) self::assertNull($public['billing_preview']['items'][0][$key]);
        foreach (['total_hours', 'included_hours', 'extra_hours', 'total_amount'] as $key) self::assertNull($public['billing_preview'][$key]);
        foreach (['fingerprint', 'totals', 'vat_breakdown'] as $key) self::assertArrayNotHasKey($key, $public['billing_preview']);
        self::assertArrayNotHasKey('linked_work_report_id', $public['billing_preview']['items'][0]);
        self::assertSame(9876.0, $report['billing_preview']['total_amount']);
        self::assertSame('internal-fingerprint', $report['billing_transferred_fingerprint']);

        $report['effective_display_options'] = Display::DEFAULTS;
        $visible = Display::forPublic($report);
        self::assertSame(9876.0, $visible['billing_preview']['total_amount']);
        self::assertSame(4321, $report['billing_snapshot']['bands'][0]['rate']);
    }

    #[DataProvider('columnCombinations')]
    public function testPdfTableKeepsWidthsAndCellCountsForEveryColumnCombination(array $options): void
    {
        $twig = $this->twig();
        $html = $twig->render('work_report_items.twig', $this->context($options));
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $headers = $xpath->query('//thead/tr/th');
        $columns = 1 + (int) $options['date'] + (int) $options['hours'] + (int) $options['rate'] + (int) $options['amount'];
        self::assertCount($columns, $headers);
        $width = 0;
        foreach ($headers as $header) {
            preg_match('/width:(\d+)%/', $header->getAttribute('style'), $match);
            $width += (int) $match[1];
        }
        self::assertSame(100, $width);
        self::assertCount($columns, $xpath->query('//tbody/tr[1]/td'));
        self::assertSame($options['compact'], str_contains($html, 'items-compact'));
        self::assertSame($options['rate'], str_contains($html, 'Sazba'));
        self::assertSame($options['rate'], str_contains($html, '1234 CZK'));
        self::assertSame($options['amount'], str_contains($html, '2468 CZK'));
        self::assertSame($options['hours'], str_contains($html, '>2</td>'));
        $footer = $xpath->query('//tr[@class="wr-total"]');
        self::assertCount($options['hours'] || $options['amount'] ? 1 : 0, $footer);
        if ($footer->length) {
            $footerColumns = 0;
            foreach ($xpath->query('./td', $footer->item(0)) as $cell) {
                $footerColumns += (int) ($cell->getAttribute('colspan') ?: 1);
            }
            self::assertSame($columns, $footerColumns);
        }
    }

    public static function columnCombinations(): iterable
    {
        for ($mask = 0; $mask < 32; $mask++) {
            yield (string) $mask => [[
                'compact' => (bool) ($mask & 1), 'date' => (bool) ($mask & 2),
                'hours' => (bool) ($mask & 4), 'rate' => (bool) ($mask & 8), 'amount' => (bool) ($mask & 16),
            ]];
        }
    }

    public function testStandaloneAndInvoiceAttachmentBothUseTheSelectedColumns(): void
    {
        $context = $this->context(['compact' => true, 'rate' => false, 'amount' => false]);
        $twig = $this->twig();
        foreach (['work_report.twig', 'invoice.twig'] as $template) {
            $html = $twig->render($template, $context);
            self::assertStringContainsString('items items-compact', $html);
            self::assertStringContainsString('Analýza požadavků', $html);
            self::assertStringNotContainsString('1234 CZK', $html);
            self::assertStringNotContainsString('2468 CZK', $html);
        }
    }

    public function testApprovalEmailSummaryRespectsHiddenHoursAndAmounts(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'));
        foreach (['cs', 'en'] as $locale) {
            foreach (['html', 'txt'] as $format) {
                $context = $this->context(['hours' => false, 'amount' => false]);
                $context['locale'] = $locale;
                $html = $twig->render('invoice_approval.' . $locale . '.' . $format . '.twig', $context);
                self::assertStringContainsString('Testovací práce', $html);
                self::assertStringNotContainsString($locale === 'cs' ? 'K vyúčtování' : 'Amount', $html);
                self::assertStringNotContainsString($locale === 'cs' ? 'Hodin celkem' : 'Total hours', $html);
                $context['work_report']['effective_display_options'] = Display::DEFAULTS;
                $shown = $twig->render('invoice_approval.' . $locale . '.' . $format . '.twig', $context);
                self::assertStringContainsString($locale === 'cs' ? 'K vyúčtování' : 'Amount', $shown);
            }
        }
    }

    public function testEnglishApprovalEmailFormatsBillingAmountsWithEnglishSeparators(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'));
        foreach (['html', 'txt'] as $format) {
            $context = $this->context(Display::DEFAULTS);
            $context['locale'] = 'en';
            $context['invoice']['language'] = 'en';
            $context['work_report']['billing_snapshot'] = ['name' => 'Synthetic billing template'];
            $context['work_report']['billing_preview'] = [
                'items' => [['description' => 'Fixed fee', 'total_amount' => 12345.67]],
            ];
            $email = $twig->render('invoice_approval.en.' . $format . '.twig', $context);
            self::assertStringContainsString('12,345.67 CZK', $email, $format);
            self::assertStringNotContainsString('12 345,67 CZK', $email, $format);
        }
    }

    /** BEZ OPRAVY PADÁ: skrytá částka práce skryla i součet výkazu, který obsahuje jen materiál. */
    public function testApprovalEmailKeepsMaterialTotalsWhenWorkAmountsAreHidden(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'));
        foreach (['cs', 'en'] as $locale) {
            $totalLabel = $locale === 'cs' ? 'K vyúčtování' : 'Amount';
            $separator = $locale === 'cs' ? ',' : '.';
            foreach (['html', 'txt'] as $format) {
                $template = 'invoice_approval.' . $locale . '.' . $format . '.twig';

                $materialOnly = $this->context(['amount' => false]);
                $materialOnly['work_report'] = array_replace($materialOnly['work_report'], [
                    'items' => [], 'total_hours' => 0.0, 'total_amount' => 0.0,
                    'material_title' => 'Spotřební materiál', 'material_total' => 500.0,
                ]);
                $html = $twig->render($template, $materialOnly);
                self::assertStringContainsString('Spotřební materiál', $html, $template);
                self::assertStringContainsString($totalLabel, $html, $template);
                self::assertStringContainsString('500' . $separator . '00', $html, $template);

                $mixed = $this->context(['amount' => false]);
                $mixed['work_report']['material_total'] = 300.0;
                $mixed['work_report']['material_title'] = 'Spotřební materiál';
                $html = $twig->render($template, $mixed);
                self::assertStringContainsString('300' . $separator . '00', $html, $template);
                self::assertStringNotContainsString('2468', $html, $template);
                self::assertStringNotContainsString('2' . ($locale === 'cs' ? ' ' : ',') . '768', $html, $template);
            }
        }
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice'));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));
        return $twig;
    }

    private function report(array $options): array
    {
        return [
            'title' => 'Testovací práce', 'total_hours' => 2.0, 'total_amount' => 2468.0,
            'items' => [['description' => 'Analýza požadavků', 'work_date' => '2096-06-01', 'hours' => 2.0,
                'duration_minutes' => 120, 'rate' => 1234.0, 'total_amount' => 2468.0]],
            'materials' => [], 'material_total' => 0,
            'effective_display_options' => Display::resolve(null, $options),
        ];
    }

    private function context(array $options): array
    {
        return [
            'invoice' => ['id' => 1, 'currency' => 'CZK', 'varsymbol' => 'TEST0001', 'items' => [],
                'vat_recap' => [], 'vat_breakdown' => [], 'invoice_type' => 'invoice', 'language' => 'cs'],
            'supplier' => ['company_name' => 'Testovací dodavatel', 'is_vat_payer' => false],
            'work_report' => $this->report($options), 'locale' => 'cs', 'date_format' => 'j. n. Y',
            'decimal_sep' => ',', 'thousand_sep' => '',
        ];
    }
}
