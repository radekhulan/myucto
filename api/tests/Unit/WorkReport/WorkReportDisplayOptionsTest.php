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
