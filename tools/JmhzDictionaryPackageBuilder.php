<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require dirname(__DIR__) . '/api/vendor/autoload.php';

final class JmhzDictionaryPackageBuilder
{
    private const DICTIONARY_SHA256 =
        'e794a56d3baa48dd876ad45a0deb5b1bb77c17a0cb44a3511e8ef4028be69743';
    private const CONTROL_CATALOG_SHA256 =
        '8c861badbd6229e9185482b0caaf19d6ded4797b27bf37f8b53dcb3b31151b49';
    private const CODEBOOK_ALIASES = [
        'klasifikace_zamestnani' => 'klasifikace_v_zamestnani',
        'klasifikace_postaveni_v_zamestnani' => 'klasifikace_v_zamestnani',
        'duvod_ukonceni_pracovnepravniho_vztahu' => 'duvod_ukonceni_ppv',
        'duvod_ukonceni_sluzebniho_pomeru' => 'duvod_ukonceni_sluz_pomeru',
    ];

    public function build(string $dictionaryPath, string $controlCatalogPath, string $outputDirectory): void
    {
        $this->assertSource($dictionaryPath, self::DICTIONARY_SHA256);
        $this->assertSource($controlCatalogPath, self::CONTROL_CATALOG_SHA256);

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($dictionaryPath);

        $codebooks = [];
        foreach ($workbook->getWorksheetIterator() as $worksheet) {
            if (str_starts_with($worksheet->getTitle(), 'CIS ')) {
                $codebooks[] = $this->codebook($worksheet);
            }
        }
        usort($codebooks, static fn (array $a, array $b): int => $a['codebook_key'] <=> $b['codebook_key']);
        if (count(array_unique(array_column($codebooks, 'codebook_key'))) !== count($codebooks)) {
            throw new RuntimeException('Stabilní klíče číselníků nejsou unikátní.');
        }
        $codebookAliases = [];
        foreach ($codebooks as $codebook) {
            $codebookAliases[$this->stableKey($codebook['source_name'])] = $codebook['codebook_key'];
        }
        $attributes = $this->attributes(
            $workbook->getSheetByName('SLOVNÍK')
                ?? throw new RuntimeException('Datový slovník neobsahuje list SLOVNÍK.'),
            $codebookAliases,
        );

        $packageKey = 'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
        $payload = [
            'schema_version' => 'jmhz-spec-package.v1',
            'package_key' => $packageKey,
            'versions' => [
                'xsd' => '1.4.3.4',
                'dictionary' => '1.4.1.6',
                'control_catalog' => '1.4.2.8',
                'process' => '1.4.0.2',
                'instructions' => '1.4.13',
            ],
            'sources' => [
                [
                    'role' => 'dictionary',
                    'filename' => basename($dictionaryPath),
                    'sha256' => self::DICTIONARY_SHA256,
                ],
                [
                    'role' => 'control_catalog_source',
                    'filename' => basename($controlCatalogPath),
                    'sha256' => self::CONTROL_CATALOG_SHA256,
                ],
            ],
            'counts' => [
                'attributes' => count($attributes),
                'reporting_marker_attributes' => count(array_filter(
                    $attributes,
                    static fn (array $row): bool => $row['employer_registration_marker'] !== null
                        || $row['employee_registration_marker'] !== null
                        || $row['monthly_marker'] !== null,
                )),
                'reporting_marker_xsd_mapped_attributes' => count(array_filter(
                    $attributes,
                    static fn (array $row): bool => ($row['employer_registration_marker'] !== null
                            || $row['employee_registration_marker'] !== null
                            || $row['monthly_marker'] !== null)
                        && ($row['regzec_xsd_mapping'] !== null || $row['xsd_mapping'] !== null),
                )),
                'monthly_attributes' => count(array_filter(
                    $attributes,
                    static fn (array $row): bool => $row['monthly_marker'] !== null,
                )),
                'monthly_xsd_mapped_attributes' => count(array_filter(
                    $attributes,
                    static fn (array $row): bool => $row['monthly_marker'] !== null
                        && $row['xsd_mapping'] !== null,
                )),
                'codebooks' => count($codebooks),
                'embedded_codebooks' => count(array_filter(
                    $codebooks,
                    static fn (array $row): bool => $row['source_kind'] === 'embedded',
                )),
                'external_reference_codebooks' => count(array_filter(
                    $codebooks,
                    static fn (array $row): bool => $row['source_kind'] === 'external_reference',
                )),
                'codebook_entries' => array_sum(array_column($codebooks, 'entry_count')),
            ],
            'dictionary_attributes' => $attributes,
            'codebooks' => $codebooks,
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException("Nelze vytvořit adresář {$outputDirectory}.");
        }
        foreach ([$dictionaryPath, $controlCatalogPath] as $source) {
            $target = $outputDirectory . DIRECTORY_SEPARATOR . basename($source);
            if (!copy($source, $target)) {
                throw new RuntimeException("Nelze připnout zdroj {$source}.");
            }
        }
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        if (file_put_contents($outputDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $json) === false) {
            throw new RuntimeException('Nelze zapsat manifest JMHZ.');
        }
    }

