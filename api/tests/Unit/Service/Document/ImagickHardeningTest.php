<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Service\Document\ThumbnailGenerator;
use MyInvoice\Service\Mail\SupplierLogoConverter;
use MyInvoice\Support\Imaging\ImagickGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Imagick dostává jen obsah ověřený podle magic bytes s vynuceným coderem, rastr nad
 * strop pixelů se nedekóduje a SVG logo se sanitizuje dřív, než ho uvidí rasterizér.
 */
final class ImagickHardeningTest extends TestCase
{
    private const SUPPLIER = 987_654_321;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/imgh_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        (new SupplierLogoConverter(new NullLogger()))->delete(self::SUPPLIER);
    }

    public function testRasterCoderComesFromMagicBytes(): void
    {
        $cases = [
            'jpeg' => "\xFF\xD8\xFF\xE0rest",
            'png'  => "\x89PNG\r\n\x1A\nrest",
            'gif'  => 'GIF89a....',
            'webp' => 'RIFF1234WEBPVP8 ',
            'heic' => "\x00\x00\x00\x18ftypheic....",
            'tiff' => "II*\x00rest",
        ];
        foreach ($cases as $coder => $bytes) {
            self::assertSame($coder, ImagickGuard::rasterCoder($this->file($coder . '.bin', $bytes)));
        }
        $mvg = "push graphic-context\nviewbox 0 0 10 10\nimage over 0,0 0,0 'text:/etc/passwd'\npop graphic-context\n";
        self::assertNull(ImagickGuard::rasterCoder($this->file('fake.png', $mvg)));
        self::assertNull(ImagickGuard::rasterCoder($this->file('fake.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')));
        self::assertFalse(ImagickGuard::isPdf($this->file('fake.pdf', 'not a pdf')));
    }

    public function testPixelLimit(): void
    {
        self::assertTrue(ImagickGuard::withinPixelLimit(6000, 6000));
        // 50Mpx fotka z mobilu (8160 × 6120) projde, 10000 × 9000 už ne.
        self::assertTrue(ImagickGuard::withinPixelLimit(8160, 6120));
        self::assertFalse(ImagickGuard::withinPixelLimit(10000, 9000));
        self::assertFalse(ImagickGuard::withinPixelLimit(30000, 10));
        self::assertFalse(ImagickGuard::withinPixelLimit(0, 10));
    }

    public function testThumbnailOfNormalPngAndRejectionOfPixelBombAndDisguisedMvg(): void
    {
        $this->requireImagick();
        $png = new \Imagick();
        $png->newImage(1200, 800, 'steelblue');
        $png->writeImage('png:' . $this->dir . '/ok.png');

        self::assertTrue($this->viaImagick($this->dir . '/ok.png', 'image', $this->dir . '/ok.jpg'));
        self::assertSame([480, 320], array_slice(getimagesize($this->dir . '/ok.jpg') ?: [], 0, 2));

        // PNG hlavička deklaruje 15000×15000 (225 Mpx), data nejsou potřeba: ping ji odmítne.
        $bomb = $this->file('bomb.png', $this->pngHeader(15000, 15000));
        self::assertFalse($this->viaImagick($bomb, 'image', $this->dir . '/bomb.jpg'));
        self::assertFileDoesNotExist($this->dir . '/bomb.jpg');

        $mvg = $this->file('fake.png', "push graphic-context\nviewbox 0 0 10 10\npop graphic-context\n");
        self::assertFalse($this->viaImagick($mvg, 'image', $this->dir . '/fake.jpg'));
        self::assertFalse($this->viaImagick($mvg, 'pdf', $this->dir . '/fake.jpg'));
    }

    public function testResourceLimitsAreRestored(): void
    {
        $this->requireImagick();
        $before = \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY);
        $inside = ImagickGuard::withLimits(static fn (): float|int => \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
        self::assertLessThanOrEqual(256 * 1024 * 1024, $inside);
        self::assertEquals($before, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_MEMORY));
    }

    public function testSvgSanitizerRemovesExternalReferences(): void
    {
        $svg = '<?xml version="1.0"?><?xml-stylesheet type="text/css" href="file:///etc/passwd"?>'
            . '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:a="http://www.w3.org/1999/xlink" xmlns:xi="http://www.w3.org/2001/XInclude">'
            . '<style>@import url(file:///etc/passwd); .c{fill:url(#g)} .d{background:url("file:///etc/shadow")}</style>'
            . '<xi:include href="/etc/passwd" parse="text"/>'
            . '<xi:include parse="text" href="/etc/hosts"><xi:fallback/></xi:include>'
            . '<image a:href="file:///etc/passwd" width="1" height="1"/>'
            . '<image href="data:image/png;base64,AAAA" width="1" height="1"/>'
            . '<rect class="c" width="10" height="10" fill="url(#g)"/></svg>';
        $clean = $this->sanitize($svg);

        foreach (['/etc/passwd', '/etc/shadow', '/etc/hosts', 'xml-stylesheet', '@import', 'ENTITY', 'include'] as $needle) {
            self::assertStringNotContainsString($needle, $clean);
        }
        self::assertStringContainsString('url(#g)', $clean);
        self::assertStringContainsString('data:image/png;base64,AAAA', $clean);
    }

    public function testLogoUploadPngAndSvg(): void
    {
        $this->requireImagick();
        $converter = new SupplierLogoConverter(new NullLogger());

        $png = new \Imagick();
        $png->newImage(400, 100, 'red');
        $png->writeImage('png:' . $this->dir . '/logo.png');
        $res = $converter->process($this->dir . '/logo.png', self::SUPPLIER);
        self::assertSame([400, 100], [$res['width'], $res['height']]);

        $jpg = new \Imagick();
        $jpg->newImage(1600, 400, 'green');
        $jpg->writeImage('jpeg:' . $this->dir . '/logo.jpg');
        $res = $converter->process($this->dir . '/logo.jpg', self::SUPPLIER);
        self::assertSame([800, 200], [$res['width'], $res['height']]);

        if (\Imagick::queryFormats('SVG') === []) {
            return;
        }
        $svg = $this->file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="50" viewBox="0 0 200 50">'
            . '<rect width="200" height="50" fill="#123456"/></svg>');
        $res = $converter->process($svg, self::SUPPLIER);
        // Výchozí DPI rasterizéru se liší (72 / 96), poměr stran 4:1 a strop 800×240 platí vždy.
        self::assertLessThanOrEqual(240, $res['height']);
        self::assertGreaterThanOrEqual(170, $res['height']);
        self::assertSame(4.0, round($res['width'] / $res['height'], 1));

        // Obří deklarované rozměry se renderují v menším měřítku, ne 10 Gpx.
        $huge = $this->file('huge.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="100000">'
            . '<circle cx="50000" cy="50000" r="40000" fill="red"/></svg>');
        $started = microtime(true);
        $res = $converter->process($huge, self::SUPPLIER);
        self::assertSame(240, $res['height']);
        self::assertSame($res['width'], $res['height']);
        self::assertLessThan(20.0, microtime(true) - $started);
    }

    private function viaImagick(string $src, string $docType, string $out): bool
    {
        $m = new \ReflectionMethod(ThumbnailGenerator::class, 'viaImagick');
        return (bool) $m->invoke(new ThumbnailGenerator(), $src, $docType, $out);
    }

    private function sanitize(string $svg): string
    {
        $m = new \ReflectionMethod(SupplierLogoConverter::class, 'sanitizeSvg');
        return (string) $m->invoke(new SupplierLogoConverter(new NullLogger()), $svg);
    }

    private function pngHeader(int $w, int $h): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1A\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', (string) gzcompress("\x00\x00\x00\x00")) . $chunk('IEND', '');
    }

    private function file(string $name, string $bytes): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    private function requireImagick(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('Imagick není k dispozici.');
        }
    }
}
