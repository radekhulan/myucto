<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzOfficialExampleSourceCatalog
{
    public const CATALOG_KEY = 'jmhz-official-xml-examples-2026-04-13-source-v1';
    public const MANIFEST_SHA256 = 'c4c28906f38fdf116ed1f15506494b70d423cfc55da6f3909bbf0fdd4dff3e89';
    public const ARCHIVE_SHA256 = 'd31c89be8e2f0e4e93b20edd0beda05030e48884aa45dbfb4db0ee88e313a507';
    public const XSD_INVENTORY_SHA256 = '72285ef5c8924d55041d54b075cbcc135a3229e6954fe81a25ef773a8d12215c';

    private const EXPECTED_COUNTS = [
        'archive_entries' => 40,
        'xml_examples' => 35,
        'well_formed' => 35,
        'xsd_pass' => 17,
        'xsd_fail' => 18,
        'valid_against_pinned_xsd' => 17,
        'different_version' => 0,
        'fragment' => 0,
        'intentionally_invalid' => 0,
        'unresolved' => 18,
        'dzmh' => 2,
        'jmhz' => 21,
        'regzec' => 11,
        'regzeldopl' => 1,
    ];

    /** @var array<string, JmhzOfficialExampleEvidence> */
    private array $examples = [];

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public function __construct(
        private readonly array $manifest,
        array $specManifest,
    ) {
        self::validateManifest($manifest, $specManifest);
        foreach (self::rows($manifest['payload'], 'examples') as $row) {
            $key = self::string($row, 'example_key');
            $this->examples[$key] = new JmhzOfficialExampleEvidence(
                $key,
                self::sha256($row, 'sha256'),
                self::string($row, 'agenda'),
                self::string($row, 'root_local_name'),
                self::string($row, 'root_namespace'),
                JmhzOfficialExampleValidationResult::from(self::string($row, 'xsd_validation')),
                JmhzOfficialExampleClassification::from(self::string($row, 'classification')),
                self::string($row, 'reason_code'),
                self::strings($row, 'blocking_reasons'),
            );
        }
    }

    public static function load(?string $resourceRoot = null): self
    {
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $manifest = self::decode($root . '/examples-2026-04-13/manifest.json');
        $specManifest = (new JmhzSpecPackageCatalog($root))->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        return new self($manifest, $specManifest);
    }

    public function example(string $key): JmhzOfficialExampleEvidence
    {
        return $this->examples[$key]
            ?? throw new \OutOfBoundsException("Oficiální XML příklad {$key} není v katalogu.");
    }

    /** @return list<JmhzOfficialExampleEvidence> */
    public function examples(): array
    {
        return array_values($this->examples);
    }

    /** @return list<JmhzOfficialExampleEvidence> */
    public function examplesForAgenda(string $agenda): array
    {
        return array_values(array_filter(
            $this->examples,
            static fn (JmhzOfficialExampleEvidence $example): bool => $example->agenda === $agenda,
        ));
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public static function validateManifest(array $manifest, array $specManifest): void
    {
        JmhzSpecPackageCatalog::validateManifest($specManifest);
        $payload = $manifest['payload'];
        $actualHash = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifest['manifest_sha256'], $actualHash)
            || !hash_equals(self::MANIFEST_SHA256, $actualHash)
        ) {
            throw new \UnexpectedValueException('Manifest oficiálních XML příkladů nemá připnutý hash.');
        }
        if (self::string($payload, 'schema_version') !== 'jmhz-official-xml-example-source-catalog.v1'
            || self::string($payload, 'catalog_key') !== self::CATALOG_KEY
            || self::string($payload, 'spec_package_key') !== ($specManifest['payload']['package_key'] ?? null)
            || self::string($payload, 'spec_manifest_sha256') !== $specManifest['manifest_sha256']
            || self::sha256($payload, 'xsd_inventory_sha256') !== self::XSD_INVENTORY_SHA256
            || self::string($payload, 'usage_policy') !== 'source_evidence_only'
            || self::string($payload, 'privacy_policy') !== 'xml_bytes_external_not_redistributed'
        ) {
            throw new \UnexpectedValueException('Katalog XML příkladů má neplatnou identitu nebo zdrojovou vazbu.');
        }
        self::validateSourceArchive($payload);
        self::validateTargets($payload);
        $entries = self::validateEntries($payload);
        $actualCounts = self::validateExamples($payload, $entries);
        if (($payload['counts'] ?? null) !== self::EXPECTED_COUNTS || $actualCounts !== self::EXPECTED_COUNTS) {
            throw new \UnexpectedValueException('Katalog XML příkladů má neplatné souhrnné počty.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateSourceArchive(array $payload): void
    {
        $source = $payload['source_archive'] ?? null;
        if (!is_array($source)
            || self::string($source, 'original_filename') !== 'Příklady XML - REGZEC_REGZEL-DOPL_JMHZ_DZMH_2026-04-13.zip'
            || self::sha256($source, 'sha256') !== self::ARCHIVE_SHA256
            || self::string($source, 'availability') !== 'external_evidence_only'
            || self::positiveInt($source, 'byte_length') !== 76416
            || self::positiveInt($source, 'zip_entry_count') !== 40
            || self::positiveInt($source, 'xml_entry_count') !== 35
            || self::positiveInt($source, 'total_uncompressed_bytes') !== 286380
        ) {
            throw new \UnexpectedValueException('Katalog XML příkladů má neplatnou identifikaci externího archivu.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateTargets(array $payload): void
    {
        $expected = [
            'dzmh-1.1' => 'b0959ab50cf627a57abd7f8f78681848b6df7ac90d3e6ced1eb15be2bc7946d1',
            'jmhz-1.4.3.4' => 'c602cdf018dc6a0c4379000e004f2c4609d7313d265defa323e70663efc66216',
            'regzec-1.4.0.4' => 'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
            'regzeldopl-1.2' => '566a124a708492d783a75296eb37a4f76a49ccc5d0aa7d5119a0fa02eee6eedf',
        ];
        $actual = [];
        foreach (self::rows($payload, 'xsd_targets') as $target) {
            $key = self::string($target, 'target_key');
            if (isset($actual[$key])) {
                throw new \UnexpectedValueException("Katalog XML příkladů má duplicitní target {$key}.");
            }
            $entrypoint = self::string($target, 'entrypoint');
            if (basename($entrypoint) === $entrypoint || str_contains($entrypoint, '..')) {
                throw new \UnexpectedValueException("XSD target {$key} nemá bezpečnou verzovanou cestu.");
            }
            $actual[$key] = self::sha256($target, 'entrypoint_sha256');
        }
        if ($actual !== $expected) {
            throw new \UnexpectedValueException('Katalog XML příkladů odkazuje na jinou sadu XSD targetů.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private static function validateEntries(array $payload): array
    {
        $entries = [];
        $paths = [];
        $casefoldPaths = [];
        foreach (self::rows($payload, 'archive_entries') as $entry) {
            $index = self::nonNegativeInt($entry, 'entry_index');
            $path = self::string($entry, 'entry_path_raw');
            $kind = self::string($entry, 'entry_kind');
            if (isset($entries[$index]) || isset($paths[$path]) || isset($casefoldPaths[mb_strtolower($path, 'UTF-8')])
                || !hash_equals(self::sha256($entry, 'entry_path_utf8_sha256'), hash('sha256', $path))
                || !in_array($kind, ['directory', 'xml'], true)
            ) {
                throw new \UnexpectedValueException("ZIP evidence položka {$index} není jednoznačná.");
            }
            self::assertSafePath($path);
            if (($kind === 'directory') !== str_ends_with($path, '/')) {
                throw new \UnexpectedValueException("ZIP evidence položka {$index} má neplatný druh.");
            }
            if ($kind === 'xml') {
                self::sha256($entry, 'sha256');
            } elseif (($entry['sha256'] ?? null) !== null) {
                throw new \UnexpectedValueException("Adresář ZIP evidence {$index} nesmí mít obsahový hash.");
            }
            $entries[$index] = $entry;
            $paths[$path] = true;
            $casefoldPaths[mb_strtolower($path, 'UTF-8')] = true;
        }
        ksort($entries);
        if (array_keys($entries) !== range(0, 39)) {
            throw new \UnexpectedValueException('ZIP evidence nemá souvislé pořadí položek.');
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, int>
     */
    private static function validateExamples(array $payload, array $entries): array
    {
        $keys = [];
        $hashes = [];
        $counts = array_fill_keys(array_keys(self::EXPECTED_COUNTS), 0);
        $counts['archive_entries'] = count($entries);
        foreach (self::rows($payload, 'examples') as $row) {
            $key = self::string($row, 'example_key');
            $sha256 = self::sha256($row, 'sha256');
            $index = self::nonNegativeInt($row, 'archive_entry_index');
            if (isset($keys[$key]) || isset($hashes[$sha256]) || !isset($entries[$index])) {
                throw new \UnexpectedValueException("XML evidence {$key} není jednoznačná.");
            }
            $entry = $entries[$index];
            if (($entry['entry_kind'] ?? null) !== 'xml'
                || ($entry['sha256'] ?? null) !== $sha256
                || ($entry['entry_path_raw'] ?? null) !== ($row['entry_path_raw'] ?? null)
                || ($entry['entry_path_utf8_sha256'] ?? null) !== ($row['entry_path_utf8_sha256'] ?? null)
                || ($row['well_formed'] ?? null) !== true
                || self::string($row, 'usage') !== 'source_evidence_only'
            ) {
                throw new \UnexpectedValueException("XML evidence {$key} neodpovídá položce archivu.");
            }
            self::verifyRowHash($row, $key);
            $validation = JmhzOfficialExampleValidationResult::from(self::string($row, 'xsd_validation'));
            $classification = JmhzOfficialExampleClassification::from(self::string($row, 'classification'));
            $evidence = self::strings($row, 'decision_evidence');
            $blocking = self::strings($row, 'blocking_reasons');
            if ($evidence === [] || count($evidence) !== count(array_unique($evidence))
                || count($blocking) !== count(array_unique($blocking))
            ) {
                throw new \UnexpectedValueException("XML evidence {$key} nemá jednoznačné rozhodovací podklady.");
            }
            self::assertClassification(
                $classification,
                $validation,
                self::string($row, 'reason_code'),
                $evidence,
                $blocking,
                $key,
            );
            ++$counts['xml_examples'];
            ++$counts['well_formed'];
            if ($validation === JmhzOfficialExampleValidationResult::Pass) {
                ++$counts['xsd_pass'];
            } elseif ($validation === JmhzOfficialExampleValidationResult::Fail) {
                ++$counts['xsd_fail'];
            } else {
                throw new \UnexpectedValueException("XML evidence {$key} nemá přiřazený XSD target.");
            }
            ++$counts[$classification->value];
            $agenda = self::string($row, 'agenda');
            if (!isset($counts[$agenda])) {
                throw new \UnexpectedValueException("XML evidence {$key} má neznámou agendu.");
            }
            ++$counts[$agenda];
            $keys[$key] = true;
            $hashes[$sha256] = true;
        }

        return $counts;
    }

    /**
     * @param list<string> $evidence
     * @param list<string> $blocking
     */
    private static function assertClassification(
        JmhzOfficialExampleClassification $classification,
        JmhzOfficialExampleValidationResult $validation,
        string $reason,
        array $evidence,
        array $blocking,
        string $key,
    ): void {
        $valid = $classification === JmhzOfficialExampleClassification::ValidAgainstPinnedXsd
            && $validation === JmhzOfficialExampleValidationResult::Pass
            && $reason === 'pinned_xsd_validation_passed'
            && $evidence === ['pinned_xsd_validation']
            && $blocking === [];
        $unresolved = $classification === JmhzOfficialExampleClassification::Unresolved
            && $validation === JmhzOfficialExampleValidationResult::Fail
            && $evidence === ['pinned_xsd_validation']
            && (($reason === 'xsd_year_below_pinned_minimum'
                    && $blocking === ['source_version_not_established'])
                || ($reason === 'placeholder_identifier_rejected_by_pinned_xsd'
                    && $blocking === ['publisher_intent_not_established']));
        if (!$valid && !$unresolved) {
            throw new \UnexpectedValueException("XML evidence {$key} má neplatnou fail-closed klasifikaci.");
        }
    }

    /** @param array<string, mixed> $row */
    private static function verifyRowHash(array $row, string $key): void
    {
        $expected = self::sha256($row, 'row_hash');
        unset($row['row_hash']);
        if (!hash_equals($expected, hash('sha256', CanonicalJson::encode($row)))) {
            throw new \UnexpectedValueException("XML evidence {$key} má neplatný hash řádku.");
        }
    }

    private static function assertSafePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path) === 1
        ) {
            throw new \UnexpectedValueException('ZIP evidence obsahuje nebezpečnou cestu.');
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \UnexpectedValueException('ZIP evidence obsahuje nebezpečný segment cesty.');
            }
        }
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private static function decode(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest oficiálních XML příkladů má neplatný formát.');
        }

        return ['manifest_sha256' => $decoded['manifest_sha256'], 'payload' => $decoded['payload']];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$key} musí být neprázdný řetězec.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function sha256(array $row, string $key): string
    {
        $value = self::string($row, $key);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \UnexpectedValueException("Pole {$key} musí být SHA-256.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function positiveInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("Pole {$key} musí být kladné celé číslo.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nonNegativeInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("Pole {$key} musí být nezáporné celé číslo.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function rows(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)
            || array_filter($value, static fn (mixed $item): bool => !is_array($item)) !== []
        ) {
            throw new \UnexpectedValueException("Pole {$key} musí být seznam objektů.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function strings(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)
            || array_filter($value, static fn (mixed $item): bool => !is_string($item) || $item === '') !== []
        ) {
            throw new \UnexpectedValueException("Pole {$key} musí být seznam neprázdných řetězců.");
        }

        return $value;
    }
}
