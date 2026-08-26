<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

require dirname(__DIR__) . '/api/vendor/autoload.php';

final class JmhzOfficialExamplePackageBuilder
{
    private const ARCHIVE_FILENAME = 'Příklady XML - REGZEC_REGZEL-DOPL_JMHZ_DZMH_2026-04-13.zip';
    private const ARCHIVE_SHA256 = 'd31c89be8e2f0e4e93b20edd0beda05030e48884aa45dbfb4db0ee88e313a507';
    private const ARCHIVE_LENGTH = 76416;
    private const ENTRY_COUNT = 40;
    private const XML_COUNT = 35;
    private const UNCOMPRESSED_BYTES = 286380;
    private const SPEC_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
    private const SPEC_MANIFEST_SHA256 =
        '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205';
    private const XSD_INVENTORY_SHA256 =
        '72285ef5c8924d55041d54b075cbcc135a3229e6954fe81a25ef773a8d12215c';

    /** @var array<string, array{target_key:string,agenda:string,version:string,entrypoint:string,entrypoint_sha256:string,official_xsd_archive_sha256:string}> */
    private const TARGETS = [
        'http://schemas.cssz.cz/JMHZ/dotazNaStav/2025|DZMH' => [
            'target_key' => 'dzmh-1.1',
            'agenda' => 'dzmh',
            'version' => '1.1',
            'entrypoint' => 'dzmh-1.1/DZMH25.xsd',
            'entrypoint_sha256' => 'b0959ab50cf627a57abd7f8f78681848b6df7ac90d3e6ced1eb15be2bc7946d1',
            'official_xsd_archive_sha256' => '1e89ec55b56b3e00f3f6a066e92bf3e39d29b05a5e2f0f8c7be95ead65111d06',
        ],
        'http://schemas.cssz.cz/JMHZ/podani/1.0|jmhz' => [
            'target_key' => 'jmhz-1.4.3.4',
            'agenda' => 'jmhz',
            'version' => '1.4.3.4',
            'entrypoint' => 'jmhz-1.4.3.4/jmhzPodani.xsd',
            'entrypoint_sha256' => 'c602cdf018dc6a0c4379000e004f2c4609d7313d265defa323e70663efc66216',
            'official_xsd_archive_sha256' => 'f189885ad637c4343b4b7ce195f13fd4f6f8b87f5b5b94c5c74fe85a9df0ee9d',
        ],
        'http://schemas.cssz.cz/REGZEC/2025|REGZEC' => [
            'target_key' => 'regzec-1.4.0.4',
            'agenda' => 'regzec',
            'version' => '1.4.0.4',
            'entrypoint' => 'regzec-1.4.0.4/REGZEC25.xsd',
            'entrypoint_sha256' => 'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
            'official_xsd_archive_sha256' => '0d0396fd857a6602b01a3ecf234fe02da96f00f316eea34de6e67b06e4cc2b1f',
        ],
        'http://schemas.cssz.cz/REGZELDOPL/2025|REGZELDOPL' => [
            'target_key' => 'regzeldopl-1.2',
            'agenda' => 'regzeldopl',
            'version' => '1.2',
            'entrypoint' => 'regzeldopl-1.2/REGZELDOPL25.xsd',
            'entrypoint_sha256' => '566a124a708492d783a75296eb37a4f76a49ccc5d0aa7d5119a0fa02eee6eedf',
            'official_xsd_archive_sha256' => '6f0eb190573336d3250130206a34d84fa228c7bc9fec2f0dd9176cb29e120dd3',
        ],
    ];

    public function build(string $archivePath, string $decisionsPath, string $xsdRoot, string $outputPath): void
    {
        $this->verifySource($archivePath, $xsdRoot);
        $decisions = $this->decisions($decisionsPath);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Oficiální archiv XML příkladů nelze otevřít.');
        }

        try {
            [$entries, $examples] = $this->inspectArchive($zip, $decisions, $xsdRoot);
        } finally {
            $zip->close();
        }

        if ($decisions !== []) {
            throw new RuntimeException('Rozhodnutí obsahují přebývající XML příklady.');
        }

