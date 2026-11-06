<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\Shared\ImportJobReportFiles;

/** Protokol patří dokončenému jobu a zůstává dostupný i po odstranění vstupní zálohy. */
final class StereoNxRunReportStore
{
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** @param array<string,mixed> $report
     * @return array{path:string,name:string,size:int,mime:string} */
    public function save(int $supplierId, int $jobId, array $report): array
    {
        if ($supplierId < 1 || $jobId < 1 || !is_bool($report['ok'] ?? null)) {
            throw $this->storageError();
        }
        $dir = $this->directory($supplierId, true);
        if ($dir === null) throw $this->storageError();
        $relative = $this->relativePath($supplierId, $jobId);
        $path = RuntimePaths::storage($relative);
        if (is_link($path)) throw $this->storageError();
        try {
            $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->storageError();
        }
        if (strlen($json) > self::MAX_BYTES) throw $this->storageError();
        $temp = $dir . '/.stereo-nx-' . $jobId . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temp, 'xb');
        if ($handle === false) throw $this->storageError();
        try {
            $written = fwrite($handle, $json);
            $flushed = fflush($handle);
        } finally {
            fclose($handle);
        }
        @chmod($temp, 0600);
        if ($written !== strlen($json) || !$flushed || is_link($path) || !@rename($temp, $path)) {
            @unlink($temp);
            throw $this->storageError();
        }
        return ['path' => $relative, 'name' => 'stereo-nx-protocol-' . $jobId . '.json',
            'size' => strlen($json), 'mime' => 'application/json'];
    }

    /** @return array<string,mixed>|null */
    public function read(int $supplierId, int $jobId, ?string $relativePath): ?array
    {
        if ($supplierId < 1 || $jobId < 1 || $relativePath !== $this->relativePath($supplierId, $jobId)) return null;
        $dir = $this->directory($supplierId, false);
        if ($dir === null) return null;
        $path = RuntimePaths::storage($relativePath);
        if (is_link($path) || !is_file($path)) return null;
        $real = realpath($path);
        if ($real === false || !$this->within($real, $dir)) return null;
        $size = @filesize($real);
        if ($size === false || $size > self::MAX_BYTES) return null;
        $json = @file_get_contents($real);
        if ($json === false) return null;
        try {
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($report) && is_bool($report['ok'] ?? null) ? $report : null;
    }

    private function relativePath(int $supplierId, int $jobId): string
    {
        return ImportJobReportFiles::relativePath($supplierId, $jobId, true);
    }

    private function directory(int $supplierId, bool $create): ?string
    {
        // Kořen úložiště smí být symlink či junction na jiný svazek (stejně jako u ImportJobReportFiles).
        $root = RuntimePaths::storage();
        if (!is_dir($root) && (!$create || !@mkdir($root, 0700, true)) && !is_dir($root)) return null;
        $base = realpath($root);
        if ($base === false) return null;
        $path = $root;
        foreach (['import-jobs', (string) $supplierId, 'reports'] as $part) {
            $path .= '/' . $part;
            if (is_link($path)) return null;
            if (!is_dir($path) && (!$create || !@mkdir($path, 0700)) && !is_dir($path)) return null;
            $real = realpath($path);
            if ($real === false || !$this->within($real, $base)) return null;
        }
        return $real;
    }

    private function within(string $path, string $base): bool
    {
        // Windows realpath() nemusí zachovat casing; oddělovače sjednocujeme pro obě platformy.
        return str_starts_with(strtolower(str_replace('\\', '/', $path)),
            rtrim(strtolower(str_replace('\\', '/', $base)), '/') . '/');
    }

    private function storageError(): StereoNxException
    {
        return new StereoNxException('storage_error', 'Protokol převodu Stereo NX nelze uložit.');
    }
}
