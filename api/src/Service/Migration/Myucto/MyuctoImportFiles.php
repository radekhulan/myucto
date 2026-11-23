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

    /**
     * Přílohy dokladů dostanou cestu pod cílovou firmou a během importu; řádek dokladu
     * v úložišti tabulek (nebo v poli) se přepíše na novou cestu. Obsah přílohy je buď
     * v `content`, nebo (čtečka exportu) v dočasném souboru `file`.
     *
     * @param MyuctoRowStore|array<array<array<mixed>>> $tables
     */
    public function prepare(MyuctoRowStore|array &$tables, array $assets, int $supplierId, string $runKey): array
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
            if ($tables instanceof MyuctoRowStore) {
                $row = $tables->get($table, $id) ?? throw new RuntimeException('Příloha nemá zdrojový řádek dokladu.');
                $row[$column] = $relative;
                $tables->put($table, $id, $row);
            } else {
                $tables[$table][$id][$column] = $relative;
            }
            $root = $asset['area'] === 'purchase-invoices'
                ? (string) ($this->config->get('purchase_invoice.archive_storage', '') ?: RuntimePaths::storage('purchase-invoices'))
                : RuntimePaths::storage($asset['area']);
            $target = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $writes[$target] = self::source($asset) + ['sha256' => $asset['sha256'], 'root' => $root];
        }
        return $writes;
    }

    /**
     * Soubory, které aplikace hledá podle firmy a názvu (dokumenty podle otisku, archiv
     * PDF vydaných faktur), patří na pevnou cestu pod cílovou firmou. Shodný soubor, který
     * tam už je, se ponechá (obnova ho nevlastní a rollback ho nesmaže), odlišný obnovu zastaví.
     *
     * @param list<array{area:string,path:string,sha256:string,content?:string,file?:string}> $stored
     */
    public function prepareStored(array $stored, int $supplierId): array
    {
        $patterns = ['documents' => '~\A[0-9a-f]{2}/[^/]+\z~D', 'invoices' => '~\A_archive/(?:[0-9]{4}-[0-9]{2}/)?[^/]+\z~D'];
        $writes = [];
        foreach ($stored as $file) {
            $pattern = $patterns[$file['area']] ?? throw new RuntimeException('Nepodporované úložiště souboru importu.');
            if (!preg_match($pattern, $file['path']) || !MyuctoExportReader::safePath($file['path'])) {
                throw new RuntimeException('Neplatná cesta souboru importu.');
            }
            $root = RuntimePaths::storage($file['area']);
            $target = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'sup-' . $supplierId . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
            $writes[$target] = self::source($file) + ['sha256' => $file['sha256'], 'root' => $root, 'shared' => true];
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
            if (($asset['shared'] ?? false) && is_file($path)) {
                if (is_link($path) || !hash_equals($asset['sha256'], (string) hash_file('sha256', $path))) {
                    throw new RuntimeException('V úložišti cílové firmy už je jiný soubor se stejným názvem.');
                }
                continue;
            }
            $stream = @fopen($path, 'x+b');
            if ($stream === false) {
                throw new RuntimeException('Soubor importu nelze výhradně vytvořit.');
            }
            $this->written[] = $path;
            try {
                if (isset($asset['file'])) {
                    $source = @fopen($asset['file'], 'rb');
                    if ($source === false) {
                        throw new RuntimeException('Dočasně uložená příloha exportu chybí.');
                    }
                    try {
                        if (stream_copy_to_stream($source, $stream) === false) {
                            throw new RuntimeException('Zápis souboru importu selhal.');
                        }
                    } finally {
                        fclose($source);
                    }
                } else {
                    $offset = 0;
                    $length = strlen($asset['content']);
                    while ($offset < $length) {
                        $bytes = fwrite($stream, substr($asset['content'], $offset, 65_536));
                        if ($bytes === false || $bytes === 0) {
                            throw new RuntimeException('Zápis souboru importu selhal.');
                        }
                        $offset += $bytes;
                    }
                }
            } finally {
                fclose($stream);
            }
            if (!hash_equals($asset['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Kontrolní součet zapsaného souboru nesedí.');
            }
        }
    }

    /** @return array{content:string}|array{file:string} */
    private static function source(array $asset): array
    {
        if (isset($asset['file']) && is_string($asset['file'])) {
            return ['file' => $asset['file']];
        }
        return ['content' => (string) ($asset['content'] ?? throw new RuntimeException('Příloha importu nemá obsah.'))];
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