        $classifications = array_count_values(array_column($examples, 'classification'));
        $validations = array_count_values(array_column($examples, 'xsd_validation'));
        $agendas = array_count_values(array_column($examples, 'agenda'));
        $payload = [
            'schema_version' => 'jmhz-official-xml-example-source-catalog.v1',
            'catalog_key' => 'jmhz-official-xml-examples-2026-04-13-source-v1',
            'spec_package_key' => self::SPEC_PACKAGE_KEY,
            'spec_manifest_sha256' => self::SPEC_MANIFEST_SHA256,
            'xsd_inventory_sha256' => self::XSD_INVENTORY_SHA256,
            'usage_policy' => 'source_evidence_only',
            'privacy_policy' => 'xml_bytes_external_not_redistributed',
            'source_archive' => [
                'original_filename' => self::ARCHIVE_FILENAME,
                'availability' => 'external_evidence_only',
                'byte_length' => self::ARCHIVE_LENGTH,
                'sha256' => self::ARCHIVE_SHA256,
                'zip_entry_count' => self::ENTRY_COUNT,
                'xml_entry_count' => self::XML_COUNT,
                'total_uncompressed_bytes' => self::UNCOMPRESSED_BYTES,
            ],
            'xsd_targets' => array_values(self::TARGETS),
            'archive_entries' => $entries,
            'examples' => $examples,
            'counts' => [
                'archive_entries' => count($entries),
                'xml_examples' => count($examples),
                'well_formed' => count(array_filter($examples, static fn (array $row): bool => $row['well_formed'])),
                'xsd_pass' => $validations['pass'] ?? 0,
                'xsd_fail' => $validations['fail'] ?? 0,
                'valid_against_pinned_xsd' => $classifications['valid_against_pinned_xsd'] ?? 0,
                'different_version' => $classifications['different_version'] ?? 0,
                'fragment' => $classifications['fragment'] ?? 0,
                'intentionally_invalid' => $classifications['intentionally_invalid'] ?? 0,
                'unresolved' => $classifications['unresolved'] ?? 0,
                'dzmh' => $agendas['dzmh'] ?? 0,
                'jmhz' => $agendas['jmhz'] ?? 0,
                'regzec' => $agendas['regzec'] ?? 0,
                'regzeldopl' => $agendas['regzeldopl'] ?? 0,
            ],
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        if (file_put_contents($outputPath, $json) === false) {
            throw new RuntimeException('Manifest oficiálních XML příkladů nelze zapsat.');
        }
    }

    private function verifySource(string $archivePath, string $xsdRoot): void
    {
        if (hash_file('sha256', $archivePath) !== self::ARCHIVE_SHA256
            || filesize($archivePath) !== self::ARCHIVE_LENGTH
        ) {
            throw new RuntimeException('Oficiální archiv XML příkladů nemá očekávané bajty.');
        }
        if (hash_file('sha256', $xsdRoot . '/SHA256SUMS') !== self::XSD_INVENTORY_SHA256) {
            throw new RuntimeException('Inventář připnutých JMHZ XSD nemá očekávané bajty.');
        }
        $this->verifyXsdInventory($xsdRoot);
        foreach (self::TARGETS as $target) {
            if (hash_file('sha256', $xsdRoot . '/' . $target['entrypoint']) !== $target['entrypoint_sha256']) {
                throw new RuntimeException("Vstupní XSD {$target['target_key']} nemá očekávané bajty.");
            }
        }
    }