    /**
     * @param array<string, string> $codebookAliases
     * @return list<array<string, mixed>>
     */
    private function attributes(Worksheet $sheet, array $codebookAliases): array
    {
        $result = [];
        $seen = [];
        for ($row = 3; $row <= $sheet->getHighestDataRow(); ++$row) {
            $attributeId = $this->text($sheet, 'A', $row);
            if ($attributeId === null) {
                continue;
            }
            if (isset($seen[$attributeId])) {
                throw new RuntimeException("Duplicitní atribut {$attributeId}.");
            }
            $seen[$attributeId] = true;
            $rawCodebook = $this->text($sheet, 'L', $row);
            $codebookKey = $rawCodebook === null
                ? null
                : $this->resolveCodebookKey($rawCodebook, $codebookAliases);
            if ($rawCodebook !== null && $codebookKey === null) {
                throw new RuntimeException(
                    "Atribut {$attributeId} odkazuje na neznámý číselník {$rawCodebook}.",
                );
            }
            $record = [
                'attribute_id' => $attributeId,
                'name' => $this->requiredText($sheet, 'B', $row),
                'area' => $this->text($sheet, 'C', $row),
                'class_name' => $this->text($sheet, 'D', $row),
                'subclass_name' => $this->text($sheet, 'E', $row),
                'data_type' => $this->text($sheet, 'F', $row),
                'data_type_refinement' => $this->text($sheet, 'G', $row),
                'cardinality' => $this->text($sheet, 'H', $row),
                'regzec_xsd_mapping' => $this->text($sheet, 'I', $row),
                'xsd_mapping' => $this->text($sheet, 'J', $row),
                'codebook_key' => $codebookKey,
                'employer_registration_marker' => $this->text($sheet, 'P', $row),
                'employee_registration_marker' => $this->text($sheet, 'Q', $row),
                'monthly_marker' => $this->text($sheet, 'R', $row),
            ];
            $record['row_hash'] = hash('sha256', CanonicalJson::encode($record));
            $result[] = $record;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function codebook(Worksheet $sheet): array
    {
        $metadata = [];
        for ($row = 1; $row <= min(20, $sheet->getHighestDataRow()); ++$row) {
            $label = $this->text($sheet, 'D', $row);
            $value = $this->text($sheet, 'E', $row);
            if ($label !== null && $value !== null) {
                $key = $this->metadataKey($label);
                $hyperlink = $sheet->getCell("E{$row}")->getHyperlink()->getUrl();
                $metadataValue = $key === 'url' && $hyperlink !== '' ? $hyperlink : $value;
                $metadata[$key] = $key === 'url'
                    && filter_var($metadataValue, FILTER_VALIDATE_URL) === false
                    ? null
                    : $metadataValue;
            }
        }

        $entries = [];
        $seen = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); ++$row) {
            $code = trim((string) $sheet->getCell("A{$row}")->getFormattedValue());
            $label = $this->text($sheet, 'B', $row);
            if ($code === '' || $label === null) {
                continue;
            }
            if (isset($seen[$code])) {
                throw new RuntimeException("Duplicitní kód {$code} na listu {$sheet->getTitle()}.");
            }
            $seen[$code] = true;
            $entry = [
                'item_code' => $code,
                'label' => $label,
                'parent_code' => null,
                'ordinal' => count($entries) + 1,
                'metadata' => [],
            ];
            $entry['row_hash'] = hash('sha256', CanonicalJson::encode($entry));
            $entries[] = $entry;
        }

        $sourceKind = $entries === [] ? 'external_reference' : 'embedded';
        $record = [
            'codebook_key' => $this->stableKey($sheet->getTitle()),
            'source_kind' => $sourceKind,
            'source_name' => $sheet->getTitle(),
            'source_url' => $metadata['url'] ?? null,
            'source_metadata' => $metadata,
            'entry_count' => count($entries),
            'content_hash' => hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            'entries' => $entries,
        ];

        return $record;
    }

