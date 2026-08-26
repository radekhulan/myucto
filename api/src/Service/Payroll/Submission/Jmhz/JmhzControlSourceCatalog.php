<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzControlSourceCatalog
{
    public const CATALOG_KEY = 'jmhz-controls-1.4.2.8-source-v4';
    public const MANIFEST_SHA256 = '83ec6a985cf1c6d6e2429657d4ba6d12b09bfb849a32b504fff882383fb03800';

    /** @var array<int, JmhzControlDefinition> */
    private array $definitions = [];

    private ?JmhzControlParameterCatalog $parameterCatalog = null;

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public function __construct(
        private readonly array $manifest,
        array $specManifest,
    ) {
        self::validateManifest($manifest, $specManifest);
        foreach (self::rows($manifest['payload'], 'controls') as $row) {
            $id = self::positiveInt($row, 'control_id');
            $this->definitions[$id] = new JmhzControlDefinition(
                new JmhzControlId($id),
                self::string($row, 'name'),
                JmhzControlScope::from(self::string($row, 'scope')),
                JmhzControlSystem::from(self::string($row, 'portal_system')),
                JmhzControlPassability::from(self::string($row, 'portal_passability')),
                JmhzControlSystem::from(self::string($row, 'remote_system')),
                JmhzControlPassability::from(self::string($row, 'remote_passability')),
                self::string($row, 'detail_text'),
                self::string($row, 'error_message'),
                array_map(
                    static fn (array $ref): string => self::string($ref, 'attribute_id'),
                    self::rows($row, 'attribute_refs'),
                ),
                self::strings($row, 'symbolic_attribute_refs'),
                self::nullableString($row, 'category'),
                self::nullableString($row, 'area'),
                self::sourceAnomaly($row),
            );
        }
        ksort($this->definitions);
    }

    public static function load(?string $resourceRoot = null): self
    {
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $directory = $root . DIRECTORY_SEPARATOR . 'dictionary-1.4.1.6';
        $manifest = self::decode($directory . DIRECTORY_SEPARATOR . 'control-manifest.json');
        if (!hash_equals(self::MANIFEST_SHA256, $manifest['manifest_sha256'])) {
            throw new \UnexpectedValueException('Katalog kontrol JMHZ nemá připnutý hash manifestu.');
        }
        $specManifest = (new JmhzSpecPackageCatalog($root))->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        $catalog = new self($manifest, $specManifest);
        $source = $manifest['payload']['source'] ?? null;
        if (!is_array($source)) {
            throw new \UnexpectedValueException('Katalog kontrol JMHZ nemá zdroj.');
        }
        $filename = self::string($source, 'filename');
        if (basename($filename) !== $filename) {
            throw new \UnexpectedValueException('Zdroj katalogu kontrol JMHZ má neplatnou cestu.');
        }
        $actual = hash_file('sha256', $directory . DIRECTORY_SEPARATOR . $filename);
        if (!is_string($actual) || !hash_equals(self::string($source, 'sha256'), $actual)) {
            throw new \UnexpectedValueException('Zdroj katalogu kontrol JMHZ neodpovídá manifestu.');
        }

        return $catalog;
    }

    public function definition(int $controlId): JmhzControlDefinition
    {
        return $this->definitions[$controlId]
            ?? throw new \OutOfBoundsException("Kontrola JMHZ {$controlId} není v katalogu.");
    }

    /**
     * Celý katalog seřazený podle ID. ID jsou řídká (199 kontrol v rozsahu
     * 1–355), takže se nikdy nesmí indexovat pořadím.
     *
     * @return array<int, JmhzControlDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Parametrické konstanty katalogu (sazby pojistného, prahy, meze). Jsou
     * účinné k datu, takže sazba se nesmí zadrátovat do kódu — kontrola 10
     * měla v roce 2025 sazbu 0,288 a v roce 2026 0,298.
     */
    public function parameters(): JmhzControlParameterCatalog
    {
        return $this->parameterCatalog ??= new JmhzControlParameterCatalog(
            self::rows($this->manifest['payload'], 'parameters'),
        );
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
            throw new \UnexpectedValueException('Manifest katalogu kontrol JMHZ má neplatný hash.');
        }
        if (self::string($payload, 'schema_version') !== 'jmhz-control-source-catalog.v4'
            || self::string($payload, 'catalog_key') !== self::CATALOG_KEY
            || self::string($payload, 'spec_package_key') !== ($specManifest['payload']['package_key'] ?? null)
            || self::string($payload, 'spec_manifest_sha256') !== $specManifest['manifest_sha256']
        ) {
            throw new \UnexpectedValueException('Katalog kontrol JMHZ odkazuje na jiný balík specifikace.');
        }
        $source = $payload['source'] ?? null;
        if (!is_array($source)
            || ($source['sheets'] ?? null) !== ['MH', 'Parametrické konstanty']
        ) {
            throw new \UnexpectedValueException('Katalog kontrol JMHZ nemá úplnou identifikaci listů.');
        }
        $specVersions = $specManifest['payload']['versions'] ?? null;
        if (!is_array($specVersions)
            || self::string($payload, 'version') !== ($specVersions['control_catalog'] ?? null)
        ) {
            throw new \UnexpectedValueException('Katalog kontrol JMHZ má jinou verzi než rodičovský balík.');
        }
        $specSources = self::rows($specManifest['payload'], 'sources');
        $matchingSources = array_values(array_filter(
            $specSources,
            static fn (array $row): bool => ($row['role'] ?? null) === 'control_catalog_source',
        ));
        if (count($matchingSources) !== 1
            || self::string($source, 'filename') !== ($matchingSources[0]['filename'] ?? null)
            || self::string($source, 'sha256') !== ($matchingSources[0]['sha256'] ?? null)
        ) {
            throw new \UnexpectedValueException('Zdroj katalogu kontrol JMHZ neodpovídá rodičovskému balíku.');
        }
        $knownAttributes = array_fill_keys(array_map(
            static fn (array $row): string => self::string($row, 'attribute_id'),
            self::rows($specManifest['payload'], 'dictionary_attributes'),
        ), true);
        $knownControls = [];
        $attributeRefCount = 0;
        $uniqueAttributes = [];
        $symbolicAttributeRefCount = 0;
        $sourceAnomalyCount = 0;
        $remoteCounts = ['blocking' => 0, 'passable' => 0, 'unavailable' => 0];
        foreach (self::rows($payload, 'controls') as $row) {
            $id = self::positiveInt($row, 'control_id');
            if (isset($knownControls[$id])) {
                throw new \UnexpectedValueException("Duplicitní kontrola JMHZ {$id}.");
            }
            $knownControls[$id] = true;
            JmhzControlScope::from(self::string($row, 'scope'));
            JmhzControlSystem::from(self::string($row, 'portal_system'));
            JmhzControlSystem::from(self::string($row, 'remote_system'));
            JmhzControlPassability::from(self::string($row, 'portal_passability'));
            $remote = JmhzControlPassability::from(self::string($row, 'remote_passability'));
            ++$remoteCounts[$remote->value];
            self::verifyRowHash($row, "kontrola {$id}");
            $anomaly = $row['source_anomaly'] ?? null;
            $expectedAnomaly = $id === 333 ? [
                'code' => 'official_detail_attribute_mismatch',
                'source_cells' => ['B188', 'C188', 'L188', 'M188'],
                'declared_attribute_ids' => ['10006', '10032', '10010', '10011'],
                'detail_attribute_ids' => ['10016', '10495'],
                'resolution' => 'fail_closed_not_evaluable',
            ] : null;
            if (($anomaly === null || $expectedAnomaly === null)
                ? $anomaly !== $expectedAnomaly
                : CanonicalJson::encode($anomaly) !== CanonicalJson::encode($expectedAnomaly)
            ) {
                throw new \UnexpectedValueException("Kontrola JMHZ {$id} má neplatně doloženou anomálii.");
            }
            $sourceAnomalyCount += $anomaly === null ? 0 : 1;
            $symbolicRefs = self::strings($row, 'symbolic_attribute_refs');
            if (count($symbolicRefs) !== count(array_unique($symbolicRefs))) {
                throw new \UnexpectedValueException("Kontrola JMHZ {$id} má duplicitní symbolický odkaz.");
            }
            $symbolicAttributeRefCount += count($symbolicRefs);
            $seenRefs = [];
            foreach (self::rows($row, 'attribute_refs') as $ref) {
                $attributeId = self::string($ref, 'attribute_id');
                if (!isset($knownAttributes[$attributeId]) || isset($seenRefs[$attributeId])) {
                    throw new \UnexpectedValueException("Kontrola JMHZ {$id} má neplatný odkaz na atribut.");
                }
                $seenRefs[$attributeId] = true;
                $uniqueAttributes[$attributeId] = true;
                ++$attributeRefCount;
                self::verifyRowHash($ref, "odkaz kontroly {$id}");
            }
        }
        $parameterRefCount = 0;
        $parameterValueCount = 0;
        $uniqueParameterControls = [];
        $missingParameterRefs = 0;
        $parameterKeys = [];
        foreach (self::rows($payload, 'parameters') as $row) {
            $key = self::string($row, 'parameter_key');
            if (isset($parameterKeys[$key])) {
                throw new \UnexpectedValueException("Duplicitní parametr JMHZ {$key}.");
            }
            $parameterKeys[$key] = true;
            self::verifyRowHash($row, "parametr {$key}");
            $sourceRow = self::positiveInt($row, 'source_row');
            $rawRefs = self::string($row, 'control_refs_raw');
            $formattedRefs = self::string($row, 'control_refs_formatted');
            $anomaly = $row['control_refs_anomaly'] ?? null;
            if ($anomaly !== null && !is_string($anomaly)) {
                throw new \UnexpectedValueException("Parametr {$key} má neplatný marker anomálie.");
            }
            $expectedAnomaly = match ([$sourceRow, $rawRefs, $formattedRefs]) {
                [7, '118270', '118,270'] => 'known_excel_number_format_split_118_270',
                [8, '168270', '168,270'] => 'known_excel_number_format_split_168_270',
                default => null,
            };
            if ($anomaly !== $expectedAnomaly) {
                throw new \UnexpectedValueException("Parametr {$key} má neplatně doloženou anomálii.");
            }
            $seenControlRefs = [];
            foreach (self::rows($row, 'control_refs') as $ref) {
                $controlId = self::positiveInt($ref, 'control_id');
                if (isset($seenControlRefs[$controlId])) {
                    throw new \UnexpectedValueException("Parametr {$key} odkazuje duplicitně na kontrolu {$controlId}.");
                }
                $seenControlRefs[$controlId] = true;
                $resolution = self::string($ref, 'resolution');
                $expected = isset($knownControls[$controlId]) ? 'present' : 'missing';
                if ($resolution !== $expected) {
                    throw new \UnexpectedValueException("Parametr {$key} má neplatné rozlišení kontroly.");
                }
                $missingParameterRefs += $resolution === 'missing' ? 1 : 0;
                $uniqueParameterControls[$controlId] = true;
                ++$parameterRefCount;
                self::verifyRowHash($ref, "odkaz parametru {$key}");
            }
            $dates = [];
            foreach (self::rows($row, 'values') as $value) {
                $sourceCell = self::string($value, 'source_cell');
                $date = self::string($value, 'effective_from');
                if (preg_match('/^[C-L][0-9]+$/', $sourceCell) !== 1
                    || preg_match('/^\d{4}-\d{2}-01$/', $date) !== 1
                    || isset($dates[$date])
                ) {
                    throw new \UnexpectedValueException("Parametr {$key} má neplatné období.");
                }
                $dates[$date] = true;
                if (!in_array(self::string($value, 'raw_type'), ['n', 's'], true)
                    || self::string($value, 'normalized_value') === ''
                ) {
                    throw new \UnexpectedValueException("Parametr {$key} nemá auditovatelnou zdrojovou hodnotu.");
                }
                if (preg_match('/^-?\d+(?:\.\d+)?$/', self::string(
                    $value,
                    'canonical_value',
                )) !== 1) {
                    throw new \UnexpectedValueException("Parametr {$key} nemá desetinnou hodnotu.");
                }
                ++$parameterValueCount;
                self::verifyRowHash($value, "hodnota parametru {$key}");
            }
        }
        $actualCounts = [
            'controls' => count($knownControls),
            'attribute_refs' => $attributeRefCount,
            'unique_attributes' => count($uniqueAttributes),
            'symbolic_attribute_refs' => $symbolicAttributeRefCount,
            'source_anomalies' => $sourceAnomalyCount,
            'parameters' => count($parameterKeys),
            'parameter_control_refs' => $parameterRefCount,
            'unique_parameter_controls' => count($uniqueParameterControls),
            'missing_parameter_control_refs' => $missingParameterRefs,
            'parameter_values' => $parameterValueCount,
            'blocking_remote_controls' => $remoteCounts['blocking'],
            'passable_remote_controls' => $remoteCounts['passable'],
            'unavailable_remote_controls' => $remoteCounts['unavailable'],
        ];
        $counts = $payload['counts'] ?? null;
        if (!is_array($counts)
            || CanonicalJson::encode($counts) !== CanonicalJson::encode($actualCounts)
        ) {
            throw new \UnexpectedValueException('Souhrnné počty katalogu kontrol JMHZ nesouhlasí.');
        }
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private static function decode(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Nelze načíst manifest katalogu kontrol JMHZ.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest katalogu kontrol JMHZ nemá očekávanou strukturu.');
        }

        return ['manifest_sha256' => $decoded['manifest_sha256'], 'payload' => $decoded['payload']];
    }

    /** @param array<string, mixed> $row */
    private static function verifyRowHash(array $row, string $label): void
    {
        $expected = self::string($row, 'row_hash');
        unset($row['row_hash']);
        if (!hash_equals($expected, hash('sha256', CanonicalJson::encode($row)))) {
            throw new \UnexpectedValueException("Neplatný hash: {$label}.");
        }
    }

    /** @param array<string, mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ není kladné číslo.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ není text ani null.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function sourceAnomaly(array $row): ?string
    {
        $value = $row['source_anomaly'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Anomálie kontroly JMHZ není objekt.');
        }

        return self::string($value, 'code');
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ není neprázdný text.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private static function rows(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function strings(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \UnexpectedValueException("Pole {$field} katalogu JMHZ obsahuje neplatný text.");
            }
        }

        return $value;
    }
}
