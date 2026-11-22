<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\SalesQuote;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use MyInvoice\Service\SalesQuote\QuoteFileStorage;
use MyInvoice\Service\SalesQuote\QuoteMarkdown;
use MyInvoice\Service\SalesQuote\QuotePdfRenderer;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\PdfParser\StreamReader;
use Slim\Psr7\UploadedFile;
use Smalot\PdfParser\Parser;
use Symfony\Component\Filesystem\Filesystem;

final class QuotePdfAndFilesTest extends TestCase
{
    private string $root;
    private string|false $oldDataDir;
    private QuotePdfRenderer $pdf;
    private QuoteFileStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myucto-quote-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->oldDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
        $this->pdf = new QuotePdfRenderer(new QuoteMarkdown());
        $this->storage = new QuoteFileStorage($this->pdf);
    }

    protected function tearDown(): void
    {
        putenv($this->oldDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->oldDataDir);
        (new Filesystem())->remove($this->root);
    }

    public function testAppendsPdfInOrderWithOriginalPageDimensions(): void
    {
        $landscape = $this->appendix('landscape.pdf', 'Landscape appendix', [100, 190], 'L');
        $portrait = $this->appendix('portrait.pdf', 'Portrait appendix', [115, 169], 'P');
        $hidden = $this->appendix('internal.pdf', 'Internal only', [120, 200], 'P');
        $bytes = $this->pdf->render($this->quote(), ['id' => 1, 'company_name' => 'Ukázkový dodavatel'], [
            ['id' => 1, 'name' => 'landscape.pdf', 'mime_type' => 'application/pdf', 'mode' => 'append', 'path' => $landscape],
            ['id' => 2, 'name' => 'internal.pdf', 'mime_type' => 'application/pdf', 'mode' => 'internal', 'path' => $hidden],
            ['id' => 3, 'name' => 'portrait.pdf', 'mime_type' => 'application/pdf', 'mode' => 'append', 'path' => $portrait],
        ]);

        self::assertStringStartsWith('%PDF-', $bytes);
        $inspection = $this->inspectionPdf();
        self::assertSame(3, $inspection->setSourceFile(StreamReader::createByString($bytes)));
        $landscapeSize = $inspection->getTemplateSize($inspection->importPage(2));
        self::assertEqualsWithDelta(190, $landscapeSize['width'], 0.1);
        self::assertEqualsWithDelta(100, $landscapeSize['height'], 0.1);
        $portraitSize = $inspection->getTemplateSize($inspection->importPage(3));
        self::assertEqualsWithDelta(115, $portraitSize['width'], 0.1);
        self::assertEqualsWithDelta(169, $portraitSize['height'], 0.1);

        $pages = (new Parser())->parseContent($bytes)->getPages();
        self::assertStringContainsString('Montáž rozvaděče', $pages[0]->getText());
        self::assertStringContainsString('Landscape appendix', $pages[1]->getText());
        self::assertStringContainsString('Portrait appendix', $pages[2]->getText());
    }

    public function testMarkdownOnlyEmbedsAnImageResolvedForTheQuote(): void
    {
        $path = $this->makeImage();
        $converter = (new QuoteMarkdown())->converter([3 => $path]);
        $html = (string) $converter->convert(<<<'MD'
## Nadpis

**Text** a [odkaz](javascript:alert%281%29).

![Povolený](quote-file:3)
![Cizí](quote-file:4)
![Vzdálený](https://example.test/picture.png)
<img src="/etc/other.png">
MD);
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('<img src="' . $path . '"', $html);
        self::assertStringContainsString('&lt;img src=', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('https://example.test', $html);

        $uploadedImage = $this->storage->store(new UploadedFile($path, 'foto.png', 'image/png', filesize($path)), 1, 11, 'inline');
        $quote = $this->quote();
        $quote['sections'][0]['markdown'] = '![Povolený](quote-file:3)';
        $bytes = $this->pdf->render($quote, ['id' => 1, 'company_name' => 'Ukázkový dodavatel'], [
            ['id' => 3, 'mode' => 'inline', 'mime_type' => $uploadedImage['mime_type'], 'path' => $this->storage->path(1, 11, $uploadedImage['storage_key'])],
        ]);
        self::assertStringContainsString('/Subtype /Image', $bytes);
    }

    public function testUploadRequiresActualMimeAndValidAppendix(): void
    {
        $invalidPdf = $this->root . '/invalid.pdf';
        $invalidContent = "%PDF-1.7\ninvalid PDF\n";
        file_put_contents($invalidPdf, $invalidContent);
        try {
            $this->storage->store(new UploadedFile($invalidPdf, 'test.pdf', 'application/pdf', strlen($invalidContent)), 1, 11, 'append');
            self::fail('Neplatné PDF nemělo jít uložit.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nelze načíst', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $image = $this->makeImage();
        $this->storage->store(new UploadedFile($image, 'příloha.pdf', 'application/pdf', filesize($image)), 1, 11, 'append');
    }

    public function testArchiveWritesDistinctImmutableCopiesAndRejectsTraversal(): void
    {
        $path = $this->appendix('source.pdf', 'Synthetic text', [100, 190], 'L');
        $bytes = (string) file_get_contents($path);
        $key1 = $this->storage->archivePdf(1, 11, 7, $bytes);
        $key2 = $this->storage->archivePdf(1, 11, 7, $bytes);
        self::assertNotSame($key1, $key2);
        self::assertSame($bytes, file_get_contents($this->storage->path(1, 11, $key1)));
        self::assertFileDoesNotExist($this->storage->path(2, 11, $key1));
        $this->storage->delete(1, 11, $key1);
        self::assertFileExists($this->storage->path(1, 11, $key2));
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->path(1, 11, '../../source.pdf');
    }

    private function inspectionPdf(array $overrides = []): Mpdf
    {
        return new Mpdf($overrides + ['tempDir' => $this->root, 'mode' => 'c']);
    }

    private function appendix(string $name, string $label, array $pageSize, string $orientation): string
    {
        $pdf = $this->inspectionPdf(['format' => $pageSize, 'orientation' => $orientation]);
        $pdf->WriteHTML('<p>' . $label . '</p>');
        $path = $this->root . '/' . $name;
        $pdf->Output($path, Destination::FILE);
        return $path;
    }

    private function makeImage(): string
    {
        $image = imagecreatetruecolor(80, 60);
        $path = $this->root . '/sample.png';
        imagepng($image, $path);
        return $path;
    }

    private function quote(): array
    {
        $totals = ['without_vat' => 100, 'vat' => 21, 'with_vat' => 121];
        return [
            'id' => 11, 'number' => 'NAB-2026-0042', 'revision' => 2, 'title' => 'Montáž rozvaděče',
            'client_name' => 'Ukázkový odběratel', 'valid_until' => '2026-11-30',
            'language' => 'cs', 'currency' => 'CZK', 'prices_include_vat' => false,
            'sections' => [[
                'id' => 1, 'title' => 'Rozsah a cena', 'kind' => 'required', 'markdown' => '## Popis zakázky',
                'items' => [[
                    'description' => 'Montáž', 'quantity' => 2, 'unit' => 'h', 'unit_price' => 50,
                    'discount_percent' => 0, 'vat_rate' => 21, 'totals' => $totals,
                ]],
                'totals' => $totals,
            ]],
            'totals' => $totals,
        ];
    }
}
