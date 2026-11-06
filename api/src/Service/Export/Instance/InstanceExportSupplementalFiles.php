<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Migration\Shared\ImportJobReportFiles;
use PDO;

final class InstanceExportSupplementalFiles
{
    public function __construct(private readonly PDO $pdo) {}

    public function forSupplier(int $supplierId): array
    {
        $files = [];
        $logoRoot = RuntimePaths::storage('supplier-logos');
        foreach (glob($logoRoot . '/sup-' . $supplierId . '*') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match('/^sup-' . $supplierId . '(?:-brand-[1-9][0-9]*-[a-f0-9]{12})?\.(?:png|jpg|jpeg|svg|webp)$/', $name)) {
                continue;
            }
            $this->add($files, $logoRoot, $name, 'supplier-logos/' . $name, 'prilohy/loga/' . $name, 'supplier_logo');
        }

        $stmt = $this->pdo->prepare('SELECT id, result_path FROM import_jobs WHERE supplier_id = ? AND result_path IS NOT NULL');
        $stmt->execute([$supplierId]);
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) $row['id'];
            $storagePath = (string) $row['result_path'];
            $source = ImportJobReportFiles::resolve($supplierId, $id, $storagePath);
            if ($source === null) continue;
            $files[] = [
                'source' => $source, 'storage_path' => $storagePath,
                'entry' => 'prilohy/importy/' . basename($source),
                'sha256' => hash_file('sha256', $source) ?: null, 'kind' => 'import_report',
            ];
        }
        $stmt->closeCursor();
        return $files;
    }

    private function add(array &$files, string $root, string $relative, string $storagePath, string $entry, string $kind): void
    {
        $base = realpath($root);
        $source = realpath($root . '/' . $relative);
        if ($base === false || $source === false || !is_file($source)
            || !str_starts_with(strtolower($source), strtolower(rtrim($base, '/\\') . DIRECTORY_SEPARATOR))) {
            return;
        }
        $files[] = [
            'source' => $source, 'storage_path' => $storagePath, 'entry' => $entry,
            'sha256' => hash_file('sha256', $source) ?: null, 'kind' => $kind,
        ];
    }
}
