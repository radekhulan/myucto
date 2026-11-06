<?php

declare(strict_types=1);

// Samostatný proces bez php.ini: skutečnou cestu převodníku ověřujeme bez spuštění
// externího SVG delegáta, který by u regresní pixelové bomby mohl viset několik minut.
final class Imagick
{
    public const RESOURCETYPE_MEMORY = 1;
    public const RESOURCETYPE_MAP = 2;
    public const RESOURCETYPE_AREA = 3;
    public const RESOURCETYPE_DISK = 4;
    public const RESOURCETYPE_TIME = 5;
    public const RESOURCETYPE_WIDTH = 6;
    public const RESOURCETYPE_HEIGHT = 7;
    public const RESOURCETYPE_LISTLENGTH = 8;
    public const FILTER_LANCZOS = 1;

    public static array $calls = [];
    private static array $limits = [];
    private int $width = 100000;
    private int $height = 100000;
    private ?array $size = null;

    public static function queryFormats(string $format): array
    {
        $available = $GLOBALS['argv'][2];
        if ($available === 'query-error') throw new RuntimeException('Coder registry unavailable.');
        if ($available === 'none') return [];
        return match ($format) {
            'RSVG' => str_starts_with($available, 'rsvg') ? ['RSVG'] : [],
            'MSVG' => $available !== 'rsvg-only' ? ['MSVG'] : [],
            default => [],
        };
    }

    public static function getResourceLimit(int $type): int { return self::$limits[$type] ?? 100000000; }
    public static function setResourceLimit(int $type, int $limit): bool { self::$limits[$type] = $limit; return true; }

    public function pingImage(string $spec): bool
    {
        self::$calls[] = ['ping', strstr($spec, ':', true)];
        if (!str_starts_with($spec, 'msvg:') && !str_starts_with($spec, 'rsvg:')) {
            throw new RuntimeException('Probe nesmí spouštět externí SVG delegát.');
        }
        [$this->width, $this->height] = array_map('intval', explode('x', $GLOBALS['argv'][3]));
        return true;
    }

    public function setSize(int $width, int $height): bool { $this->size = [$width, $height]; return true; }
    public function setResolution(float $x, float $y): bool { return true; }
    public function setBackgroundColor(mixed $color): bool { return true; }
    public function getImageWidth(): int { return $this->width; }
    public function getImageHeight(): int { return $this->height; }
    public function readImage(string $spec): bool
    {
        self::$calls[] = ['read', strstr($spec, ':', true), $this->size];
        if (!str_starts_with($spec, 'rsvg:') && !str_starts_with($spec, 'msvg:')) {
            throw new RuntimeException('Render nesmí spouštět externí SVG delegát.');
        }
        if ($this->size === null || max($this->size) > 20000 || $this->size[0] * $this->size[1] > 12000000) {
            throw new RuntimeException('Render musí mít předem omezenou velikost.');
        }
        [$this->width, $this->height] = $this->size;
        return true;
    }
    public function resizeImage(int $width, int $height, int $filter, float $blur, bool $bestFit): bool
    {
        if (!$bestFit) {
            [$this->width, $this->height] = [$width, $height];
            return true;
        }
        $scale = min($width / $this->width, $height / $this->height);
        $this->width = max(1, (int) round($this->width * $scale));
        $this->height = max(1, (int) round($this->height * $scale));
        return true;
    }
    public function setImageFormat(string $format): bool { return true; }
    public function writeImage(string $spec): bool
    {
        self::$calls[] = ['write', [$this->width, $this->height]];
        return file_put_contents(substr($spec, 6), 'synthetic PNG') !== false;
    }
    public function clear(): bool { return true; }
    public function destroy(): bool { return true; }
}

final class ImagickPixel
{
    public function __construct(string $color) {}
}

$api = dirname(__DIR__, 3);
foreach (['LoggerInterface', 'LogLevel', 'LoggerTrait', 'AbstractLogger', 'NullLogger'] as $class) {
    require $api . '/vendor/psr/log/src/' . $class . '.php';
}
require $api . '/src/Support/Imaging/ImagickGuard.php';
require $api . '/src/Service/Mail/SupplierLogoConverter.php';
$converter = new MyInvoice\Service\Mail\SupplierLogoConverter(new Psr\Log\NullLogger());
$method = new ReflectionMethod($converter, 'convertSvgViaImagick');
$source = $argv[1] . '.svg';
file_put_contents($source, '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="50"><rect width="200" height="50"/></svg>');
try {
    $result = $method->invoke($converter, $source, $argv[1]);
} finally {
    @unlink($source);
}
echo json_encode(['converted' => $result, 'calls' => Imagick::$calls], JSON_THROW_ON_ERROR);
