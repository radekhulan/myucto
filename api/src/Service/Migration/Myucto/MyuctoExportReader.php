<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Service\Export\Instance\CompleteInstanceRestoreService;
use MyInvoice\Service\Export\Instance\InstanceExportBinaryCodec;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use RuntimeException;
use ZipArchive;

/**
 * Čte existující export jedné firmy a ověří každou položku dřív, než cokoli zapíše do cíle.
 *
 * Data se čtou proudem: tabulky řádek po řádku ze ZIPu do dočasného úložiště
 * ({@see MyuctoRowStore}), přílohy rovnou do dočasných souborů. V paměti zůstává jen
 * manifest a indexy, takže velikost firmy nenaráží na paměť PHP. Limity chrání před
 * podvrženým archivem (zip bomb, traversal, přerostlý řádek) a jsou nastavené pro
 * reálné firmy se statisíci řádky deníku.
 */
final class MyuctoExportReader
{
    /** Nejvýš položek ZIPu (data, přílohy, dokumenty). */
    public const MAX_ENTRIES = 250_000;
    /** Součet nekomprimovaných velikostí všech položek ZIPu. */
    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024 * 1024;
    /** Manifest nese kontrolní součty každé položky, u firmy s tisíci příloh má megabajty. */
    public const MAX_MANIFEST_BYTES = 64 * 1024 * 1024;
    /** Součet dat převáděných tabulek (JSONL). */
    public const MAX_DATA_BYTES = 8 * 1024 * 1024 * 1024;
    /** Řádků v jedné tabulce. */
    public const MAX_TABLE_ROWS = 20_000_000;
    /** Jeden řádek JSONL. */
    public const MAX_LINE_BYTES = 16 * 1024 * 1024;
    /** Jedna příloha (originál dokladu, dokument, výpis). */
    public const MAX_FILE_BYTES = 512 * 1024 * 1024;
    /**
     * Nejvyšší kompresní poměr položky nad {@see RATIO_MIN_BYTES}. JSONL exportu se
     * komprimuje kolem 10–50×, deflate zvládne nejvýš ~1000×; podvržená položka
     * s extrémním poměrem se odmítne dřív, než se rozbalí.
     */
    public const MAX_RATIO = 300;
    public const RATIO_MIN_BYTES = 1024 * 1024;

    public function __construct(
        private readonly int $maxDataBytes = self::MAX_DATA_BYTES,
        private readonly int $maxTableRows = self::MAX_TABLE_ROWS,
        private readonly int $maxLineBytes = self::MAX_LINE_BYTES,
        private readonly int $maxFileBytes = self::MAX_FILE_BYTES,
        private readonly int $maxManifestBytes = self::MAX_MANIFEST_BYTES,
        private readonly ?string $tempDir = null,
    ) {
    }

