<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImageToPdfConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/** Převod musí zachovat geometrii vykresleného obrázku, ne jen rozměry PDF stránky. */
final class ImageToPdfConverterTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/image-to-pdf-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
        else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function images(): iterable
    {
        foreach (['png', 'jpeg'] as $format) {
            yield 'dlouhý portrét ' . $format => [1206, 4499, $format];
            yield 'panorama ' . $format => [4499, 1206, $format];
            yield 'čtverec ' . $format => [2000, 2000, $format];
            yield 'malý obrázek ' . $format => [320, 200, $format];
        }
    }

    #[DataProvider('images')]
    public function testImageFillsOnePageWithoutDistortion(int $width, int $height, string $format): void
    {
        $pdf = (new ImageToPdfConverter())->convert(self::image($width, $height, $format), 'image/' . $format);
        $this->assertGeometry($pdf, $width, $height);
    }

    public function testJpegOrientationIsAppliedBeforePageSizing(): void
    {
        // Syntetický EXIF Orientation=6 (90° po směru hodin); TIFF má jediný IFD záznam.
        $tiff = 'II' . pack('vVv', 42, 8, 1) . pack('vvVvvV', 0x0112, 3, 1, 6, 0, 0);
        $exif = "Exif\0\0" . $tiff;
        $jpeg = self::image(320, 200, 'jpeg');
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);

        $pdf = (new ImageToPdfConverter())->convert($jpeg, 'image/jpeg');
        $this->assertGeometry($pdf, 200, 320);
    }

    private function assertGeometry(string $pdf, int $width, int $height): void
    {
        self::assertStringStartsWith('%PDF-', $pdf);
        $document = (new Parser())->parseContent($pdf);
        $pages = $document->getPages();
        self::assertCount(1, $pages, 'Obrázek musí zůstat na jedné stránce.');
        $box = $pages[0]->getDetails()['MediaBox'];
        $pageWidth = (float) $box[2] - (float) $box[0];
        $pageHeight = (float) $box[3] - (float) $box[1];

        $images = $document->getObjectsByType('XObject', 'Image');
        self::assertCount(1, $images);
        $image = array_values($images)[0]->getDetails();
        $scale = min(1.0, 1600 / max($width, $height));
        $expectedWidth = (int) round($width * $scale);
        $expectedHeight = (int) round($height * $scale);
        self::assertSame($expectedWidth, (int) $image['Width']);
        self::assertSame($expectedHeight, (int) $image['Height']);

        // Čteme skutečnou transformaci vykreslení XObjectu z rozbaleného streamu.
        // Kontrola samotného MediaBoxu dřívější čtvercové zmáčknutí nezachytila.
        $number = '(-?\d+(?:\.\d+)?)';
        $pattern = '/q\s+' . $number . '\s+0\s+0\s+' . $number . '\s+' . $number . '\s+' . $number
            . '\s+cm\s+\/\w+\s+Do\s+Q/';
        $content = $pages[0]->get('Contents')->getContent();
        self::assertIsString($content);
        self::assertSame(1, preg_match_all($pattern, $content, $matches, PREG_SET_ORDER));
        [, $drawWidth, $drawHeight, $left, $bottom] = $matches[0];
        $drawWidth = (float) $drawWidth;
        $drawHeight = (float) $drawHeight;
        self::assertEqualsWithDelta(0.0, (float) $left, 0.03);
        self::assertEqualsWithDelta(0.0, (float) $bottom, 0.03);
        self::assertEqualsWithDelta($pageWidth, $drawWidth, 0.03);
        self::assertEqualsWithDelta($pageHeight, $drawHeight, 0.03);
        $ratio = $expectedWidth / $expectedHeight;
        self::assertEqualsWithDelta($ratio, $drawWidth / $drawHeight, $ratio * 0.001);
        self::assertEqualsWithDelta(297 * 72 / 25.4, max($pageWidth, $pageHeight), 0.03);
    }

    private static function image(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $size = max(20, (int) (min($width, $height) / 5));
        foreach ([[0, 0, 220, 30, 30], [$width - $size, 0, 30, 160, 30],
            [0, $height - $size, 30, 30, 220], [$width - $size, $height - $size, 220, 160, 30]] as $corner) {
            [$x, $y, $r, $g, $b] = $corner;
            imagefilledrectangle($image, $x, $y, $x + $size - 1, $y + $size - 1, imagecolorallocate($image, $r, $g, $b));
        }
        imagestring($image, 5, 5, $size + 5, 'TEST ' . $width . 'x' . $height, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        if ($format === 'png') imagepng($image);
        else imagejpeg($image, null, 95);
        return (string) ob_get_clean();
    }
}
