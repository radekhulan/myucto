<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use RuntimeException;

/** Owns only newly created files; rollback never removes a pre-existing path. */
final class MyuctoImportFiles
{
    private array $written = [];
    private array $directories = [];

    public function __construct(private readonly Config $config)
    {
    }

    public function prepare(array &$tables, array $assets, int $supplierId, string $runKey): array
    {
        $writes = [];
        foreach ($assets as $asset) {
            $table = $asset['table'];
            $id = $asset['id'];
            $column = $asset['column'];
            $extension = strtolower(pathinfo($asset['storage_path'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf', 'xml', 'isdoc', 'isdocx', 'json', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'csv', 'txt', 'zip'], true)) {
                $extension = 'bin';
            }
            $relative = 'sup-' . $supplierId . '/myucto-import/' . $runKey . '/' . hash('sha256', $asset['storage_path'] . $asset['sha256']) . '.' . $extension;
            $tables[$table][$id][$column] = $relative;
            $root = $asset['area'] === 'purchase-invoices'
                ? (string) ($this->config->get('purchase_invoice.archive_storage', '') ?: RuntimePaths::storage('purchase-invoices'))
                : RuntimePaths::storage($asset['area']);
            $target = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $writes[$target] = ['content' => $asset['content'], 'sha256' => $asset['sha256'], 'root' => $root];
        }
        return $writes;
    }

    public function publish(array $writes): void
    {
        foreach ($writes as $path => $asset) {
            $this->mkdir(dirname($path));
            $realRoot = realpath($asset['root']);
            $realParent = realpath(dirname($path));
            if ($realRoot === false || $realParent === false
                || !str_starts_with(strtolower($realParent . DIRECTORY_SEPARATOR), strtolower(rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Cílová cesta souboru překračuje úložiště.');
            }
            $stream = @fopen($path, 'x+b');
            if ($stream === false) {
                throw new RuntimeException('Soubor importu nelze výhradně vytvořit.');
            }
            $this->written[] = $path;
            try {
                $offset = 0;
                $length = strlen($asset['content']);
                while ($offset < $length) {
                    $bytes = fwrite($stream, substr($asset['content'], $offset, 65_536));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException('Zápis souboru importu selhal.');
                    }
                    $offset += $bytes;
                }
            } finally {
                fclose($stream);
            }
            if (!hash_equals($asset['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Kontrolní součet zapsaného souboru nesedí.');
            }
        }
    }

    public function rollback(): void
    {
        foreach (array_reverse($this->written) as $path) {
            @unlink($path);
        }
        foreach (array_reverse($this->directories) as $path) {
            @rmdir($path);
        }
        $this->written = [];
        $this->directories = [];
    }

    public function commit(): void
    {
        $this->written = [];
        $this->directories = [];
    }

    private function mkdir(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Cílový adresář importu nesmí být symbolický odkaz.');
        }
        if (is_dir($path)) {
            return;
        }
        $parent = dirname($path);
        if ($parent === $path) {
            throw new RuntimeException('Nelze vytvořit úložiště importu.');
        }
        $this->mkdir($parent);
        if (@mkdir($path, 0770)) {
            $this->directories[] = $path;
        } elseif (!is_dir($path)) {
            throw new RuntimeException('Nelze vytvořit adresář importu.');
        }
    }
}