    /**
     * @param null|callable(string):void $progress průběh po tabulkách
     * @return array{manifest:array<string,mixed>,tables:MyuctoRowStore,skipped:array<string,int>,assets:list<array<string,mixed>>,context:array<string,array<int,array<string,mixed>>>}
     */
    public function read(string $path, string $password = '', ?callable $progress = null): array
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
            $sizes = [];
            $total = 0;
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Export obsahuje příliš mnoho položek (nejvýš ' . self::MAX_ENTRIES . ').');
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
                $size = (int) $stat['size'];
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Export překračuje limit 20 GiB.');
                }
                if ($size > self::RATIO_MIN_BYTES && $size > max(1, (int) $stat['comp_size']) * self::MAX_RATIO) {
                    throw new RuntimeException('Položka exportu má podezřelý kompresní poměr: ' . $name . '.');
                }
                $entries[strtolower($name)] = $name;
                $sizes[$name] = $size;
            }
            $manifestSize = $sizes['manifest.json'] ?? null;
            $raw = $manifestSize === null || $manifestSize > $this->maxManifestBytes
                ? false : $zip->getFromName('manifest.json', $manifestSize + 1);
            if ($raw === false || strlen($raw) > $this->maxManifestBytes) {
                throw new RuntimeException('Chybí manifest nebo je příliš velký; ověřte heslo ZIPu.');
            }
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            unset($raw);
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
                if ($expected['size'] !== $sizes[$entry]) {
                    throw new RuntimeException('Kontrolní součet exportu nesedí: ' . $entry);
                }
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new RuntimeException('Položku exportu nelze přečíst; ověřte heslo.');
                }
                try {
                    $hash = hash_init('sha256');
                    // Nikdy nečte víc, než položka deklaruje: podvržená položka neroste do nekonečna.
                    $size = hash_update_stream($hash, $stream, $expected['size'] + 1);
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
            $tables = MyuctoRowStore::create($this->tempDir);
            $skipped = [];
            $bytes = 0;
            $groups = [$manifest['sections']['data']['tables'], $manifest['sections']['data']['shared_tables'] ?? []];
            foreach ($groups as $group) {
                foreach ($group as $table => $info) {
                    if (!is_string($table) || !preg_match('/\A[a-z][a-z0-9_]*\z/D', $table) || !is_array($info)
                        || !is_int($info['rows'] ?? null) || $info['rows'] < 0 || $tables->hasTable($table)) {
                        throw new RuntimeException('Neplatná definice tabulky v exportu.');
                    }
                    $keyless = in_array($table, [...MyuctoImportProfile::KEYLESS_JOURNAL_TABLES, ...array_keys(MyuctoImportProfile::KEYLESS_TABLES)], true);
                    $supported = $keyless || in_array($table, [...MyuctoImportProfile::TABLES, ...MyuctoImportProfile::CONFIG_TABLES, ...MyuctoImportProfile::CONTEXT_TABLES,
                        MyuctoImportProfile::ENTRY_FORM_HISTORY_TABLE, 'supplier', ...array_keys(MyuctoImportProfile::GLOBAL_KEYS)], true);
                    // Kontextová tabulka se jen čte ke kontrole a v protokolu zůstává mimo rozsah převodu.
                    if (in_array($table, MyuctoImportProfile::CONTEXT_TABLES, true) && $info['rows'] > 0) {
                        $skipped[$table] = $info['rows'];
                    }
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
                    $tables->addTable($table);
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
                    if ($info['rows'] > $this->maxTableRows) {
                        throw new RuntimeException('Tabulka exportu ' . $table . ' překračuje limit ' . $this->maxTableRows . ' řádků.');
                    }
                    if ($progress !== null) {
                        $progress('Čtení exportu: ' . $table . ' (' . $info['rows'] . ' řádků)');
                    }
                    $rows = 0;
                    foreach ($this->lines($zip, $entry) as $line) {
                        $bytes += strlen($line);
                        if ($bytes > $this->maxDataBytes) {
                            throw new RuntimeException('Data pro převod překračují limit ' . self::formatBytes($this->maxDataBytes) . '.');
                        }
                        // Víc řádků, než manifest deklaruje, je neplatný export; dál se nečte.
                        if (++$rows > $info['rows']) {
                            throw new RuntimeException('Počet řádků exportu nesedí: ' . $table . '.');
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
                        $id = $keyless ? $rows : self::id($row['id'] ?? null);
                        if ($tables->has($table, $id)) {
                            throw new RuntimeException('Duplicitní ID v exportu: ' . $table . '.');
                        }
                        $tables->put($table, $id, $row);
                    }
                    if ($tables->count($table) !== $info['rows']) {
                        throw new RuntimeException('Počet řádků exportu nesedí: ' . $table . '.');
                    }
                }
            }
            $context = [];
            foreach (MyuctoImportProfile::CONTEXT_TABLES as $table) {
                if ($tables->hasTable($table)) {
                    $context[$table] = $tables->all($table);
                    $tables->dropTable($table);
                }
            }
            $sharedDimensions = $this->sharedDimensions($zip, $manifest, $bytes);
            $supplierId = self::id($manifest['supplier']['id'] ?? null);
            if ($tables->count('supplier') !== 1 || !$tables->has('supplier', $supplierId)) {
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
            if ($progress !== null && $available !== []) {
                $progress('Čtení exportu: přílohy');
            }
            foreach (['invoices' => ['imported_pdf_path' => 'invoices-imported'],
                'purchase_invoices' => ['pdf_path' => 'purchase-invoices', 'source_path' => 'purchase-invoices']] as $table => $columns) {
                foreach ($tables->rows($table) as $id => $row) {
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
                        $file = $this->extract($zip, $asset['entry'], $tables);
                        $hashColumn = match ($column) {
                            'pdf_path' => 'pdf_hash', 'source_path' => 'source_hash', 'imported_pdf_path' => 'imported_pdf_hash'
                        };
                        if (
                            !empty($row[$hashColumn])
                            && !hash_equals((string) $row[$hashColumn], $file['sha256'])
                        ) {
                            throw new RuntimeException('Originál dokladu nesouhlasí s otiskem v datech.');
                        }
                        $assets[] = ['table' => $table, 'id' => $id, 'column' => $column, 'area' => $area,
                            'storage_path' => $storage, 'file' => $file['file'], 'size' => $file['size'], 'sha256' => $file['sha256']];
                    }
                }
            }
            foreach ($manifest['restore']['blobs'] ?? [] as $asset) {
                if (($asset['table'] ?? null) !== 'bank_statements' || !in_array($asset['column'] ?? null, ['file_content', 'pdf_content'], true)
                    || !isset($manifest['checksums'][$asset['entry'] ?? ''])) {
                    throw new RuntimeException('Neplatná binární příloha výpisu.');
                }
                $id = self::id($asset['id'] ?? null);
                $row = $tables->get('bank_statements', $id) ?? throw new RuntimeException('Příloha výpisu nemá zdrojový řádek.');
                $row[$asset['column']] = $this->content($zip, $asset['entry']);
                $tables->put('bank_statements', $id, $row);
                unset($row);
            }
            [$stored, $missing] = $this->storedFiles($zip, $tables, $available, $supplierId);
            $package = ['manifest' => $manifest, 'tables' => $tables, 'skipped' => $skipped, 'assets' => $assets, 'context' => $context];
            if ($sharedDimensions !== []) {
                $package['shared_dimensions'] = $sharedDimensions;
            }
            if ($stored !== [] || $missing !== []) {
                $package['stored_files'] = $stored;
                $package['missing_files'] = $missing;
            }
            return $package;
        } finally {
            $zip->close();
        }
    }

    /**
     * Soubory modulu Dokumenty a archivu PDF vydaných faktur. Aplikace je hledá podle
     * firmy a názvu souboru (dokument podle otisku, archiv podle data v názvu), takže se
     * obnoví na stejnou relativní cestu pod cílovou firmou. Obsah musí sedět na otisk
     * v datech; soubor, který export nenese (ve zdroji chyběl), obnova vykáže.
     *
     * @param array<string,array<string,mixed>> $available zdrojová cesta => položka manifestu
     * @return array{0:list<array{area:string,path:string,sha256:string,file:string,size:int}>,1:array<string,int>}
     */
    private function storedFiles(ZipArchive $zip, MyuctoRowStore $tables, array $available, int $supplierId): array
    {
        $wanted = [];
        $missing = [];
        foreach (['documents', 'document_files'] as $table) {
            foreach ($tables->rows($table) as $row) {
                $sha = $row['sha256'] ?? null;
                $name = $row['filename'] ?? null;
                if (!is_string($sha) || !preg_match('/\A[0-9a-f]{64}\z/D', $sha) || !is_string($name)
                    || str_contains($name, '/') || !self::safePath($name)) {
                    throw new RuntimeException('Neplatný soubor dokumentu v exportu: ' . $table . '.');
                }
                $path = substr($sha, 0, 2) . '/' . $name;
                if (isset($available['documents/sup-' . $supplierId . '/' . $path])) {
                    $wanted['documents/sup-' . $supplierId . '/' . $path] = ['documents', $path, $sha];
                } else {
                    $missing[$table] = ($missing[$table] ?? 0) + 1;
                }
            }
        }
        $archivePrefix = 'invoices/sup-' . $supplierId . '/_archive/';
        $archive = [];
        foreach (array_keys($available) as $storage) {
            if (str_starts_with($storage, $archivePrefix)) {
                $name = basename($storage);
                if (isset($archive[$name])) {
                    throw new RuntimeException('Archiv vydaných faktur obsahuje dvakrát týž soubor: ' . $name . '.');
                }
                $archive[$name] = $storage;
            }
        }
        foreach ($tables->rows('invoice_pdfs') as $row) {
            $name = $row['filename'] ?? null;
            $sha = $row['sha256'] ?? null;
            if (!is_string($name) || str_contains($name, '/') || !self::safePath($name) || !is_string($sha)) {
                throw new RuntimeException('Neplatný soubor archivu vydané faktury v exportu.');
            }
            $storage = $archive[$name] ?? null;
            if ($storage === null) {
                $missing['invoice_pdfs'] = ($missing['invoice_pdfs'] ?? 0) + 1;
                continue;
            }
            $wanted[$storage] = ['invoices', substr($storage, strlen('invoices/sup-' . $supplierId . '/')), $sha];
        }
        $stored = [];
        foreach ($wanted as $storage => [$area, $path, $sha]) {
            $file = $this->extract($zip, $available[$storage]['entry'], $tables);
            if (!hash_equals(strtolower($sha), $file['sha256'])) {
                throw new RuntimeException('Soubor exportu nesouhlasí s otiskem v datech: ' . $storage . '.');
            }
            $stored[] = ['area' => $area, 'path' => $path, 'sha256' => $file['sha256'], 'file' => $file['file'], 'size' => $file['size']];
        }
        ksort($missing);

        return [$stored, $missing];
    }

    /**
     * Sdílené typy a hodnoty dimenzí skupiny firem, na které data firmy odkazují
     * (sekce `shared_dimensions` manifestu). Nejsou to řádky k převodu: obnova je
     * v cíli dohledá ({@see MyuctoImporter}), proto musí patřit skupině, ne firmě.
     *
     * @return array<string,array<int,array<string,mixed>>> tabulka => id => řádek
     */
    private function sharedDimensions(ZipArchive $zip, array $manifest, int &$bytes): array
    {
        $section = $manifest['sections']['data']['shared_dimensions'] ?? [];
        if (!is_array($section)) {
            throw new RuntimeException('Neplatná sekce sdílených dimenzí exportu.');
        }
        $out = [];
        foreach ($section as $table => $info) {
            if (!in_array($table, ['dimension_types', 'dimension_values'], true) || !is_array($info)
                || !is_int($info['rows'] ?? null) || !is_string($info['entry'] ?? null)
                || !isset($manifest['checksums'][$info['entry']]) || !self::safePath($info['entry'])) {
                throw new RuntimeException('Neplatná sekce sdílených dimenzí exportu.');
            }
            $out[$table] = [];
            foreach ($this->lines($zip, $info['entry']) as $line) {
                $bytes += strlen($line);
                if ($bytes > $this->maxDataBytes || count($out[$table]) >= $info['rows']) {
                    throw new RuntimeException('Počet sdílených dimenzí exportu nesedí.');
                }
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($row) || array_is_list($row) || ($row['supplier_id'] ?? null) !== null
                    || ($row['supplier_group_id'] ?? null) === null || !is_string($row['code'] ?? null)) {
                    throw new RuntimeException('Sdílená dimenze exportu nepatří skupině firem.');
                }
                foreach ($row as $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new RuntimeException('Neplatná hodnota sdílené dimenze exportu.');
                    }
                }
                $id = self::id($row['id'] ?? null);
                if (isset($out[$table][$id])) {
                    throw new RuntimeException('Duplicitní ID sdílené dimenze exportu.');
                }
                $out[$table][$id] = $row;
            }
            if (count($out[$table]) !== $info['rows']) {
                throw new RuntimeException('Počet sdílených dimenzí exportu nesedí.');
            }
        }
        foreach ($out['dimension_values'] ?? [] as $row) {
            if (!isset($out['dimension_types'][self::id($row['type_id'] ?? null)])) {
                throw new RuntimeException('Sdílená hodnota dimenze exportu nemá svůj typ.');
            }
        }
        return array_filter($out);
    }

    /**
     * Řádky JSONL položky jeden po druhém (bez prázdných řádků). Řádek nad limit délky
     * se odmítne dřív, než se celý načte.
     *
     * @return \Generator<int,string>
     */
    private function lines(ZipArchive $zip, string $entry): \Generator
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            throw new RuntimeException('Data tabulky nelze přečíst.');
        }
        try {
            // stream_get_line() alokuje jen skutečnou délku řádku; fgets() s limitem by pro
            // každý řádek alokoval celý limit.
            while (($line = stream_get_line($stream, $this->maxLineBytes + 1, "\n")) !== false) {
                if (strlen($line) > $this->maxLineBytes) {
                    throw new RuntimeException('Řádek exportu překračuje limit ' . self::formatBytes($this->maxLineBytes) . '.');
                }
                if (trim($line) === '') {
                    continue;
                }
                yield $line;
            }
            if (!feof($stream)) {
                throw new RuntimeException('Čtení tabulky exportu selhalo.');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Rozbalí přílohu do dočasného souboru úložiště a spočítá její otisk; obsah přílohy
     * se nikdy nedrží v paměti celý.
     *
     * @return array{file:string,size:int,sha256:string}
     */
    private function extract(ZipArchive $zip, string $entry, MyuctoRowStore $store): array
    {
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > $this->maxFileBytes) {
            throw new RuntimeException('Příloha exportu překračuje limit ' . self::formatBytes($this->maxFileBytes) . '.');
        }
        $source = $zip->getStream($entry);
        if ($source === false) {
            throw new RuntimeException('Přílohu exportu nelze přečíst.');
        }
        $path = $store->tempFile();
        $target = @fopen($path, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Přílohu exportu nelze dočasně uložit.');
        }
        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($source)) {
                $chunk = fread($source, 1_048_576);
                if ($chunk === false) {
                    throw new RuntimeException('Přílohu exportu nelze přečíst.');
                }
                $size += strlen($chunk);
                if ($size > $stat['size']) {
                    throw new RuntimeException('Příloha exportu je větší, než deklaruje ZIP.');
                }
                hash_update($hash, $chunk);
                if ($chunk !== '' && fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Přílohu exportu nelze dočasně uložit.');
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }
        if ($size !== $stat['size']) {
            throw new RuntimeException('Přílohu exportu nelze přečíst celou.');
        }
        return ['file' => $path, 'size' => $size, 'sha256' => hash_final($hash)];
    }

    /** Binární obsah výpisu patří do řádku databáze, proto se čte do paměti (s limitem velikosti). */
    private function content(ZipArchive $zip, string $entry): string
    {
        $stat = $zip->statName($entry);
        if ($stat === false || $stat['size'] > $this->maxFileBytes) {
            throw new RuntimeException('Příloha exportu překračuje limit ' . self::formatBytes($this->maxFileBytes) . '.');
        }
        $content = $zip->getFromName($entry, $stat['size']);
        if ($content === false || strlen($content) !== $stat['size']) {
            throw new RuntimeException('Přílohu exportu nelze přečíst celou.');
        }
        return $content;
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1) . ' GiB' : round($bytes / 1024 ** 2, 1) . ' MiB';
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
