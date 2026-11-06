<?php

declare(strict_types=1);

namespace MyInvoice\Support\Imaging;

/**
 * Společné pojistky pro dekódování nedůvěryhodných souborů přes Imagick.
 *
 *   - coder se volí z ověřených magic bytes a vynucuje prefixem (`png:soubor`), takže
 *     ImageMagick formát neodhaduje z obsahu (MVG, MSL, TEXT apod. se nedostanou ke slovu),
 *   - rozměry rastru se zjišťují přes pingImage() PŘED dekódováním (strop pixelů),
 *   - withLimits() nastaví resource limity (paměť, mapa, plocha, disk, čas, šířka/výška,
 *     počet snímků) a po práci vrátí původní hodnoty: v php-fpm / Apache prefork jsou
 *     limity globální pro celý proces.
 */
final class ImagickGuard
{
    /**
     * Strop pixelů rastru. Pokryje i 48 a 50Mpx fotky z mobilů. Pixel cache nad
     * paměťový limit odkládá ImageMagick na mapu a disk, takže strop nehrozí
     * vyčerpáním paměti PHP. GD cesta drží vlastní nižší strop.
     */
    public const MAX_PIXELS = 80_000_000;
    public const MAX_EDGE = 20_000;
    public const TIMEOUT = 30;

    private const MEMORY = 256 * 1024 * 1024;
    private const MAP = 512 * 1024 * 1024;
    private const DISK = 1024 * 1024 * 1024;
    private const LIST_LENGTH = 32;
    /** „Bez časového limitu“ pro návrat po operaci: 10 let v sekundách. */
    private const TIME_UNLIMITED = 315_360_000;

    /** HEIF/HEIC značky (ftyp major brand), které čte coder HEIC. */
    private const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /** Coder ImageMagicku pro rastrový obrázek podle magic bytes; null = nepodporovaný obsah. */
    public static function rasterCoder(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 32);
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($head, 'BM') => 'bmp',
            str_starts_with($head, "II*\x00"), str_starts_with($head, "MM\x00*") => 'tiff',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIF_BRANDS, true) => 'heic',
            default => null,
        };
    }

    /** PDF podle hlavičky (`%PDF-` smí podle specifikace ležet v prvním kilobajtu). */
    public static function isPdf(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 1024);
        return str_contains($head, '%PDF-');
    }

    /**
     * Rozměry prvního snímku bez dekódování pixelů.
     *
     * @param string $spec soubor s vynuceným coderem, např. `png:/cesta/soubor[0]`
     * @return array{0:int, 1:int}|null
     */
    public static function ping(string $spec): ?array
    {
        $probe = new \Imagick();
        try {
            $probe->pingImage($spec);
            $w = (int) $probe->getImageWidth();
            $h = (int) $probe->getImageHeight();
        } finally {
            $probe->clear();
        }
        return ($w > 0 && $h > 0) ? [$w, $h] : null;
    }

    public static function withinPixelLimit(int $width, int $height, int $maxPixels = self::MAX_PIXELS): bool
    {
        return $width > 0 && $height > 0
            && $width <= self::MAX_EDGE && $height <= self::MAX_EDGE
            && $width * $height <= $maxPixels;
    }

    /**
     * Spustí $work s nastavenými resource limity Imagicku a pak vrátí původní hodnoty.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function withLimits(callable $work, int $timeoutSeconds = self::TIMEOUT): mixed
    {
        $limits = [
            \Imagick::RESOURCETYPE_MEMORY => self::MEMORY,
            \Imagick::RESOURCETYPE_MAP    => self::MAP,
            \Imagick::RESOURCETYPE_AREA   => self::MAX_PIXELS,
            \Imagick::RESOURCETYPE_DISK   => self::DISK,
        ];
        $optional = [
            'RESOURCETYPE_TIME'       => $timeoutSeconds,
            'RESOURCETYPE_WIDTH'      => self::MAX_EDGE,
            'RESOURCETYPE_HEIGHT'     => self::MAX_EDGE,
            'RESOURCETYPE_LISTLENGTH' => self::LIST_LENGTH,
        ];
        foreach ($optional as $name => $value) {
            if (defined('Imagick::' . $name)) {
                $limits[constant('Imagick::' . $name)] = $value;
            }
        }

        $timeType = defined('Imagick::RESOURCETYPE_TIME') ? constant('Imagick::RESOURCETYPE_TIME') : null;
        $previous = [];
        try {
            foreach ($limits as $type => $value) {
                $previous[$type] = \Imagick::getResourceLimit($type);
                \Imagick::setResourceLimit($type, $value);
            }
            return $work();
        } finally {
            foreach ($previous as $type => $value) {
                try {
                    \Imagick::setResourceLimit($type, self::restorableLimit($type === $timeType, $value));
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Hodnota pro návrat limitu. Neomezený limit vrací Imagick jako float nad PHP_INT_MAX
     * (nebo 0). Časový limit se ale „neomezenou“ hodnotou vrátit nedá: ImageMagick 7.1.2
     * pak hlásí `time limit exceeded` u každé další operace v procesu (přetečení při
     * porovnání s epochou pixel cache). Proto ho vracíme na konečných 10 let.
     */
    private static function restorableLimit(bool $isTime, float|int $value): int
    {
        if ($isTime && ($value <= 0 || $value >= self::TIME_UNLIMITED)) {
            return self::TIME_UNLIMITED;
        }
        return $value >= PHP_INT_MAX ? PHP_INT_MAX : (int) $value;
    }
}
