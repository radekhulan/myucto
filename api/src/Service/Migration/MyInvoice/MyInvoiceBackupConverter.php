<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MyInvoice;

use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Export\Instance\InstanceExportBinaryCodec;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use MyInvoice\Service\Migration\Myucto\MyuctoImportProfile;
use RuntimeException;
use ZipArchive;

/** Converts a DB backup plus original files to the existing one-company import archive. */
final class MyInvoiceBackupConverter
{
    private const MAX_DATA_BYTES = 67_108_864;
    private const MAX_ROWS = 100_000;

    /** Match the existing target identity; never silently select the first company. */
    public static function companyByIc(array $suppliers, string $ic): int
    {
        $ic = trim($ic);
        $matches = array_filter($suppliers, static fn (array $row): bool => trim((string) ($row['ic'] ?? '')) === $ic);
        if ($ic === '' || count($matches) !== 1) {
            throw new RuntimeException('Záloha musí obsahovat právě jednu firmu s IČO cílové firmy.');
        }
        return MyuctoExportReader::id(reset($matches)['id'] ?? null);
    }

    public function convert(
        string $backup,
        array $fileArchives,
        string $output,
        int $supplierId,
        string $mode,
        array $history,
        string $password = '',
        string $outputPassword = '',
        ?string $companyIc = null,
    ): array {
        if (file_exists($output) || is_link($output) || !is_dir(dirname($output))) {
            throw new RuntimeException('Výstup musí být nový soubor v existujícím adresáři.');
        }
        if (($reason = BackupEncryption::unsupportedReason($outputPassword)) !== null) {
            throw new RuntimeException($reason);
        }
        if (count($fileArchives) > 16) {
            throw new RuntimeException('Lze přiložit nejvýše 16 archivů originálů.');
        }
        $archives = [];
        $temp = null;
        $published = false;
        $writer = null;
        try {
            $dbZip = $this->open($backup, $password);
            $archives[] = $dbZip;
            $sqlEntries = [];
            for ($i = 0; $i < $dbZip->numFiles; $i++) {
                $name = $dbZip->getNameIndex($i);
                if (is_string($name) && str_ends_with(strtolower($name), '.sql')) {
                    $sqlEntries[] = $name;
                }
            }
            if (count($sqlEntries) !== 1) {
                throw new RuntimeException('Databázový ZIP musí obsahovat právě jeden SQL dump.');
            }
            $stream = $dbZip->getStream($sqlEntries[0]);
            if ($stream === false) {
                throw new RuntimeException('SQL zálohu nelze přečíst; ověřte heslo.');
            }
            $all = [];
            $wanted = array_fill_keys(['supplier', ...MyInvoiceBackupScope::TABLES, ...array_keys(MyuctoImportProfile::GLOBAL_KEYS)], true);
            $bytes = 0;
            $rows = 0;
            try {
                foreach ((new MyInvoiceSqlDumpReader())->rows($stream, $wanted) as $item) {
                    $table = $item['table'];
                    $row = $item['row'];
                    foreach (array_keys($row) as $column) {
                        if (TenantScopeResolver::isSecretColumn($column)) {
                            unset($row[$column]);
                        }
                    }
                    foreach (MyuctoImportProfile::RESET_COLUMNS[$table] ?? [] as $column) {
                        if (array_key_exists($column, $row)) {
                            $row[$column] = null;
                        }
                    }
                    $id = MyuctoExportReader::id($row['id'] ?? null);
                    if (isset($all[$table][$id])) {
                        throw new RuntimeException('Duplicitní ID tabulky SQL zálohy: ' . $table . '.');
                    }
                    $bytes += strlen(json_encode(InstanceExportBinaryCodec::encodeRow($row), JSON_THROW_ON_ERROR));
                    if ($bytes > self::MAX_DATA_BYTES || ++$rows > self::MAX_ROWS) {
                        throw new RuntimeException('Podporovaná data celé SQL zálohy překračují 64 MiB / 100 000 řádků.');
                    }
                    $all[$table][$id] = $row;
                }
            } finally {
                fclose($stream);
            }
            if ($companyIc !== null) {
                $supplierId = self::companyByIc($all['supplier'] ?? [], $companyIc);
            }
            $tables = (new MyInvoiceBackupScope())->select($all, $supplierId, $mode, $history);
            unset($all);
            $files = [];
            foreach ($fileArchives as $path) {
                $archive = $this->open($path, $password);
                $archives[] = $archive;
                for ($i = 0; $i < $archive->numFiles; $i++) {
                    $name = $archive->getNameIndex($i);
                    if (!is_string($name) || str_ends_with($name, '/')) {
                        continue;
                    }
                    // File archives use canonical storage paths; an external archive
                    // root cannot be guessed from the broken legacy substr(rootDir) path.
                    $storage = str_starts_with($name, 'storage/') ? substr($name, 8) : $name;
                    if (!preg_match('~\A(?:purchase-invoices|invoices-imported)/~', $storage)) {
                        continue;
                    }
                    $key = strtolower($storage);
                    if (isset($files[$key])) {
                        throw new RuntimeException('Archivy obsahují duplicitní cestu originálu.');
                    }
                    $files[$key] = [$archive, $name];
                }
            }
            $temp = tempnam(dirname($output), '.myinvoice-convert-');
            if ($temp === false) {
                throw new RuntimeException('Nelze vytvořit dočasný výstup převodu.');
            }
            chmod($temp, 0600);
            $writer = new ZipArchive();
            if ($writer->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $writer = null;
                throw new RuntimeException('Nelze vytvořit výstupní ZIP.');
            }
            $checksums = [];
            $tableInfo = [];
            $shared = [];
            foreach ($tables as $table => $data) {
                ksort($data, SORT_NUMERIC);
                $entry = $data === [] ? null : 'data/' . $table . '.jsonl';
                if ($entry !== null) {
                    $jsonl = '';
                    foreach ($data as $row) {
                        $jsonl .= json_encode(InstanceExportBinaryCodec::encodeRow($row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                    }
                    $this->add($writer, $entry, $jsonl, $checksums, $outputPassword);
                }
                $info = ['rows' => count($data), 'entry' => $entry];
                if (isset(MyuctoImportProfile::GLOBAL_KEYS[$table])) {
                    $shared[$table] = $info;
                } else {
                    $tableInfo[$table] = $info;
                }
            }
            $assets = [];
            $assetEntries = [];
            $assetBytes = 0;
            foreach (['invoices' => ['imported_pdf_path' => 'invoices-imported'],
                'purchase_invoices' => ['pdf_path' => 'purchase-invoices', 'source_path' => 'purchase-invoices']] as $table => $columns) {
                foreach ($tables[$table] ?? [] as $row) {
                    foreach ($columns as $column => $area) {
                        $path = $row[$column] ?? null;
                        if ($path === null || $path === '') {
                            continue;
                        }
                        if (!is_string($path) || !MyuctoExportReader::safePath($path)) {
                            throw new RuntimeException('Doklad má neplatnou cestu originálu.');
                        }
                        $storage = $area . '/' . $path;
                        if (isset($assetEntries[$storage])) {
                            continue;
                        }
                        [$archive, $name] = $files[strtolower($storage)]
                            ?? throw new RuntimeException('Chybí originál dokladu: ' . $table . '.' . $column . '. Doplňte archiv originálů.');
                        $stat = $archive->statName($name);
                        if ($stat === false || $stat['size'] > self::MAX_DATA_BYTES - $assetBytes) {
                            throw new RuntimeException('Originály překračují limit 64 MiB.');
                        }
                        $content = $archive->getFromName($name, $stat['size']);
                        if ($content === false || strlen($content) !== $stat['size']) {
                            throw new RuntimeException('Originál nelze přečíst; ověřte heslo archivu.');
                        }
                        $assetBytes += strlen($content);
                        $entry = 'origin/' . hash('sha256', $storage) . '.bin';
                        $this->add($writer, $entry, $content, $checksums, $outputPassword);
                        $assets[] = ['entry' => $entry, 'storage_path' => $storage];
                        $assetEntries[$storage] = $entry;
                    }
                }
            }
            $manifest = ['format' => InstanceExportService::FORMAT, 'version' => InstanceExportService::FORMAT_VERSION,
                'supplier' => ['id' => $supplierId, 'name' => $tables['supplier'][$supplierId]['company_name'] ?? ''],
                'restore' => ['available' => true, 'files' => $assets, 'documents' => [], 'blobs' => []],
                'sections' => ['data' => ['tables' => $tableInfo, 'shared_tables' => $shared]],
                'checksums' => $checksums,
                'origin' => ['type' => 'myinvoice-backup', 'backup_sha256' => hash_file('sha256', $backup),
                    'vat_history' => 'administrator-supplied', 'scope' => MyInvoiceBackupScope::TABLES]];
            $raw = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (!$writer->addFromString('manifest.json', $raw)
                || !BackupEncryption::encryptEntry($writer, 'manifest.json', $outputPassword)) {
                throw new RuntimeException('Dokončení výstupního ZIPu selhalo.');
            }
            $closed = $writer->close();
            $writer = null;
            if (!$closed) {
                throw new RuntimeException('Dokončení výstupního ZIPu selhalo.');
            }
            // Same integrity, document hashes and size limits as UI/worker imports.
            $package = (new MyuctoExportReader())->read($temp, $outputPassword);
            $counts = $package['tables']->counts();
            unset($package);
            $destination = @fopen($output, 'x+b');
            if ($destination === false) {
                throw new RuntimeException('Výstup již existuje nebo jej nelze výhradně vytvořit.');
            }
            $published = true;
            chmod($output, 0600);
            $source = fopen($temp, 'rb');
            try {
                if ($source === false || stream_copy_to_stream($source, $destination) !== filesize($temp) || !fflush($destination)) {
                    throw new RuntimeException('Zápis výstupního ZIPu selhal.');
                }
                chmod($output, 0600);
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
                fclose($destination);
            }
            return ['supplier_id' => $supplierId, 'tables' => $counts, 'files' => count($assets),
                'output' => $output, 'encrypted' => $outputPassword !== ''];
        } catch (\Throwable $e) {
            if ($published) {
                unlink($output);
            }
            throw $e;
        } finally {
            if ($writer !== null) {
                $writer->close();
            }
            foreach ($archives as $archive) {
                $archive->close();
            }
            if (is_string($temp) && is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function open(string $path, string $password): ZipArchive
    {
        if (!is_file($path) || filesize($path) > 2_147_483_648) {
            throw new RuntimeException('Vstup musí být ZIP do 2 GiB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Vstupní ZIP nelze otevřít.');
        }
        try {
            if ($password !== '' && !$zip->setPassword($password)) {
                throw new RuntimeException('Heslo vstupního ZIPu nelze nastavit.');
            }
            $names = [];
            $size = 0;
            if ($zip->numFiles > 100_000) {
                throw new RuntimeException('ZIP obsahuje příliš mnoho souborů.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                if (!MyuctoExportReader::safePath($name) || isset($names[strtolower($name)])) {
                    throw new RuntimeException('ZIP obsahuje neplatnou nebo duplicitní cestu.');
                }
                $names[strtolower($name)] = true;
                $size += $stat['size'];
                if ($size > 20 * 1024 * 1024 * 1024) {
                    throw new RuntimeException('Rozbalený ZIP překračuje 20 GiB.');
                }
            }
            return $zip;
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    private function add(ZipArchive $zip, string $entry, string $content, array &$checksums, string $password): void
    {
        if (!$zip->addFromString($entry, $content) || !BackupEncryption::encryptEntry($zip, $entry, $password)) {
            throw new RuntimeException('Zápis nebo šifrování položky výstupního ZIPu selhalo.');
        }
        $checksums[$entry] = ['sha256' => hash('sha256', $content), 'size' => strlen($content)];
    }
}
