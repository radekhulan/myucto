<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Nahrané zálohy agend čekající na převod: `storage/money-s3/<firma>/<token>/`.
 *
 * Záloha se nahraje jednou, rozbalí se a průvodce nad ní pouští náhled, zkoušku
 * nanečisto i ostrý převod — worker běží až po skončení requestu, takže data musí
 * ležet na disku. Token je náhodný a adresář je pod firmou: cizí firma na nahranou
 * zálohu nedosáhne ani se znalostí tokenu. Po úspěšném ostrém převodu se adresář
 * smaže, neukončené nahrávky se uklidí po týdnu.
 */
final class MoneyS3Uploads
{
    public const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';
    private const STALE_DAYS = 7;

    public static function base(int $supplierId): string
    {
        return RuntimePaths::storage('money-s3/' . $supplierId);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function dir(int $supplierId, string $token): string
    {
        if ($supplierId <= 0 || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new MoneyS3Exception('upload_not_found', 'Nahraná záloha nebyla nalezena.', [], 404);
        }
        return self::base($supplierId) . '/' . $token;
    }

    public static function agendaDir(int $supplierId, string $token): string
    {
        return self::dir($supplierId, $token) . '/agenda';
    }

    /** @return array<string,mixed> */
    public static function meta(int $supplierId, string $token): array
    {
        $path = self::dir($supplierId, $token) . '/meta.json';
        if (!is_file($path)) {
            throw new MoneyS3Exception('upload_not_found', 'Nahraná záloha nebyla nalezena (mohla být už uklizena).', [], 404);
        }
        $meta = json_decode((string) file_get_contents($path), true);
        return is_array($meta) ? $meta : [];
    }

    /** @param array<string,mixed> $meta */
    public static function writeMeta(int $supplierId, string $token, array $meta): void
    {
        file_put_contents(
            self::dir($supplierId, $token) . '/meta.json',
            (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public static function reportPath(int $supplierId, string $token, int $year): string
    {
        return self::dir($supplierId, $token) . '/reports/' . $year . '.csv';
    }

    /** @return array<int,string> rok => cesta k předvaze z Money */
    public static function reports(int $supplierId, string $token): array
    {
        $out = [];
        foreach (glob(self::dir($supplierId, $token) . '/reports/*.csv') ?: [] as $path) {
            $year = (int) basename($path, '.csv');
            if ($year >= 1990 && $year <= 2100) {
                $out[$year] = $path;
            }
        }
        ksort($out);
        return $out;
    }

    public static function purge(int $supplierId, string $token): void
    {
        try {
            $dir = self::dir($supplierId, $token);
        } catch (MoneyS3Exception) {
            return;
        }
        self::removeTree($dir, $supplierId);
    }

    public static function purgeStale(int $supplierId): void
    {
        $limit = time() - self::STALE_DAYS * 86400;
        foreach (glob(self::base($supplierId) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match(self::TOKEN_PATTERN, basename($dir)) === 1 && (int) filemtime($dir) < $limit) {
                self::removeTree($dir, $supplierId);
            }
        }
    }

    /**
     * Mazání jen pod adresářem firmy. Casing cest na Windows nesedí spolehlivě,
     * proto se obě strany porovnávají malými písmeny.
     */
    private static function removeTree(string $dir, int $supplierId): void
    {
        $real = realpath($dir);
        $base = realpath(self::base($supplierId));
        if ($real === false || $base === false
            || !str_starts_with(strtolower($real), strtolower($base) . DIRECTORY_SEPARATOR)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($real);
    }
}
