<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportBinaryCodec;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use RuntimeException;
use ZipArchive;

/** Reads the existing one-company export; checks every payload before any target write. */
final class MyuctoExportReader
{
    public function read(string $path, string $password = ''): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('ZIP exportu nelze otevřít.');
        }
        try {
            if ($password !== '' && !$zip->setPassword($password)) {
                throw new RuntimeException('Heslo ZIPu nelze nastavit.');
            }
            $entries = [];
            $total = 0;
            if ($zip->numFiles > 100_000) {
                throw new RuntimeException('Export obsahuje příliš mnoho položek.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('Neplatná položka ZIPu.');
                }
                $name = $stat['name'];
                if (!self::safePath($name) || isset($entries[strtolower($name)])) {
                    throw new RuntimeException('ZIP obsahuje neplatnou nebo duplicitní cestu.');
                }
                $total += $stat['size'];
                if ($total > 20 * 1024 * 1024 * 1024) {
                    throw new RuntimeException('Export překračuje limit 20 GiB.');
                }
                $entries[strtolower($name)] = $name;
            }
            $raw = $zip->getFromName('manifest.json', 2_097_153);
            if ($raw === false || strlen($raw) > 2_097_152) {
                throw new RuntimeException('Chybí manifest nebo je příliš velký; ověřte heslo ZIPu.');
            }
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== InstanceExportService::FORMAT
                || !in_array($manifest['version'] ?? null, CompleteInstanceRestoreService::SUPPORTED_VERSIONS, true)
                || ($manifest['restore']['available'] ?? null) !== true
                || !is_array($manifest['sections']['data']['tables'] ?? null)
                || !is_array($manifest['checksums'] ?? null)) {
                throw new RuntimeException('Použijte Kompletní export dat s volbou Úplný obnovitelný archiv.');
            }
            if (
                ($manifest['range']['from'] ?? null) !== null
                || ($manifest['range']['to'] ?? null) !== null
            ) {
                throw new RuntimeException('Pro převod firmy použijte export bez omezení období.');
            }
            foreach ($manifest['checksums'] as $entry => $expected) {
                if (!is_string($entry) || !self::safePath($entry) || ($entries[strtolower($entry)] ?? null) !== $entry
                    || !is_array($expected) || !is_int($expected['size'] ?? null)
                    || !is_string($expected['sha256'] ?? null)) {
                    throw new RuntimeException('Neplatný inventář kontrolních součtů exportu.');
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException('Položku exportu nelze přečíst; ověřte heslo.');
                }
                try {
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $stream);
                    if ($size !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
                        throw new RuntimeException('Kontrolní součet exportu nesedí: ' . $entry);
                    }
                } finally {
                    fclose($stream);
                }
            }
            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '/') && !in_array($entry, ['manifest.json', 'CHECKSUMS.txt', 'CTI-MNE.txt'], true)
                    && !isset($manifest['checksums'][$entry])) {
                    throw new RuntimeException('Položka exportu nemá kontrolní součet.');
                }
            }
            $tables = [];
            $skipped = [];
            $bytes = 0;
            $rows = 0;
            $groups = [$manifest['sections']['data']['tables'], $manifest['sections']['data']['shared_tables'] ?? []];
            foreach ($groups as $group) {
                foreach ($group as $table => $info) {
                    if (!is_string($table) || !preg_match('/\A[a-z][a-z0-9_]*\z/D', $table) || !is_array($info)
                        || !is_int($info['rows'] ?? null) || $info['rows'] < 0 || isset($tables[$table])) {
                        throw new RuntimeException('Neplatná definice tabulky v exportu.');
                    }
                    $supported = in_array($table, [...MyuctoImportProfile::TABLES, ...MyuctoImportProfile::CONFIG_TABLES, 'supplier', ...array_keys(MyuctoImportProfile::GLOBAL_KEYS)], true);
                    if (
                        $info['rows'] > 0
                        && (!is_string($info['entry'] ?? null)
                        || !isset($manifest['checksums'][$info['entry']]))
                    ) {
                        throw new RuntimeException('Tabulka exportu nemá ověřená data: ' . $table . '.');
                    }
                    if (!$supported) {
                        if ($info['rows'] > 0) {
                            $skipped[$table] = $info['rows'];
                        }
                        continue;
                    }
                    $tables[$table] = [];
                    if ($info['rows'] === 0 && ($info['entry'] ?? null) === null) {
                        continue;
                    }
                    $entry = $info['entry'] ?? null;
                    if (
                        !is_string($entry)
                        || !isset($manifest['checksums'][$entry])
                        || !self::safePath($entry)
                    ) {
                        throw new RuntimeException('Chybí ověřená data tabulky ' . $table . '.');
                    }
                    $stream = $zip->getStream($entry);
                    if ($stream === false) {
                        throw new RuntimeException('Data tabulky nelze přečíst.');
                    }
                    try {
                        while (($line = fgets($stream, 4_194_305)) !== false) {
                            if (strlen($line) >= 4_194_304 && !str_ends_with($line, "\n")) {
                                throw new RuntimeException('Řádek exportu překračuje limit 4 MiB.');
                            }
                            if (trim($line) === '') {
                                continue;
                            }
                            $bytes += strlen($line);
                            if ($bytes > 67_108_864 || ++$rows > 100_000) {
                                throw new RuntimeException('Data pro převod překračují limit 64 MiB / 100 000 řádků.');
                            }
                            $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                            if (!is_array($row) || array_is_list($row)) {
                                throw new RuntimeException('Neplatný JSONL řádek exportu.');
                            }
                            $row = InstanceExportBinaryCodec::decodeRow($row);
                            foreach ($row as $value) {
                                if ($value !== null && !is_scalar($value)) {
                                    throw new RuntimeException('Nepodporovaná strukturovaná hodnota exportu.');
                                }
                            }
                            $id = self::id($row['id'] ?? null);
                            if (isset($tables[$table][$id])) {
                                throw new RuntimeException('Duplicitní ID v exportu: ' . $table . '.');
                            }
                            $tables[$table][$id] = $row;
                        }
                        if (!feof($stream)) {
                            throw new RuntimeException('Čtení tabulky exportu selhalo.');
                        }
                    } finally {
                        fclose($stream);
                    }
                    if (count($tables[$table]) !== $info['rows']) {
                        throw new RuntimeException('Počet řádků exportu nesedí: ' . $table . '.');
                    }
                }
            }
            $supplierId = self::id($manifest['supplier']['id'] ?? null);
            if (count($tables['supplier'] ?? []) !== 1 || !isset($tables['supplier'][$supplierId])) {
                throw new RuntimeException('Export musí obsahovat právě jednu zdrojovou firmu.');
            }
            $assets = [];
            $available = [];
            foreach ([...($manifest['restore']['files'] ?? []), ...($manifest['restore']['documents'] ?? [])] as $asset) {
                if (!is_array($asset) || !is_string($asset['storage_path'] ?? null) || !self::safePath($asset['storage_path'])
                    || !isset($manifest['checksums'][$asset['entry'] ?? ''])) {
                    throw new RuntimeException('Neplatná vazba souboru exportu.');
                }
                $previous = $available[$asset['storage_path']] ?? null;
                if (
                    $previous !== null
                    && $manifest['checksums'][$previous['entry']]['sha256'] !== $manifest['checksums'][$asset['entry']]['sha256']
                ) {
                    throw new RuntimeException('Dvě rozdílné přílohy používají stejnou zdrojovou cestu.');
                }
                $available[$asset['storage_path']] = $asset;
            }
            foreach (['invoices' => ['imported_pdf_path' => 'invoices-imported'],
                'purchase_invoices' => ['pdf_path' => 'purchase-invoices', 'source_path' => 'purchase-invoices']] as $table => $columns) {
                foreach ($tables[$table] ?? [] as $id => $row) {
                    foreach ($columns as $column => $area) {
                        $value = $row[$column] ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }
                        if (!is_string($value) || !self::safePath($value)) {
                            throw new RuntimeException('Neplatná zdrojová cesta dokladu.');
                        }
                        $storage = $area . '/' . $value;
                        $asset = $available[$storage] ?? throw new RuntimeException('Export postrádá originál dokladu: ' . $table . '.' . $column . '.');
                        $content = $this->content($zip, $asset['entry'], $bytes);
                        $hashColumn = match ($column) {
                            'pdf_path' => 'pdf_hash', 'source_path' => 'source_hash', 'imported_pdf_path' => 'imported_pdf_hash'
                        };
                        if (
                            !empty($row[$hashColumn])
                            && !hash_equals((string) $row[$hashColumn], hash('sha256', $content))
                        ) {
                            throw new RuntimeException('Originál dokladu nesouhlasí s otiskem v datech.');
                        }
                        $assets[] = ['table' => $table, 'id' => $id, 'column' => $column, 'area' => $area,
                            'storage_path' => $storage, 'content' => $content, 'sha256' => hash('sha256', $content)];
                    }
                }
            }
            foreach ($manifest['restore']['blobs'] ?? [] as $asset) {
                if (($asset['table'] ?? null) !== 'bank_statements' || !in_array($asset['column'] ?? null, ['file_content', 'pdf_content'], true)
                    || !isset($manifest['checksums'][$asset['entry'] ?? ''])) {
                    throw new RuntimeException('Neplatná binární příloha výpisu.');
                }
                $id = self::id($asset['id'] ?? null);
                if (!isset($tables['bank_statements'][$id])) {
                    throw new RuntimeException('Příloha výpisu nemá zdrojový řádek.');
                }
                $tables['bank_statements'][$id][$asset['column']] = $this->content($zip, $asset['entry'], $bytes);
            }
            return ['manifest' => $manifest, 'tables' => $tables, 'skipped' => $skipped, 'assets' => $assets];
        } finally {
            $zip->close();
        }
    }

    private function content(ZipArchive $zip, string $entry, int &$bytes): string
    {
        $remaining = 67_108_864 - $bytes;
        $content = $zip->getFromName($entry, $remaining + 1);
        if ($content === false || strlen($content) > $remaining) {
            throw new RuntimeException('Přílohy a data překračují limit převodu 64 MiB.');
        }
        $bytes += strlen($content);
        return $content;
    }

    public static function safePath(string $path): bool
    {
        return $path !== '' && !str_contains($path, '\\') && !str_contains($path, "\0") && !str_contains($path, ':')
            && !str_starts_with($path, '/') && !preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path);
    }

    public static function id(mixed $value): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)))
            || ($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            throw new RuntimeException('Neplatné ID v exportu.');
        }
        return $id;
    }
}
