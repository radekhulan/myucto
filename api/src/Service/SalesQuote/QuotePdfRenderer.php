<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use MyInvoice\Service\Pdf\PdfBranding;
use MyInvoice\Service\Pdf\TwigCache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class QuotePdfRenderer
{
    private const MAX_APPENDIX_PAGES = 100;
    private const MAX_TOTAL_APPENDIX_PAGES = 200;
    private const LABELS = [
        'cs' => [
            'quote' => 'Cenová nabídka', 'revision' => 'Verze', 'issue_date' => 'Datum nabídky', 'valid_until' => 'Platí do',
            'supplier' => 'Dodavatel', 'client' => 'Odběratel', 'company_id' => 'IČO', 'vat_id' => 'DIČ',
            'optional' => 'Volitelné', 'accepted' => 'Přijato', 'description' => 'Položka',
            'quantity' => 'Množství', 'unit' => 'MJ', 'unit_price' => 'Cena za MJ', 'discount' => 'Sleva',
            'vat' => 'DPH', 'without_vat' => 'Bez DPH', 'with_vat' => 'Včetně DPH',
            'section_total' => 'Mezisoučet', 'total' => 'Základní cena nabídky',
            'accepted_total' => 'Přijatá cena nabídky', 'unit_net' => 'Ceny za jednotku jsou bez DPH.',
            'unit_gross' => 'Ceny za jednotku jsou včetně DPH.',
        ],
        'en' => [
            'quote' => 'Quote', 'revision' => 'Revision', 'issue_date' => 'Quote date', 'valid_until' => 'Valid until',
            'supplier' => 'Supplier', 'client' => 'Customer', 'company_id' => 'Registration no.', 'vat_id' => 'VAT ID',
            'optional' => 'Optional', 'accepted' => 'Accepted', 'description' => 'Item',
            'quantity' => 'Qty', 'unit' => 'Unit', 'unit_price' => 'Unit price', 'discount' => 'Discount',
            'vat' => 'VAT', 'without_vat' => 'Excl. VAT', 'with_vat' => 'Incl. VAT',
            'section_total' => 'Subtotal', 'total' => 'Base quote total',
            'accepted_total' => 'Accepted quote total', 'unit_net' => 'Unit prices exclude VAT.',
            'unit_gross' => 'Unit prices include VAT.',
        ],
    ];

    private ?Environment $twig = null;

    public function __construct(private readonly QuoteMarkdown $markdown) {}

    public function render(array $quote, array $supplier, array $files): string
    {
        $images = [];
        foreach ($files as $file) {
            if ($file['mode'] === 'inline' && in_array($file['mime_type'], ['image/jpeg', 'image/png', 'image/gif'], true)) {
                $images[(int) $file['id']] = $file['path'];
            }
        }
        $converter = $this->markdown->converter($images);
        foreach ($quote['sections'] as &$section) {
            $section['html'] = (string) $converter->convert((string) ($section['markdown'] ?? ''));
        }
        unset($section);

        $locale = ($quote['language'] ?? '') === 'en' ? 'en' : 'cs';
        $labels = self::LABELS[$locale];
        $date = $quote['valid_until'] ?? null;
        $dateFormat = $locale === 'en' ? 'Y-m-d' : 'j. n. Y';
        $client = $quote['client_snapshot'] ?? [];
        $body = $this->twig()->render('quote.twig', [
            'quote' => $quote,
            'supplier' => $supplier,
            'client' => $client,
            'labels' => $labels,
            'valid_until' => $date ? (new \DateTimeImmutable((string) $date))->format($dateFormat) : '',
            'issue_date' => empty($quote['issue_date']) ? '' : (new \DateTimeImmutable($quote['issue_date']))->format($dateFormat),
            'decimal' => $locale === 'en' ? '.' : ',',
            'logo' => PdfBranding::logoPath($supplier),
        ]);
        $pdf = $this->mpdf();
        try {
            $pdf->SetTitle($labels['quote'] . ' ' . (string) ($quote['number'] ?? ''));
            $pdf->SetCreator('MyÚčto.cz');
            $pdf->WriteHTML($this->twig()->render('quote.css.twig') . PdfBranding::accentCss($supplier), HTMLParserMode::HEADER_CSS);
            $pdf->WriteHTML($body, HTMLParserMode::HTML_BODY);

            $appended = 0;
            foreach ($files as $file) {
                if ($file['mode'] !== 'append') {
                    continue;
                }
                $count = $this->appendixPageCount($pdf, $file['path'], (string) $file['name']);
                $appended += $count;
                if ($appended > self::MAX_TOTAL_APPENDIX_PAGES) {
                    throw new \InvalidArgumentException('Přílohy nabídky mají dohromady více než 200 stran.');
                }
                try {
                    for ($page = 1; $page <= $count; $page++) {
                        $template = $pdf->importPage($page);
                        $size = $pdf->getTemplateSize($template);
                        $pdf->AddPageByArray([
                            'orientation' => $size['orientation'],
                            'newformat' => [min($size['width'], $size['height']), max($size['width'], $size['height'])],
                            'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0,
                        ]);
                        $pdf->useTemplate($template, 0, 0, null, null, true);
                    }
                } catch (\Exception $error) {
                    throw new \InvalidArgumentException('PDF přílohu nelze vložit do nabídky: ' . $file['name'], 0, $error);
                }
            }

            return $pdf->Output('', Destination::STRING_RETURN);
        } finally {
            $pdf->cleanup();
        }
    }

    public function validateAppendix(string $path): void
    {
        $pdf = $this->mpdf();
        try {
            $pages = $this->appendixPageCount($pdf, $path);
            try {
                for ($page = 1; $page <= $pages; $page++) {
                    $pdf->importPage($page);
                }
            } catch (\Exception $error) {
                throw new \InvalidArgumentException('PDF přílohu nelze vložit do nabídky.', 0, $error);
            }
        } finally {
            $pdf->cleanup();
        }
    }

    private function appendixPageCount(Mpdf $pdf, string $path, ?string $name = null): int
    {
        try {
            $count = $pdf->setSourceFile($path);
        } catch (\Exception $error) {
            $message = 'PDF přílohu nelze načíst. Zkontrolujte, že je čitelná a bez hesla.';
            throw new \InvalidArgumentException($name === null ? $message : $message . ' ' . $name, 0, $error);
        }
        if ($count < 1 || $count > self::MAX_APPENDIX_PAGES) {
            throw new \InvalidArgumentException('PDF příloha musí mít 1 až 100 stran.');
        }
        return $count;
    }

    private function mpdf(): Mpdf
    {
        $temp = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($temp) && !@mkdir($temp, 0755, true) && !is_dir($temp)) {
            throw new \RuntimeException('Dočasné úložiště pro PDF není dostupné.');
        }
        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
            'tempDir' => $temp,
            'whitelistStreamWrappers' => ['file'],
            ...MpdfFontConfig::options(),
        ]);
    }

    private function twig(): Environment
    {
        return $this->twig ??= new Environment(
            new FilesystemLoader(Bootstrap::rootDir() . '/api/templates/quote'),
            ['autoescape' => 'html'] + TwigCache::options('quote'),
        );
    }
}