    private function assertSource(string $path, string $expectedHash): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Chybí zdroj {$path}.");
        }
        $actualHash = hash_file('sha256', $path);
        if ($actualHash !== $expectedHash) {
            throw new RuntimeException("Zdroj {$path} nemá očekávaný SHA-256.");
        }
    }

    private function requiredText(Worksheet $sheet, string $column, int $row): string
    {
        return $this->text($sheet, $column, $row)
            ?? throw new RuntimeException("Chybí povinná hodnota {$sheet->getTitle()}!{$column}{$row}.");
    }

    private function text(Worksheet $sheet, string $column, int $row): ?string
    {
        Coordinate::columnIndexFromString($column);
        $value = $sheet->getCell("{$column}{$row}")->getValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function metadataKey(string $value): string
    {
        $value = mb_strtolower(rtrim(trim($value), ':'));
        $map = ['zdroj' => 'source', 'popis' => 'description', 'url' => 'url', 'poznámka' => 'note'];

        return $map[$value] ?? $this->asciiKey($value);
    }

    private function stableKey(string $title): string
    {
        return $this->asciiKey(preg_replace('/^CIS\s+/u', '', $title) ?? $title);
    }

    /** @param array<string, string> $aliases */
    private function resolveCodebookKey(string $rawCodebook, array $aliases): ?string
    {
        $normalized = $this->stableKey($rawCodebook);
        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }
        if (isset(self::CODEBOOK_ALIASES[$normalized])) {
            return self::CODEBOOK_ALIASES[$normalized];
        }
        $comparable = static fn (string $value): string => preg_replace(
            '/_(?:a|v|ve|pro|do|na|z)_/',
            '_',
            "_{$value}_",
        ) ?? $value;
        $matches = [];
        foreach ($aliases as $alias => $key) {
            if (str_starts_with($normalized, $alias) || str_starts_with($alias, $normalized)
                || $comparable($normalized) === $comparable($alias)
            ) {
                $matches[$key] = true;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    private function asciiKey(string $value): string
    {
        $value = strtr(mb_strtolower($value), [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Builder JMHZ je určen pouze pro CLI.');
}
if ($argc !== 4) {
    fwrite(STDERR, "Použití: php tools/JmhzDictionaryPackageBuilder.php <slovník.xlsx> <kontroly.xlsx> <výstup>\n");
    exit(2);
}

(new JmhzDictionaryPackageBuilder())->build($argv[1], $argv[2], $argv[3]);
fwrite(STDOUT, "Balík JMHZ byl vytvořen.\n");
