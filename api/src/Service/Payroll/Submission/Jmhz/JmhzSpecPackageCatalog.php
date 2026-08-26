<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzSpecPackageCatalog
{
    public const DEFAULT_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
    public const DEFAULT_MANIFEST_SHA256 =
        '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205';

    private const PACKAGE_DIRECTORIES = [
        self::DEFAULT_PACKAGE_KEY => 'dictionary-1.4.1.6',
    ];

    public function __construct(private readonly ?string $resourceRoot = null) {}

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    public function load(string $packageKey, ?string $expectedManifestHash = null): array
    {
        $directory = self::PACKAGE_DIRECTORIES[$packageKey] ?? null;
        if ($directory === null) {
            throw new \OutOfBoundsException("Neznámý balík specifikace JMHZ {$packageKey}.");
        }
        $root = $this->resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $packageDirectory = $root . DIRECTORY_SEPARATOR . $directory;
        $manifestPath = $packageDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException("Nelze načíst manifest balíku JMHZ {$packageKey}.");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ nemá očekávanou strukturu.');
        }
        self::validateManifest($decoded);
        $payload = $decoded['payload'];
        if (($payload['package_key'] ?? null) !== $packageKey) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ má jiný package_key.');
        }
        $actualHash = $decoded['manifest_sha256'];
        if ($expectedManifestHash !== null && !hash_equals($expectedManifestHash, $actualHash)) {
            throw new \UnexpectedValueException('Balík JMHZ neodpovídá požadovanému SHA-256.');
        }
        $this->verifySources($packageDirectory, $payload);

        return ['manifest_sha256' => $actualHash, 'payload' => $payload];
    }

    /** @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest */
    public static function validateManifest(array $manifest): void
    {
        $actualHash = hash('sha256', CanonicalJson::encode($manifest['payload']));
        if (!hash_equals($manifest['manifest_sha256'], $actualHash)) {
            throw new \UnexpectedValueException('Manifest balíku JMHZ neodpovídá svému SHA-256.');
        }
        self::verifyContent($manifest['payload']);
    }

    /** @param array<string, mixed> $payload */
    private function verifySources(string $packageDirectory, array $payload): void
    {
        foreach (self::list($payload['sources'] ?? null, 'sources') as $source) {
            $filename = self::string($source, 'filename');
            if (basename($filename) !== $filename) {
                throw new \UnexpectedValueException('Zdroj balíku JMHZ obsahuje neplatnou cestu.');
            }
            $actual = hash_file('sha256', $packageDirectory . DIRECTORY_SEPARATOR . $filename);
            if (!is_string($actual) || !hash_equals(self::string($source, 'sha256'), $actual)) {
                throw new \UnexpectedValueException("Zdroj {$filename} neodpovídá manifestu JMHZ.");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function verifyContent(array $payload): void
    {
        $attributes = self::list($payload['dictionary_attributes'] ?? null, 'dictionary_attributes');
        $codebooks = self::list($payload['codebooks'] ?? null, 'codebooks');
        $attributeIds = [];
        foreach ($attributes as $attribute) {
            $id = self::string($attribute, 'attribute_id');
            if (isset($attributeIds[$id])) {
                throw new \UnexpectedValueException("Duplicitní atribut JMHZ {$id}.");
            }
            $attributeIds[$id] = true;
            $rowHash = self::string($attribute, 'row_hash');
            $withoutHash = $attribute;
            unset($withoutHash['row_hash']);
            if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                throw new \UnexpectedValueException("Atribut JMHZ {$id} má neplatný hash.");
            }
        }

        $codebookKeys = [];
        $entryCount = 0;
        $embeddedCodebooks = 0;
        $externalCodebooks = 0;
        foreach ($codebooks as $codebook) {
            $key = self::string($codebook, 'codebook_key');
            if (isset($codebookKeys[$key])) {
                throw new \UnexpectedValueException("Duplicitní číselník JMHZ {$key}.");
            }
            $codebookKeys[$key] = true;
            $entries = self::list($codebook['entries'] ?? null, "codebooks.{$key}.entries");
            $sourceKind = self::string($codebook, 'source_kind');
            if (!in_array($sourceKind, ['embedded', 'external_reference'], true)) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neznámý druh zdroje.");
            }
            if (($sourceKind === 'embedded') !== ($entries !== [])) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný druh zdroje.");
            }
            $sourceKind === 'embedded' ? ++$embeddedCodebooks : ++$externalCodebooks;
            if (($codebook['entry_count'] ?? null) !== count($entries)) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný počet položek.");
            }
            if (!hash_equals(
                self::string($codebook, 'content_hash'),
                hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            )) {
                throw new \UnexpectedValueException("Číselník JMHZ {$key} má neplatný hash.");
            }
            $codes = [];
            foreach ($entries as $entry) {
                $code = self::string($entry, 'item_code');
                if (isset($codes[$code])) {
                    throw new \UnexpectedValueException("Číselník JMHZ {$key} obsahuje duplicitní kód {$code}.");
                }
                $codes[$code] = true;
                $rowHash = self::string($entry, 'row_hash');
                $withoutHash = $entry;
                unset($withoutHash['row_hash']);
                if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                    throw new \UnexpectedValueException("Položka {$key}/{$code} má neplatný hash.");
                }
            }
            $entryCount += count($entries);
        }
        foreach ($attributes as $attribute) {
            $codebookKey = $attribute['codebook_key'] ?? null;
            if ($codebookKey !== null
                && (!is_string($codebookKey) || !isset($codebookKeys[$codebookKey]))
            ) {
                throw new \UnexpectedValueException(
                    "Atribut JMHZ {$attribute['attribute_id']} odkazuje na neznámý číselník.",
                );
            }
        }

        $counts = $payload['counts'] ?? null;
        $actualCounts = [
            'attributes' => count($attributes),
            'reporting_marker_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['employer_registration_marker'] ?? null) !== null
                    || ($row['employee_registration_marker'] ?? null) !== null
                    || ($row['monthly_marker'] ?? null) !== null,
            )),
            'reporting_marker_xsd_mapped_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => (($row['employer_registration_marker'] ?? null) !== null
                        || ($row['employee_registration_marker'] ?? null) !== null
                        || ($row['monthly_marker'] ?? null) !== null)
                    && (($row['regzec_xsd_mapping'] ?? null) !== null
                        || ($row['xsd_mapping'] ?? null) !== null),
            )),
            'monthly_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['monthly_marker'] ?? null) !== null,
            )),
            'monthly_xsd_mapped_attributes' => count(array_filter(
                $attributes,
                static fn (array $row): bool => ($row['monthly_marker'] ?? null) !== null
                    && ($row['xsd_mapping'] ?? null) !== null,
            )),
            'codebooks' => count($codebooks),
            'embedded_codebooks' => $embeddedCodebooks,
            'external_reference_codebooks' => $externalCodebooks,
            'codebook_entries' => $entryCount,
        ];
        if (!is_array($counts)
            || CanonicalJson::encode($counts) !== CanonicalJson::encode($actualCounts)
        ) {
            throw new \UnexpectedValueException(
                'Souhrnné počty manifestu JMHZ neodpovídají obsahu: '
                . json_encode(['declared' => $counts, 'actual' => $actualCounts], JSON_THROW_ON_ERROR),
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} manifestu JMHZ není neprázdný text.");
        }

        return $value;
    }
}
