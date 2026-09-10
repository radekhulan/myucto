<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/**
 * Skeny nahrané do stagingu dávky (chunkovaný upload):
 *   - `blob` = jeden ZIP, rozbaluje se po položkách (nic se nedrží celé v paměti),
 *   - `manifest.jsonl` + `p…` soubory = víc samostatně nahraných souborů.
 *
 * Staging zůstává až do úspěšného dokončení dávky, takže běh po pádu dostane
 * tytéž soubory znovu. Každý soubor se vydá jako dočasná kopie.
 */
final class UploadedScanSource implements ScanSourceInterface
{
    /** Strop jednoho souboru — víc AI extrakce ani upload faktury nepřijme. */
    public const MAX_FILE_BYTES = 32 * 1024 * 1024;
    /** Ochrana proti ZIP bombě. */
    private const MAX_TOTAL_BYTES = 8 * 1024 * 1024 * 1024;
    private const MAX_ENTRIES = 50000;

    public function __construct(private readonly string $dir) {}

    public function count(): ?int
    {
        $manifest = $this->dir . '/manifest.jsonl';
        if (is_file($manifest)) {
            return count(file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }
        $blob = $this->dir . '/blob';
        if (is_file($blob) && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($blob) === true) {
                $n = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (self::usableEntry($name)) {
                        $n++;
                    }
                }
                $zip->close();
                return $n;
            }
        }
        return null;
    }

    public function files(): iterable
    {
        if (is_file($this->dir . '/manifest.jsonl')) {
            yield from $this->manifestFiles();
            return;
        }
        if (is_file($this->dir . '/blob')) {
            yield from $this->zipFiles($this->dir . '/blob');
            return;
        }
        throw new \RuntimeException('Soubory dávky nenalezeny (staging je prázdný).');
    }

    public function hasFiles(): bool
    {
        return is_file($this->dir . '/manifest.jsonl') || is_file($this->dir . '/blob');
    }

    public function cleanup(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    /** @return \Generator<ScanSourceFile> */
    private function manifestFiles(): \Generator
    {
        $lines = file($this->dir . '/manifest.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if (!is_array($e)) {
                continue;
            }
            $name = self::cleanName((string) ($e['n'] ?? 'sken'));
            // Soubor odmítnutý už při nahrání (příliš velký) — v dávce zůstane jako chyba.
            if (is_string($e['e'] ?? null) && $e['e'] !== '') {
                yield new ScanSourceFile($name, '', (int) ($e['s'] ?? 0), $e['e']);
                continue;
            }
            $staged = $this->dir . '/' . basename((string) ($e['f'] ?? ''));
            if (!is_file($staged)) {
                yield new ScanSourceFile($name, '', 0, 'missing');
                continue;
            }
            $size = (int) filesize($staged);
            if ($size > self::MAX_FILE_BYTES) {
                yield new ScanSourceFile($name, '', $size, 'too_large');
                continue;
            }
            $tmp = $this->tempPath();
            if (!@copy($staged, $tmp)) {
                yield new ScanSourceFile($name, '', $size, 'unreadable');
                continue;
            }
            yield new ScanSourceFile($name, $tmp, $size);
        }
    }

    /** @return \Generator<ScanSourceFile> */
    private function zipFiles(string $blob): \Generator
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Rozšíření ext-zip není dostupné.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($blob) !== true) {
            throw new \RuntimeException('Nahraný soubor není platný ZIP.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('ZIP obsahuje příliš mnoho souborů.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = is_array($stat) ? (string) $stat['name'] : '';
                if (!self::usableEntry($entry)) {
                    continue;
                }
                $name = self::cleanName(basename(str_replace('\\', '/', $entry)));
                $size = (int) ($stat['size'] ?? 0);
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new \RuntimeException('ZIP po rozbalení přesahuje povolenou velikost.');
                }
                if ($size > self::MAX_FILE_BYTES) {
                    yield new ScanSourceFile($name, '', $size, 'too_large');
                    continue;
                }
                $in = $zip->getStream($entry);
                $tmp = $this->tempPath();
                $out = $in !== false ? @fopen($tmp, 'wb') : false;
                if ($in === false || $out === false) {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                    yield new ScanSourceFile($name, '', $size, 'unreadable');
                    continue;
                }
                // Velikost z hlavičky ZIP může lhát — kopíruje se nejvýš strop + 1 bajt.
                $copied = (int) stream_copy_to_stream($in, $out, self::MAX_FILE_BYTES + 1);
                fclose($in);
                fclose($out);
                if ($copied > self::MAX_FILE_BYTES) {
                    @unlink($tmp);
                    yield new ScanSourceFile($name, '', $copied, 'too_large');
                    continue;
                }
                yield new ScanSourceFile($name, $tmp, $copied);
            }
        } finally {
            $zip->close();
        }
    }

    private static function usableEntry(string $entry): bool
    {
        $entry = str_replace('\\', '/', $entry);
        if ($entry === '' || str_ends_with($entry, '/') || str_starts_with($entry, '__MACOSX/')) {
            return false;
        }
        $base = basename($entry);
        return $base !== '' && $base[0] !== '.' && strcasecmp($base, 'Thumbs.db') !== 0;
    }

    private static function cleanName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1250');
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        return $name !== '' ? mb_substr($name, 0, 255) : 'sken';
    }

    private function tempPath(): string
    {
        return $this->dir . '/x-' . bin2hex(random_bytes(8));
    }
}
