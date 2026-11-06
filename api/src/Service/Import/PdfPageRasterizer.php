<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Support\Imaging\ImagickGuard;
use Psr\Log\LoggerInterface;

/**
 * PDF → PNG stránky pro vision modely (Ollama). Pořadí backendů:
 *   1. CLI `pdftoppm` z Poppleru (Linux i Windows; v Docker image) — přednostně,
 *      protože jen vykresluje a na podstrčená PDF je méně náchylný než Ghostscript,
 *   2. Imagick s Ghostscript delegatem jako záloha, když pdftoppm chybí nebo selže
 *      (po překročení limitu ne: stejné PDF by jen dostal rizikovější backend),
 *   3. nic → prázdný seznam. Rasterizace nikdy nevyhodí výjimku, vytěžování pak
 *      pokračuje jen s textovou vrstvou.
 * Dočasné soubory jdou do `storage/cache/ollama` přes {@see RuntimePaths}.
 */
final class PdfPageRasterizer implements PdfPageRasterizerInterface
{
    public const DPI = 150;
    public const MAX_EDGE = 1600;
    /** Tvrdý strop jednoho běhu rasterizace (s) — PDF přichází od nedůvěryhodného uživatele. */
    public const TIMEOUT = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $useImagick = true,
        private readonly ?string $pdftoppmBinary = null,
        private readonly int $timeoutSeconds = self::TIMEOUT,
    ) {}

    public function rasterize(string $pdfBytes, int $maxPages): array
    {
        try {
            return $this->rasterizeUnsafe($pdfBytes, $maxPages);
        } catch (\Throwable $e) {
            // Typicky shell_exec/proc_open v disable_functions: PHP 8 hází \Error, který @ neumlčí.
            $this->logger->warning('Rasterizace PDF selhala: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<string> */
    private function rasterizeUnsafe(string $pdfBytes, int $maxPages): array
    {
        if ($maxPages < 1 || !str_starts_with($pdfBytes, '%PDF')) {
            return [];
        }
        $tmpDir = RuntimePaths::storage('cache/ollama');
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            return [];
        }
        $pdfPath = @tempnam($tmpDir, 'pdf');
        if ($pdfPath === false) {
            return [];
        }
        try {
            if (@file_put_contents($pdfPath, $pdfBytes) === false) {
                return [];
            }
            // pdftoppm přednostně: PDF je nedůvěryhodný vstup a Ghostscript (delegát
            // Imagicku) interpretuje PostScript — opakovaně v něm šlo spustit kód.
            $bin = $this->pdftoppmBinary ?? self::findBinary('pdftoppm');
            if ($bin !== null && $bin !== '') {
                $pages = $this->viaPdftoppm($bin, $pdfPath, $maxPages);
                if ($pages === null || $pages !== []) {
                    return $pages ?? [];
                }
            }
            return ($this->useImagick && class_exists(\Imagick::class)) ? $this->viaImagick($pdfPath, $maxPages) : [];
        } finally {
            @unlink($pdfPath);
            self::removeLeftovers($pdfPath);
        }
    }

    /** @return list<string> */
    private function viaImagick(string $pdfPath, int $maxPages): array
    {
        // Limity jsou v php-fpm globální pro celý proces → ImagickGuard je po práci vrátí.
        try {
            return ImagickGuard::withLimits(static function () use ($pdfPath, $maxPages): array {
                $im = new \Imagick();
                $im->setResolution(self::DPI, self::DPI);
                $im->readImage('pdf:' . $pdfPath . '[0-' . ($maxPages - 1) . ']');
                $out = [];
                foreach ($im as $page) {
                    $page->setImageBackgroundColor('white');
                    $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                    if (max($page->getImageWidth(), $page->getImageHeight()) > self::MAX_EDGE) {
                        $page->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
                    }
                    $page->setImageFormat('png');
                    $out[] = $page->getImageBlob();
                }
                $im->clear();
                return array_slice($out, 0, $maxPages);
            }, $this->timeoutSeconds);
        } catch (\Throwable $e) {
            $this->logger->info('Imagick rasterizace PDF selhala: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<string>|null null = překročen časový limit */
    private function viaPdftoppm(string $bin, string $pdfPath, int $maxPages): ?array
    {
        $prefix = $pdfPath . '-p';
        $errPath = $pdfPath . '-err.txt';
        $nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = [$bin, '-png', '-r', (string) self::DPI, '-scale-to', (string) self::MAX_EDGE,
            '-f', '1', '-l', (string) $maxPages, $pdfPath, $prefix];
        // Soubory místo pipes: žádný deadlock na plné rouře, funguje stejně na Windows.
        if (!function_exists('proc_open')) {
            return [];
        }
        $proc = @proc_open($cmd, [1 => ['file', $nullDev, 'w'], 2 => ['file', $errPath, 'w']], $pipes);
        if (!is_resource($proc)) {
            return [];
        }
        $deadline = microtime(true) + max(1, $this->timeoutSeconds);
        $exit = null;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(50_000);
        }
        if ($timedOut) {
            self::killTree($proc);
            proc_terminate($proc);
            usleep(100_000);
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
        }
        $closeCode = proc_close($proc);
        $exit = $exit ?? $closeCode;
        $stderr = (string) @file_get_contents($errPath, false, null, 0, 300);

        $out = [];
        if (!$timedOut) {
            foreach (self::leftovers($prefix . '-') as $file) {
                $png = @file_get_contents($file);
                if (is_string($png) && $png !== '') {
                    $out[] = $png;
                }
            }
        }
        if ($timedOut || ($exit !== 0 && $out === [])) {
            $this->logger->warning('pdftoppm ' . ($timedOut ? 'překročilo časový limit' : 'selhalo')
                . ' (exit ' . $exit . '): ' . mb_substr($stderr, 0, 300));
        }
        return $timedOut ? null : array_slice($out, 0, $maxPages);
    }

    /**
     * Na Windows proc_terminate ukončí jen přímý proces (u .cmd obalu cmd.exe), potomek
     * běží dál a drží otevřený stderr soubor, který pak nejde smazat. taskkill /T vezme celý strom.
     *
     * @param resource $proc
     */
    private static function killTree($proc): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }
        $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
        if ($pid <= 0) {
            return;
        }
        $kill = @proc_open(['taskkill', '/T', '/F', '/PID', (string) $pid], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (is_resource($kill)) {
            proc_close($kill);
        }
    }

    /** @return list<string> PNG výstupy pdftoppm (`<prefix>*.png`), přirozeně seřazené; bez glob(). */
    private static function leftovers(string $prefix): array
    {
        $dir = dirname($prefix);
        $base = basename($prefix);
        $files = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if (str_starts_with($name, $base) && str_ends_with($name, '.png')) {
                $files[] = $dir . '/' . $name;
            }
        }
        natsort($files);
        return array_values($files);
    }

    /** Smaže PNG a stderr soubory pdftoppm patřící k danému PDF (i po selhání/timeoutu). */
    private static function removeLeftovers(string $pdfPath): void
    {
        foreach (self::leftovers($pdfPath . '-p-') as $file) {
            @unlink($file);
        }
        @unlink($pdfPath . '-err.txt');
    }

    /** `command -v` / `where` jako SupplierLogoConverter::findBinary(), ale s přesnou detekcí Windows. */
    private static function findBinary(string $name): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }
        $out = (string) @shell_exec(self::lookupCommand($name, PHP_OS_FAMILY));
        foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
        return null;
    }

    /** Přesné porovnání: PHP_OS_FAMILY 'Darwin' obsahuje „win", macOS ale `where` nemá. */
    private static function lookupCommand(string $name, string $osFamily): string
    {
        return $osFamily === 'Windows'
            ? 'where ' . escapeshellarg($name) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
    }
}
