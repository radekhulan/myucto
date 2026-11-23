<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\SupplierLogoConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SupplierLogoSvgRasterizationTest extends TestCase
{
    #[DataProvider('boundedRenderCases')]
    public function testProbeNeverStartsExternalDelegateAndRenderIsBounded(string $renderer, string $native, array $expected, ?array $renderSize = null): void
    {
        $result = $this->probe($renderer, $native);
        self::assertTrue($result['converted'], json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([
            ['ping', $renderer === 'rsvg-only' ? 'rsvg' : 'msvg'],
            ['read', $renderer === 'rsvg-only' ? 'rsvg' : $renderer, $renderSize ?? $expected],
            ['write', $expected],
        ], $result['calls']);
    }

    #[DataProvider('unrenderableSizes')]
    public function testMissingOrUnsafeSizeIsRejectedBeforeRasterization(string $native): void
    {
        $result = $this->probe('rsvg', $native);
        self::assertFalse($result['converted']);
        self::assertSame([['ping', 'msvg']], $result['calls']);
    }

    public static function unrenderableSizes(): iterable
    {
        yield 'zero width' => ['0x50'];
        yield 'negative height' => ['200x-1'];
        yield 'unsafe intermediate width' => ['1000000000x1'];
        yield 'unsafe intermediate height' => ['1x1000000000'];
    }

    public function testUnavailableCoderRegistryAllowsCliFallback(): void
    {
        foreach (['none', 'query-error'] as $renderer) {
            $result = $this->probe($renderer, '200x50');
            self::assertFalse($result['converted']);
            self::assertSame([], $result['calls']);
        }
    }

    private function probe(string $renderer, string $native): array
    {
        static $builtinDom = null;
        if ($builtinDom === null) {
            // DOM může být zkompilované přímo do PHP, zejména na Windows.
            $check = proc_open([PHP_BINARY, '-n', '-r', 'exit(class_exists("DOMDocument") ? 0 : 1);'], [], $pipes);
            self::assertIsResource($check);
            $builtinDom = proc_close($check) === 0;
        }
        $command = [PHP_BINARY, '-n'];
        if (!$builtinDom) array_push($command, '-d', 'extension=dom');
        $output = tempnam(sys_get_temp_dir(), 'svg-probe-');
        self::assertNotFalse($output);
        try {
            $process = proc_open([...$command, dirname(__DIR__, 3) . '/Fixtures/Imaging/logo-imagick-probe.php', $output, $renderer, $native],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            return json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($output);
        }
    }

    public static function boundedRenderCases(): iterable
    {
        yield 'RSVG huge' => ['rsvg', '100000x100000', [240, 240]];
        yield 'MSVG fallback huge' => ['msvg', '100000x100000', [240, 240]];
        yield 'small retina logo' => ['rsvg', '200x50', [800, 200]];
        yield 'wide' => ['msvg', '100000x10', [800, 1], [10000, 1]];
        yield 'tall' => ['msvg', '10x100000', [1, 240], [1, 10000]];
        yield 'RSVG subpixel edge' => ['rsvg', '100000x10', [800, 1], [10000, 1]];
        yield 'build without MSVG' => ['rsvg-only', '100000x100000', [240, 240]];
    }

    // ImageMagick 6 počítá TIME od inicializace procesu, ne od začátku operace.
    // Geometrii ověřujeme s čerstvým časovým rozpočtem, i po dlouhém běhu celé sady.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('svgGeometryCases')]
    public function testActualSvgRenderPreservesGeometry(string $attributes, string $content, array $expected): void
    {
        if (!extension_loaded('imagick')) self::markTestSkipped('Imagick není k dispozici.');
        $source = tempnam(sys_get_temp_dir(), 'svg-input-');
        $output = tempnam(sys_get_temp_dir(), 'svg-output-');
        self::assertNotFalse($source);
        self::assertNotFalse($output);
        try {
            file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg" ' . $attributes . '>' . $content . '</svg>');
            $logger = new class extends \Psr\Log\AbstractLogger {
                public array $messages = [];
                public function log($level, \Stringable|string $message, array $context = []): void { $this->messages[] = (string) $message; }
            };
            $converter = new SupplierLogoConverter($logger);
            $coders = [null];
            foreach (['MSVG', 'RSVG'] as $format) {
                if (\Imagick::queryFormats($format) !== []) $coders[] = strtolower($format) . ':';
            }
            foreach ($coders as $coder) {
                @unlink($output);
                $logger->messages = [];
                $converted = $coder === null
                    ? (new \ReflectionMethod($converter, 'convertSvgViaImagick'))->invoke($converter, $source, $output)
                    : (new \ReflectionMethod($converter, 'rasterizeSvgViaImagick'))->invoke($converter, $source, $output, $coder, $coder);
                $reason = ($coder ?? 'selected') . ': ' . implode(' | ', $logger->messages);
                // Docker záměrně zakazuje MVG, které MSVG používá interně.
                // Jen doložené odmítnutí policy je očekávané; ostatní chyby padají.
                if (!$converted && $coder === 'msvg:' && preg_match('/security policy[^\n]*[`\'"]MVG[\'"]/i', $reason)) {
                    self::assertFileDoesNotExist($output, $reason);
                    continue;
                }
                self::assertTrue($converted, $reason);
                $size = getimagesize($output);
                self::assertNotFalse($size);
                self::assertSame($expected, array_slice($size, 0, 2), $reason);
                $pixels = imagecreatefrompng($output);
                self::assertNotFalse($pixels);
                $center = imagecolorsforindex($pixels, imagecolorat($pixels, intdiv($expected[0], 2), intdiv($expected[1], 2)));
                self::assertSame(['red' => 255, 'green' => 0, 'blue' => 0, 'alpha' => 0], $center,
                    $reason . ' Změna rozměrů musí zachovat obsah loga, ne jen vytvořit prázdné PNG.');
            }
        } finally {
            @unlink($source);
            @unlink($output);
        }
    }

    public static function svgGeometryCases(): iterable
    {
        yield 'missing dimensions and viewBox' => ['', '<rect width="300" height="150" fill="red"/>', [480, 240]];
        yield 'percentage viewport without viewBox' => ['width="100%" height="100%"', '<rect width="300" height="150" fill="red"/>', [480, 240]];
        yield 'missing height without viewBox' => ['width="200"', '<rect width="200" height="150" fill="red"/>', [320, 240]];
        yield 'viewBox only' => ['viewBox="0 0 200 50"', '<rect width="200" height="50" fill="red"/>', [800, 200]];
        yield 'percentage viewport' => ['width="100%" height="100%" viewBox="0 0 200 50"', '<rect width="200" height="50" fill="red"/>', [800, 200]];
        yield 'physical units' => ['width="20mm" height="5mm" viewBox="0 0 200 50"', '<rect width="200" height="50" fill="red"/>', [800, 200]];
        yield 'offset viewBox' => ['viewBox="10 20 200 50"', '<rect x="10" y="20" width="200" height="50" fill="red"/>', [800, 200]];
        yield 'wide viewport' => ['width="100000" height="10" viewBox="0 0 100000 10"', '<rect width="100000" height="10" fill="red"/>', [800, 1]];
        yield 'tall viewport' => ['width="10" height="100000" viewBox="0 0 10 100000"', '<rect width="10" height="100000" fill="red"/>', [1, 240]];
    }

    #[DataProvider('invalidSvgCases')]
    public function testInvalidSvgDoesNotProduceImage(string $svg): void
    {
        if (!extension_loaded('imagick')) self::markTestSkipped('Imagick není k dispozici.');
        $source = tempnam(sys_get_temp_dir(), 'svg-invalid-');
        $output = $source . '.png';
        try {
            file_put_contents($source, $svg);
            $converter = new SupplierLogoConverter(new NullLogger());
            self::assertFalse((new \ReflectionMethod($converter, 'convertSvgViaImagick'))->invoke($converter, $source, $output));
            self::assertFileDoesNotExist($output);
        } finally {
            @unlink($source);
            @unlink($output);
        }
    }

    public static function invalidSvgCases(): iterable
    {
        yield 'empty' => [''];
        yield 'not XML' => ['not an SVG'];
        yield 'zero viewport' => ['<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0"/>'];
        yield 'invalid viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 NaN 20"/>'];
    }
}
