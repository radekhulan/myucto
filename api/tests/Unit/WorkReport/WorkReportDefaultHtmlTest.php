<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use MyInvoice\Service\Pdf\TimeBillingPdfPresenter;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Výkaz práce bez nastavených voleb zobrazení musí v PDF (příloha faktury i samostatný
 * výkaz) a v e-mailu ke schválení vypadat bajtově stejně jako před zavedením voleb.
 *
 * Fixtury v tests/Fixtures/WorkReport/default-output vznikly ze šablon před zavedením
 * voleb (renderOutputs() nad tehdejším api/templates). Porovnává se jak výkaz bez klíče
 * effective_display_options (data uložená před migrací), tak výkaz s výchozími volbami.
 */
final class WorkReportDefaultHtmlTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/WorkReport/default-output';

    /** @return iterable<string, array{string}> */
    public static function outputKeys(): iterable
    {
        foreach (array_keys(self::renderOutputs(dirname(__DIR__, 3) . '/templates', null)) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('outputKeys')]
    public function testDefaultOutputMatchesOutputBeforeDisplayOptions(string $key): void
    {
        $expected = (string) file_get_contents(self::FIXTURES . '/' . $key);
        $templates = dirname(__DIR__, 3) . '/templates';
        self::assertSame($expected, self::renderOutputs($templates, null)[$key], 'Výkaz bez voleb: ' . $key);
        self::assertSame($expected, self::renderOutputs($templates, WorkReportDisplayOptions::DEFAULTS)[$key], 'Výchozí volby: ' . $key);
    }

    /**
     * @param array<string,bool>|null $options null = výkaz bez klíče effective_display_options
     * @return array<string,string> název fixtury => výstup
     */
    public static function renderOutputs(string $templatesDir, ?array $options): array
    {
        $outputs = [];
        foreach (self::reports() as $name => $report) {
            if ($options !== null) {
                $report['effective_display_options'] = $options;
            }
            $report = TimeBillingPdfPresenter::workReport($report);
            foreach (['cs', 'en'] as $locale) {
                $context = self::context($report, $locale);
                $pdf = self::pdfTwig($templatesDir . '/invoice', $locale);
                $invoice = $pdf->render('invoice.twig', $context);
                $start = strpos($invoice, '<a name="work-report"></a>');
                if ($start !== false) {
                    $end = strpos($invoice, '</table>', $start);
                    $outputs["{$name}.{$locale}.invoice.html"] = substr($invoice, $start, $end + 8 - $start);
                }
                $outputs["{$name}.{$locale}.work_report.html"] = $pdf->render('work_report.twig', $context);
                $mail = new Environment(new FilesystemLoader($templatesDir . '/email'));
                foreach (['html', 'txt'] as $format) {
                    $outputs["{$name}.{$locale}.approval.{$format}"] = $mail->render("invoice_approval.{$locale}.{$format}.twig", $context);
                }
            }
        }
        return $outputs;
    }

    /** @return array<string, array<string,mixed>> */
    private static function reports(): array
    {
        $base = ['title' => 'Syntetický výkaz', 'materials' => [], 'material_total' => 0.0, 'material_title' => null];
        return [
            'dates' => $base + [
                'total_hours' => 3.5, 'total_amount' => 3800.75,
                'items' => [
                    ['description' => "Analýza\nřádek 2", 'work_date' => '2096-03-02', 'hours' => 1.5, 'duration_minutes' => null, 'rate' => 1200.5, 'total_amount' => 1800.75],
                    ['description' => 'Vývoj', 'work_date' => null, 'hours' => 2.0, 'duration_minutes' => null, 'rate' => 1000.0, 'total_amount' => 2000.0],
                ],
            ],
            'nodates' => $base + [
                'total_hours' => 2.0, 'total_amount' => 3000.0,
                'items' => [['description' => 'Konzultace', 'work_date' => null, 'hours' => 2.0, 'duration_minutes' => null, 'rate' => 1500.0, 'total_amount' => 3000.0]],
            ],
            'minutes' => $base + [
                'total_hours' => 2.08, 'total_amount' => 2500.0,
                'items' => [
                    ['description' => 'Minutová práce', 'work_date' => '2096-03-05', 'hours' => 1.58, 'duration_minutes' => 95, 'rate' => 1200.0, 'total_amount' => 1900.0],
                    ['description' => 'Další', 'work_date' => '2096-03-06', 'hours' => 0.5, 'duration_minutes' => 30, 'rate' => 1200.0, 'total_amount' => 600.0],
                ],
            ],
            'material' => [
                'title' => 'Materiál', 'total_hours' => 0.0, 'total_amount' => 0.0, 'items' => [],
                'material_title' => 'Spotřební materiál', 'material_total' => 149.85,
                'materials' => [['description' => 'Šroub', 'quantity' => 1.5, 'unit' => 'kg', 'unit_price' => 99.9, 'total_amount' => 149.85]],
            ],
            'mixed' => [
                'title' => 'Práce a materiál', 'total_hours' => 1.0, 'total_amount' => 1000.0,
                'items' => [['description' => 'Montáž', 'work_date' => '2096-03-07', 'hours' => 1.0, 'duration_minutes' => null, 'rate' => 1000.0, 'total_amount' => 1000.0]],
                'material_title' => null, 'material_total' => 300.0,
                'materials' => [['description' => 'Kabel', 'quantity' => 2.0, 'unit' => 'ks', 'unit_price' => 150.0, 'total_amount' => 300.0]],
            ],
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function context(array $report, string $locale): array
    {
        return [
            'invoice' => ['id' => 1, 'currency' => 'CZK', 'varsymbol' => '20960001', 'varsymbol_or_id' => '20960001',
                'items' => [], 'vat_recap' => [], 'vat_breakdown' => [], 'invoice_type' => 'invoice', 'language' => $locale,
                'issue_date' => '2096-03-10', 'tax_date' => '2096-03-10', 'due_date' => '2096-03-24',
                'project_name' => 'Syntetická zakázka', 'client_company_name' => 'Testovací odběratel s.r.o.'],
            'supplier' => ['company_name' => 'Testovací dodavatel', 'is_vat_payer' => false],
            'work_report' => $report, 'locale' => $locale,
            'date_format' => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep' => $locale === 'en' ? '.' : ',',
            'thousand_sep' => $locale === 'en' ? ',' : "\u{00A0}",
            'css' => '', 'logo_path' => null, 'logo_show_name' => false,
            'approval_url' => 'https://example.test/approval/token', 'is_test' => false, 'is_reminder' => false,
            'accent' => '#5B3FD9', 'accent_soft' => '#F1EDFC',
        ];
    }

    private static function pdfTwig(string $dir, string $locale): Environment
    {
        $twig = new Environment(new FilesystemLoader($dir), ['autoescape' => 'html', 'strict_variables' => false]);
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $locale === 'en' ? $en : $cs));
        return $twig;
    }
}