    private function verifyXsdInventory(string $xsdRoot): void
    {
        $lines = file($xsdRoot . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException('Inventář připnutých JMHZ XSD nelze načíst.');
        }
        $expected = [];
        foreach ($lines as $line) {
            if (preg_match('/\A([a-f0-9]{64})  ([^\r\n]+\.xsd)\z/D', $line, $matches) !== 1) {
                throw new RuntimeException('Inventář připnutých JMHZ XSD má neplatný řádek.');
            }
            $relative = $matches[2];
            $this->assertSafePath($relative);
            if (isset($expected[$relative])) {
                throw new RuntimeException('Inventář připnutých JMHZ XSD má duplicitní cestu.');
            }
            $expected[$relative] = $matches[1];
        }

        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($xsdRoot, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'xsd') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($xsdRoot) + 1));
            $actual[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new RuntimeException('Bajty připnutých JMHZ XSD neodpovídají úplnému inventáři.');
        }
    }

    /**
     * @return array<string, array{classification:string,reason_code:string,decision_evidence:list<string>,blocking_reasons:list<string>}>
     */
    private function decisions(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 'jmhz-official-xml-example-decisions.v1'
            || ($decoded['archive_sha256'] ?? null) !== self::ARCHIVE_SHA256
            || !is_array($decoded['decisions'] ?? null)
        ) {
            throw new RuntimeException('Rozhodnutí klasifikace XML mají neplatnou hlavičku.');
        }
        $result = [];
        foreach ($decoded['decisions'] as $row) {
            if (!is_array($row) || !is_string($row['sha256'] ?? null) || isset($result[$row['sha256']])) {
                throw new RuntimeException('Rozhodnutí klasifikace XML obsahují neplatný nebo duplicitní hash.');
            }
            $classification = $row['classification'] ?? null;
            $reason = $row['reason_code'] ?? null;
            $evidence = $row['decision_evidence'] ?? null;
            $blocking = $row['blocking_reasons'] ?? null;
            if (!is_string($classification) || !is_string($reason)
                || !is_array($evidence) || !is_array($blocking)
                || array_filter($evidence, static fn (mixed $value): bool => !is_string($value)) !== []
                || array_filter($blocking, static fn (mixed $value): bool => !is_string($value)) !== []
            ) {
                throw new RuntimeException('Rozhodnutí klasifikace XML má neplatná pole.');
            }
            $result[$row['sha256']] = [
                'classification' => $classification,
                'reason_code' => $reason,
                'decision_evidence' => array_values($evidence),
                'blocking_reasons' => array_values($blocking),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array{classification:string,reason_code:string,decision_evidence:list<string>,blocking_reasons:list<string>}> $decisions
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function inspectArchive(ZipArchive $zip, array &$decisions, string $xsdRoot): array
    {
        if ($zip->numFiles !== self::ENTRY_COUNT) {
            throw new RuntimeException('Oficiální archiv má neočekávaný počet položek.');
        }
        $entries = [];
        $examples = [];
        $paths = [];
        $casefoldPaths = [];
        $uncompressedBytes = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)) {
                throw new RuntimeException("ZIP položku {$index} nelze načíst.");
            }
            $path = $stat['name'];
            $this->assertSafePath($path);
            $casefold = mb_strtolower($path, 'UTF-8');
            if (isset($paths[$path]) || isset($casefoldPaths[$casefold])) {
                throw new RuntimeException('ZIP obsahuje duplicitní cestu.');
            }
            $paths[$path] = true;
            $casefoldPaths[$casefold] = true;
            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new RuntimeException('ZIP obsahuje šifrovanou položku.');
            }
            $directory = str_ends_with($path, '/');
            $size = $this->intStat($stat, 'size');
            $compressedSize = $this->intStat($stat, 'comp_size');
            $uncompressedBytes += $size;
            if ($uncompressedBytes > self::UNCOMPRESSED_BYTES) {
                throw new RuntimeException('ZIP překračuje připnutou rozbalenou velikost.');
            }
            $entry = [
                'entry_index' => $index,
                'entry_path_raw' => $path,
                'entry_path_utf8_sha256' => hash('sha256', $path),
                'entry_kind' => $directory ? 'directory' : 'xml',
                'compression_method' => $this->intStat($stat, 'comp_method'),
                'crc32' => sprintf('%08x', $this->intStat($stat, 'crc')),
                'compressed_size' => $compressedSize,
                'byte_length' => $size,
                'sha256' => null,
            ];
            if (!$directory) {
                if (!str_ends_with(mb_strtolower($path, 'UTF-8'), '.xml')) {
                    throw new RuntimeException('ZIP obsahuje neočekávaný soubor.');
                }
                $bytes = $zip->getFromIndex($index, 0, ZipArchive::FL_UNCHANGED);
                if (!is_string($bytes) || strlen($bytes) !== $size) {
                    throw new RuntimeException("XML položku {$index} nelze přesně načíst.");
                }
                $sha256 = hash('sha256', $bytes);
                $entry['sha256'] = $sha256;
                $decision = $decisions[$sha256] ?? throw new RuntimeException(
                    "XML položka {$index} nemá klasifikační rozhodnutí.",
                );
                unset($decisions[$sha256]);
                $examples[] = $this->example($index, $path, $bytes, $sha256, $decision, $xsdRoot);
            }
            $entries[] = $entry;
        }
        if ($uncompressedBytes !== self::UNCOMPRESSED_BYTES || count($examples) !== self::XML_COUNT) {
            throw new RuntimeException('Obsah ZIP neodpovídá připnutým souhrnům.');
        }

        return [$entries, $examples];
    }

    /**
     * @param array{classification:string,reason_code:string,decision_evidence:list<string>,blocking_reasons:list<string>} $decision
     * @return array<string, mixed>
     */
    private function example(
        int $index,
        string $path,
        string $bytes,
        string $sha256,
        array $decision,
        string $xsdRoot,
    ): array {
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) {
            throw new RuntimeException("XML položka {$index} obsahuje zakázanou DTD nebo entitu.");
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_COMPACT);
        $loadErrors = libxml_get_errors();
        libxml_clear_errors();
        if (!$loaded || $loadErrors !== [] || $document->doctype !== null || $document->documentElement === null) {
            libxml_use_internal_errors($previous);
            throw new RuntimeException("XML položka {$index} není bezpečně well-formed.");
        }
        $root = $document->documentElement;
        $target = self::TARGETS[($root->namespaceURI ?? '') . '|' . $root->localName]
            ?? throw new RuntimeException("XML položka {$index} nemá připnutý XSD target.");
        $valid = @$document->schemaValidate($xsdRoot . '/' . $target['entrypoint']);
        $validationErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $validation = $valid ? 'pass' : 'fail';
        $this->assertDecision($decision, $validation, $validationErrors, $index);
        $record = [
            'example_key' => sprintf('example-%03d', $index + 1),
            'archive_entry_index' => $index,
            'entry_path_raw' => $path,
            'entry_path_utf8_sha256' => hash('sha256', $path),
            'byte_length' => strlen($bytes),
            'sha256' => $sha256,
            'agenda' => $target['agenda'],
            'root_local_name' => $root->localName,
            'root_namespace' => $root->namespaceURI ?? '',
            'well_formed' => true,
            'target_key' => $target['target_key'],
            'xsd_validation' => $validation,
            'classification' => $decision['classification'],
            'reason_code' => $decision['reason_code'],
            'decision_evidence' => $decision['decision_evidence'],
            'blocking_reasons' => $decision['blocking_reasons'],
            'usage' => 'source_evidence_only',
        ];
        $record['row_hash'] = hash('sha256', CanonicalJson::encode($record));

        return $record;
    }

    /**
     * @param array{classification:string,reason_code:string,decision_evidence:list<string>,blocking_reasons:list<string>} $decision
     * @param list<LibXMLError> $errors
     */
    private function assertDecision(array $decision, string $validation, array $errors, int $index): void
    {
        $classification = $decision['classification'];
        if ($classification === 'valid_against_pinned_xsd') {
            if ($validation !== 'pass'
                || $decision['reason_code'] !== 'pinned_xsd_validation_passed'
                || $decision['decision_evidence'] !== ['pinned_xsd_validation']
                || $decision['blocking_reasons'] !== []
            ) {
                throw new RuntimeException("XML položka {$index} má neplatné validní rozhodnutí.");
            }
            return;
        }
        if ($classification !== 'unresolved'
            || $validation !== 'fail'
            || $decision['decision_evidence'] !== ['pinned_xsd_validation']
        ) {
            throw new RuntimeException("XML položka {$index} má nepodloženou klasifikaci.");
        }
        $messages = implode("\n", array_map(static fn (LibXMLError $error): string => $error->message, $errors));
        if ($decision['reason_code'] === 'xsd_year_below_pinned_minimum') {
            if ($decision['blocking_reasons'] !== ['source_version_not_established']
                || stripos($messages, 'minInclusive') === false
                || preg_match('/\brok\b/ui', $messages) !== 1
            ) {
                throw new RuntimeException("XML položka {$index} už nepadá na minimálním roku.");
            }
            return;
        }
        if ($decision['reason_code'] === 'placeholder_identifier_rejected_by_pinned_xsd') {
            if ($decision['blocking_reasons'] !== ['publisher_intent_not_established']
                || (stripos($messages, 'pattern') === false && stripos($messages, 'length') === false)
                || preg_match('/\b(?:bno|ikmpsv|vs|oid)\b/ui', $messages) !== 1
            ) {
                throw new RuntimeException("XML položka {$index} už nepadá na placeholderu identifikátoru.");
            }
            return;
        }
        throw new RuntimeException("XML položka {$index} má neznámý důvod klasifikace.");
    }

    private function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) === 1
        ) {
            throw new RuntimeException('ZIP obsahuje nebezpečnou cestu.');
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('ZIP obsahuje nebezpečný segment cesty.');
            }
        }
    }

    /** @param array<string, mixed> $stat */
    private function intStat(array $stat, string $key): int
    {
        $value = $stat[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException("ZIP metadata {$key} nejsou platná.");
        }

        return $value;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];
    if (count($arguments) !== 5) {
        fwrite(STDERR, "Použití: php JmhzOfficialExamplePackageBuilder.php <archive.zip> <decisions.json> <xsd-root> <manifest.json>\n");
        exit(2);
    }
    (new JmhzOfficialExamplePackageBuilder())->build(
        $arguments[1],
        $arguments[2],
        $arguments[3],
        $arguments[4],
    );
}
