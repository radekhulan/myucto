<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\RuntimePaths;

/** Přesné názvy uložených reportů sdílené exportem a úklidem historie jobů. */
final class ImportJobReportFiles
{
    public static function relativePath(int $supplierId, int $jobId, bool $stereoNx = false): string
    {
        return 'import-jobs/' . $supplierId . '/reports/' . ($stereoNx ? 'stereo-nx-' : '') . $jobId . '.json';
    }

    public static function resolve(int $supplierId, int $jobId, ?string $relative): ?string
    {
        if ($supplierId < 1 || $jobId < 1 || !in_array($relative, [
            self::relativePath($supplierId, $jobId), self::relativePath($supplierId, $jobId, true),
        ], true)) return null;
        // Kořen úložiště (storage/ nebo MYINVOICE_DATA_DIR) smí být symlink či junction na jiný
        // svazek; realpath ho rozbalí. Odkazy uvnitř úložiště odmítáme a cíl musí ležet pod kořenem.
        $root = RuntimePaths::storage();
        $base = realpath($root);
        if ($base === false) return null;
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) return null;
        }
        $real = realpath($path);
        $normalize = static fn (string $value): string => strtolower(str_replace('\\', '/', $value));
        if ($real === false || !is_file($real)
            || !str_starts_with($normalize($real), rtrim($normalize($base), '/') . '/')) return null;
        return $real;
    }

    /** Volat až po úspěšném odstranění řádku jobu. */
    public static function purge(array $deletedJob): void
    {
        $path = self::resolve((int) ($deletedJob['supplier_id'] ?? 0), (int) ($deletedJob['id'] ?? 0),
            isset($deletedJob['result_path']) ? (string) $deletedJob['result_path'] : null);
        if ($path !== null) @unlink($path);
    }
}
