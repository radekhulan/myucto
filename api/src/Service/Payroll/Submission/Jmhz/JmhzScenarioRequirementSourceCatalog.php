<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzScenarioRequirementSourceCatalog
{
    public const CATALOG_KEY = 'jmhz-scenario-requirements-1.4.0.2-source-v1';
    public const MANIFEST_SHA256 = 'bb43e8621c713729d534c026379c87e761711c53c42ce7e97377b68b0868b4e0';
    public const SOURCE_SHA256 = 'cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37';

    private const EXPECTED_COUNTS = [
        'scenarios' => 8,
        'interactions' => 37,
        'interaction_attribute_refs' => 22,
        'unique_interaction_attributes' => 19,
        'matrices' => 48,
        'part_matrices' => 2,
        'scenario_matrices' => 8,
        'foundation_matrices' => 2,
        'interaction_matrices' => 36,
        'requirements' => 1181,
        'required_requirements' => 595,
        'optional_requirements' => 350,
        'conditional_requirements' => 236,
        'add_effects' => 147,
        'remove_effects' => 28,
        'master_attributes' => 442,
        'reconciliation_axes' => 43,
        'reconciliation_nonempty_cells' => 250,
        'derived_axes' => 41,
        'derived_one_cells' => 159,
        'derived_zero_cells' => 17963,
        'derived_blank_cells' => 0,
        'derived_zero_axes' => 15,
        'projection_checks' => 50,
        'anomalies' => 11,
    ];

    /** @var array<string, JmhzScenarioDefinition> */
    private array $scenarios = [];

    /** @var array<string, JmhzInteractionDefinition> */
    private array $interactions = [];

    /** @var array<string, list<JmhzFieldRequirement>> */
    private array $requirements = [];

    /** @var array<string, JmhzFieldMatrixDefinition> */
    private array $matrices = [];

    /** @var array<string, JmhzSourceEvidenceAxis> */
    private array $evidenceAxes = [];

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public function __construct(
        private readonly array $manifest,
        array $specManifest,
    ) {
        self::validateManifest($manifest, $specManifest);
        foreach (self::rows($manifest['payload'], 'scenarios') as $row) {
            $key = self::string($row, 'scenario_key');
            $this->scenarios[$key] = new JmhzScenarioDefinition(
                $key,
                self::string($row, 'selector_raw_type'),
                self::string($row, 'selector_raw'),
                self::string($row, 'name_raw'),
                self::string($row, 'condition_raw'),
                self::string($row, 'business_description_raw'),
                self::string($row, 'xsd_entrypoint'),
                JmhzScenarioSelectionKind::from(self::string($row, 'selection_kind')),
            );
        }
        foreach (self::rows($manifest['payload'], 'interactions') as $row) {
            $key = self::string($row, 'interaction_key');
            $this->interactions[$key] = new JmhzInteractionDefinition(
                $key,
                self::string($row, 'condition_raw'),
                self::nullableString($row, 'portal_text'),
                self::nullableString($row, 'note_raw'),
                JmhzInteractionTriggerKind::from(self::string($row, 'trigger_kind')),
                self::string($row, 'row_hash'),
            );
        }
        foreach (self::rows($manifest['payload'], 'matrices') as $matrix) {
            $matrixKey = self::string($matrix, 'matrix_key');
            $this->matrices[$matrixKey] = new JmhzFieldMatrixDefinition(
                $matrixKey,
                JmhzMatrixKind::from(self::string($matrix, 'matrix_kind')),
                self::string($matrix, 'source_sheet'),
                self::nonNegativeInt($matrix, 'row_count'),
            );
            $requirements = [];
            foreach (self::rows($matrix, 'requirements') as $row) {
                $requirements[] = new JmhzFieldRequirement(
                    $matrixKey,
                    self::string($row, 'attribute_id'),
                    JmhzFieldRequirementKind::from(self::string($row, 'requirement_kind')),
                    self::nullableString($row, 'condition_note_raw'),
                    JmhzFieldEffect::from(self::string($row, 'effect_kind')),
                    self::string($row, 'row_hash'),
                );
            }
            $this->requirements[$matrixKey] = $requirements;
        }
        foreach (self::rows($manifest['payload'], 'evidence_axes') as $axis) {
            $key = self::string($axis, 'axis_key');
            $this->evidenceAxes[$key] = new JmhzSourceEvidenceAxis(
                $key,
                self::string($axis, 'axis_kind'),
                self::string($axis, 'source_column'),
                self::string($axis, 'source_sheet'),
                self::string($axis, 'label_raw'),
                self::nonNegativeInt($axis, 'dimension_count'),
                self::nonNegativeInt($axis, 'nonempty_count'),
                self::nonNegativeInt($axis, 'blank_count'),
                self::nonNegativeInt($axis, 'zero_count'),
                self::nonNegativeInt($axis, 'one_count'),
                self::string($axis, 'raw_vector_sha256'),
                self::nonNegativeInt($axis, 'dictionary_formula_count'),
                self::nullableString($axis, 'dictionary_formula_vector_sha256'),
                self::nullableString($axis, 'dictionary_cached_vector_sha256'),
                self::nonNegativeInt($axis, 'master_match_count'),
                self::nonNegativeInt($axis, 'master_mismatch_count'),
                self::string($axis, 'reconciliation_status'),
            );
        }
    }

    public static function load(?string $resourceRoot = null): self
    {
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $directory = $root . DIRECTORY_SEPARATOR . 'dictionary-1.4.1.6';
        $manifest = self::decode(
            $directory . DIRECTORY_SEPARATOR . 'scenario-requirement-manifest.json',
        );
        if (!hash_equals(self::MANIFEST_SHA256, $manifest['manifest_sha256'])) {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ nemá připnutý hash manifestu.');
        }
        $specManifest = (new JmhzSpecPackageCatalog($root))->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
        $catalog = new self($manifest, $specManifest);
        $source = $manifest['payload']['source'] ?? null;
        if (!is_array($source)) {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ nemá zdroj.');
        }
        $filename = self::string($source, 'filename');
        if (basename($filename) !== $filename) {
            throw new \UnexpectedValueException('Zdroj katalogu scénářů JMHZ má neplatnou cestu.');
        }
        $actual = hash_file('sha256', $directory . DIRECTORY_SEPARATOR . $filename);
        if (!is_string($actual) || !hash_equals(self::SOURCE_SHA256, $actual)) {
            throw new \UnexpectedValueException('Zdroj katalogu scénářů JMHZ neodpovídá připnutému SHA-256.');
        }
        $xsdDirectory = dirname($root, 3) . '/xsd/jmhz/jmhz-1.4.3.4';
        foreach ($catalog->scenarios as $scenario) {
            if (basename($scenario->xsdEntrypoint) !== $scenario->xsdEntrypoint
                || !is_file($xsdDirectory . DIRECTORY_SEPARATOR . $scenario->xsdEntrypoint)
            ) {
                throw new \UnexpectedValueException(
                    "Scénář JMHZ {$scenario->key} odkazuje na neznámé XSD.",
                );
            }
        }

        return $catalog;
    }

    public function scenario(string $key): JmhzScenarioDefinition
    {
        return $this->scenarios[$key]
            ?? throw new \OutOfBoundsException("Scénář JMHZ {$key} není v katalogu.");
    }

    public function interaction(string $key): JmhzInteractionDefinition
    {
        return $this->interactions[$key]
            ?? throw new \OutOfBoundsException("Interakce JMHZ {$key} není v katalogu.");
    }

    public function matrix(string $key): JmhzFieldMatrixDefinition
    {
        return $this->matrices[$key]
            ?? throw new \OutOfBoundsException("Matice JMHZ {$key} není v katalogu.");
    }

    /** @return list<JmhzFieldRequirement> */
    public function requirementsForMatrix(string $matrixKey): array
    {
        return $this->requirements[$matrixKey]
            ?? throw new \OutOfBoundsException("Matice JMHZ {$matrixKey} není v katalogu.");
    }

    public function evidenceAxis(string $key): JmhzSourceEvidenceAxis
    {
        return $this->evidenceAxes[$key]
            ?? throw new \OutOfBoundsException("Důkazní osa JMHZ {$key} není v katalogu.");
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
            throw new \UnexpectedValueException('Manifest katalogu scénářů JMHZ má neplatný hash.');
        }
        if (self::string($payload, 'schema_version')
                !== 'jmhz-scenario-requirement-source-catalog.v1'
            || self::string($payload, 'catalog_key') !== self::CATALOG_KEY
            || self::string($payload, 'version') !== '1.4.0.2'
            || self::string($payload, 'spec_package_key') !== ($specManifest['payload']['package_key'] ?? null)
            || self::string($payload, 'spec_manifest_sha256') !== $specManifest['manifest_sha256']
        ) {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ odkazuje na jiný balík specifikace.');
        }
        $versions = $specManifest['payload']['versions'] ?? null;
        if (!is_array($versions) || ($versions['process'] ?? null) !== '1.4.0.2') {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ má jinou procesní verzi než balík.');
        }
        $source = $payload['source'] ?? null;
        if (!is_array($source)
            || self::string($source, 'sha256') !== self::SOURCE_SHA256
            || self::string($source, 'filename')
                !== 'datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx'
        ) {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ nemá připnutý oficiální zdroj.');
        }
        $sheets = self::strings($source, 'sheets');
        if (count($sheets) !== 56 || count(array_unique($sheets)) !== 56
            || !in_array('MASTER', $sheets, true) || !in_array('SLOVNÍK', $sheets, true)
        ) {
            throw new \UnexpectedValueException('Katalog scénářů JMHZ nemá úplný seznam listů.');
        }

        $knownAttributes = array_fill_keys(array_map(
            static fn (array $row): string => self::string($row, 'attribute_id'),
            self::rows($specManifest['payload'], 'dictionary_attributes'),
        ), true);
        $scenarioKeys = self::validateScenarios($payload);
        $interactionKeys = self::validateInteractions($payload);
        $matrixStats = self::validateMatrices($payload, $knownAttributes);
        self::validateDefinitionOwnership($payload);
        $referenceStats = self::validateInteractionAttributeRefs(
            $payload,
            $interactionKeys,
            $knownAttributes,
        );
        $masterAttributes = self::validateMasterAxis($payload, $knownAttributes);
        $evidenceStats = self::validateEvidence($payload, $masterAttributes);
        $sourceOnlyStats = self::validateSourceOnlyEvidence($payload);
        $actualCounts = array_merge([
            'scenarios' => count($scenarioKeys),
            'interactions' => count($interactionKeys),
            'master_attributes' => count($masterAttributes),
        ], $referenceStats, $matrixStats, $evidenceStats, $sourceOnlyStats);
        $counts = $payload['counts'] ?? null;
        if (!is_array($counts)
            || CanonicalJson::encode($counts) !== CanonicalJson::encode(self::EXPECTED_COUNTS)
            || CanonicalJson::encode($actualCounts) !== CanonicalJson::encode(self::EXPECTED_COUNTS)
        ) {
            throw new \UnexpectedValueException('Souhrnné počty katalogu scénářů JMHZ nesouhlasí.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, true>
     */
    private static function validateScenarios(array $payload): array
    {
        $known = [];
        $allowedXsd = [
            'formBezPriznaku.xsd', 'formPestoun.xsd', 'formCinnostKS.xsd', 'formVezen.xsd',
            'formJinyPrijem.xsd', 'formMezinarodniPronajemSily.xsd', 'formOzpTpp.xsd',
            'formOdlozenyPrijem.xsd',
        ];
        foreach (self::rows($payload, 'scenarios') as $row) {
            $key = self::string($row, 'scenario_key');
            if (isset($known[$key]) || self::string($row, 'source_sheet') !== 'Datové scénáře'
                || !in_array(self::string($row, 'selector_raw_type'), ['n', 's'], true)
                || !in_array(self::string($row, 'xsd_entrypoint'), $allowedXsd, true)
            ) {
                throw new \UnexpectedValueException("Scénář JMHZ {$key} má neplatnou definici.");
            }
            JmhzScenarioSelectionKind::from(self::string($row, 'selection_kind'));
            self::positiveInt($row, 'source_row');
            if (self::positiveInt($row, 'ordinal') !== count($known) + 1
                || !in_array(self::string($row, 'business_description_cell_kind'), [
                    'plain', 'rich_text',
                ], true)
            ) {
                throw new \UnexpectedValueException("Scénář JMHZ {$key} nemá platné pořadí nebo typ popisu.");
            }
            self::string($row, 'matrix_key');
            self::string($row, 'selector_raw');
            self::string($row, 'name_raw');
            self::string($row, 'condition_raw');
            self::string($row, 'business_description_raw');
            self::verifyRowHash($row, "scénář {$key}");
            $known[$key] = true;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, true>
     */
    private static function validateInteractions(array $payload): array
    {
        $known = [];
        foreach (self::rows($payload, 'interactions') as $row) {
            $key = self::string($row, 'interaction_key');
            if (isset($known[$key]) || self::string($row, 'source_sheet') !== 'Interakce'
                || preg_replace('/\s+/', '', self::string($row, 'interaction_id_raw')) !== $key
            ) {
                throw new \UnexpectedValueException("Interakce JMHZ {$key} má neplatnou definici.");
            }
            JmhzInteractionTriggerKind::from(self::string($row, 'trigger_kind'));
            self::positiveInt($row, 'source_row');
            if (self::positiveInt($row, 'ordinal') !== count($known) + 1) {
                throw new \UnexpectedValueException("Interakce JMHZ {$key} nemá platné pořadí.");
            }
            self::nullableString($row, 'matrix_key');
            self::string($row, 'condition_raw');
            self::nullableString($row, 'portal_text');
            self::nullableString($row, 'note_raw');
            self::verifyRowHash($row, "interakce {$key}");
            $known[$key] = true;
        }

        return $known;
    }

    /** @param array<string, mixed> $payload */
    private static function validateDefinitionOwnership(array $payload): void
    {
        $matrices = [];
        foreach (self::rows($payload, 'matrices') as $matrix) {
            $matrices[self::string($matrix, 'matrix_key')] = [
                'kind' => self::string($matrix, 'matrix_kind'),
                'rows' => self::nonNegativeInt($matrix, 'row_count'),
            ];
        }
        foreach (self::rows($payload, 'scenarios') as $scenario) {
            $key = self::string($scenario, 'scenario_key');
            $matrixKey = self::string($scenario, 'matrix_key');
            if (($matrices[$matrixKey]['kind'] ?? null) !== 'scenario') {
                throw new \UnexpectedValueException("Scénář JMHZ {$key} nemá vlastní matici.");
            }
        }
        foreach (self::rows($payload, 'interactions') as $interaction) {
            $key = self::string($interaction, 'interaction_key');
            $matrixKey = self::nullableString($interaction, 'matrix_key');
            $matrixKind = $matrixKey === null ? null : ($matrices[$matrixKey]['kind'] ?? null);
            $matrixRows = $matrixKey === null ? null : ($matrices[$matrixKey]['rows'] ?? null);
            if (($key === 'IN14') !== ($matrixKey === null)
                || ($matrixKey !== null && $matrixKind !== 'interaction')
                || ($key === 'IN37' && $matrixRows !== 0)
            ) {
                throw new \UnexpectedValueException("Interakce JMHZ {$key} nemá očekávanou matici.");
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, true> $interactionKeys
     * @param array<string, true> $knownAttributes
     * @return array<string, int>
     */
    private static function validateInteractionAttributeRefs(
        array $payload,
        array $interactionKeys,
        array $knownAttributes,
    ): array {
        $seen = [];
        $attributes = [];
        $ordinals = [];
        foreach (self::rows($payload, 'interaction_attribute_refs') as $row) {
            $interactionKey = self::string($row, 'interaction_key');
            $attributeId = self::string($row, 'attribute_id');
            $ordinal = self::positiveInt($row, 'ordinal');
            $semanticKey = $interactionKey . '/' . $attributeId;
            if (!isset($interactionKeys[$interactionKey]) || !isset($knownAttributes[$attributeId])
                || isset($seen[$semanticKey])
                || $ordinal !== ($ordinals[$interactionKey] ?? 0) + 1
                || preg_match('/^Interakce![A-Z]+[0-9]+$/', self::string($row, 'source_cell')) !== 1
            ) {
                throw new \UnexpectedValueException('Lexikální vazba interakce JMHZ není platná.');
            }
            self::string($row, 'source_match_raw');
            self::verifyRowHash($row, "vazba {$semanticKey}");
            $seen[$semanticKey] = true;
            $attributes[$attributeId] = true;
            $ordinals[$interactionKey] = $ordinal;
        }

        return [
            'interaction_attribute_refs' => count($seen),
            'unique_interaction_attributes' => count($attributes),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, true> $knownAttributes
     * @return array<string, int>
     */
    private static function validateMatrices(array $payload, array $knownAttributes): array
    {
        $matrixKeys = [];
        $kindCounts = ['part' => 0, 'scenario' => 0, 'foundation' => 0, 'interaction' => 0];
        $requirementCounts = ['required' => 0, 'optional' => 0, 'conditional' => 0];
        $effectCounts = ['add' => 0, 'remove' => 0];
        $requirementCount = 0;
        foreach (self::rows($payload, 'matrices') as $matrix) {
            $key = self::string($matrix, 'matrix_key');
            $kind = self::string($matrix, 'matrix_kind');
            if (isset($matrixKeys[$key]) || !isset($kindCounts[$kind])) {
                throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatný druh.");
            }
            $matrixKeys[$key] = true;
            ++$kindCounts[$kind];
            self::positiveInt($matrix, 'source_header_row');
            self::string($matrix, 'source_sheet');
            self::nullableString($matrix, 'selector_raw');
            $requirements = self::rows($matrix, 'requirements');
            if (($matrix['row_count'] ?? null) !== count($requirements)
                || !hash_equals(
                    self::string($matrix, 'matrix_hash'),
                    hash('sha256', CanonicalJson::encode(['requirements' => $requirements])),
                )
            ) {
                throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatný obsahový hash.");
            }
            $seen = [];
            foreach ($requirements as $row) {
                $attributeId = self::string($row, 'attribute_id');
                if (!isset($knownAttributes[$attributeId]) || isset($seen[$attributeId])) {
                    throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatný atribut.");
                }
                $seen[$attributeId] = true;
                self::positiveInt($row, 'source_row');
                if (preg_match('/^.+![A-Z]+[0-9]+$/u', self::string($row, 'source_cell')) !== 1) {
                    throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatnou souřadnici.");
                }
                $requirement = JmhzFieldRequirementKind::from(self::string($row, 'requirement_kind'));
                $raw = self::string($row, 'requirement_raw');
                $note = self::nullableString($row, 'condition_note_raw');
                $expectedRaw = match ($requirement) {
                    JmhzFieldRequirementKind::Required => 'P',
                    JmhzFieldRequirementKind::Optional => 'N',
                    JmhzFieldRequirementKind::Conditional => 'NSP',
                };
                if ($raw !== $expectedRaw
                    || (($requirement === JmhzFieldRequirementKind::Conditional) !== ($note !== null))
                ) {
                    throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatnou povinnost.");
                }
                $effect = JmhzFieldEffect::from(self::string($row, 'effect_kind'));
                $effectRaw = self::nullableString($row, 'effect_raw');
                $expectedEffectRaw = match ($effect) {
                    JmhzFieldEffect::Add => '+',
                    JmhzFieldEffect::Remove => '-',
                    default => null,
                };
                if ($effectRaw !== $expectedEffectRaw) {
                    throw new \UnexpectedValueException("Matice JMHZ {$key} má neplatný efekt.");
                }
                self::nullableString($row, 'translation_raw');
                self::verifyRowHash($row, "povinnost {$key}/{$attributeId}");
                ++$requirementCounts[$requirement->value];
                if (isset($effectCounts[$effect->value])) {
                    ++$effectCounts[$effect->value];
                }
                ++$requirementCount;
            }
            self::verifyRowHash($matrix, "matice {$key}");
        }

        return [
            'matrices' => count($matrixKeys),
            'part_matrices' => $kindCounts['part'],
            'scenario_matrices' => $kindCounts['scenario'],
            'foundation_matrices' => $kindCounts['foundation'],
            'interaction_matrices' => $kindCounts['interaction'],
            'requirements' => $requirementCount,
            'required_requirements' => $requirementCounts['required'],
            'optional_requirements' => $requirementCounts['optional'],
            'conditional_requirements' => $requirementCounts['conditional'],
            'add_effects' => $effectCounts['add'],
            'remove_effects' => $effectCounts['remove'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, true> $knownAttributes
     * @return array<string, int>
     */
    private static function validateMasterAxis(array $payload, array $knownAttributes): array
    {
        $axis = [];
        foreach (self::rows($payload, 'master_attribute_axis') as $row) {
            $attributeId = self::string($row, 'attribute_id');
            $ordinal = self::positiveInt($row, 'ordinal');
            if (!isset($knownAttributes[$attributeId]) || isset($axis[$attributeId])
                || $ordinal !== count($axis) + 1
            ) {
                throw new \UnexpectedValueException('MASTER osa katalogu scénářů JMHZ není úplná.');
            }
            self::positiveInt($row, 'source_row');
            self::verifyRowHash($row, "MASTER atribut {$attributeId}");
            $axis[$attributeId] = $ordinal;
        }

        return $axis;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $masterAttributes
     * @return array<string, int>
     */
    private static function validateEvidence(array $payload, array $masterAttributes): array
    {
        $keys = [];
        $reconciliation = 0;
        $derived = 0;
        $members = 0;
        $zeroAxes = 0;
        $reconciliationNonempty = 0;
        $derivedZeros = 0;
        $derivedBlanks = 0;
        foreach (self::rows($payload, 'evidence_axes') as $axis) {
            $key = self::string($axis, 'axis_key');
            $kind = self::string($axis, 'axis_kind');
            if (isset($keys[$key]) || !in_array($kind, ['reconciliation', 'derived_binary'], true)) {
                throw new \UnexpectedValueException("Důkazní osa JMHZ {$key} má neplatný druh.");
            }
            $keys[$key] = true;
            $kind === 'derived_binary' ? ++$derived : ++$reconciliation;
            self::string($axis, 'source_column');
            $sourceSheet = self::string($axis, 'source_sheet');
            self::string($axis, 'label_raw');
            self::nullableString($axis, 'expected_matrix_key');
            $effect = self::nullableString($axis, 'expected_effect');
            if ($effect !== null) {
                JmhzFieldEffect::from($effect);
            }
            foreach ([
                'dimension_count', 'explicit_cell_count', 'nonempty_count', 'blank_count',
                'zero_count', 'one_count', 'dictionary_formula_count', 'master_match_count',
                'master_mismatch_count',
            ] as $field) {
                if (!is_int($axis[$field] ?? null) || $axis[$field] < 0) {
                    throw new \UnexpectedValueException("Důkazní osa JMHZ {$key} má neplatné počty.");
                }
            }
            if ($axis['dimension_count'] !== count($masterAttributes)
                || $axis['explicit_cell_count'] + $axis['blank_count'] !== $axis['dimension_count']
                || $axis['nonempty_count'] > $axis['explicit_cell_count']
                || preg_match('/^[0-9a-f]{64}$/', self::string($axis, 'raw_vector_sha256')) !== 1
                || !in_array(self::string($axis, 'reconciliation_status'), [
                    'match', 'known_anomaly', 'not_applicable',
                ], true)
            ) {
                throw new \UnexpectedValueException("Důkazní osa JMHZ {$key} není úplná.");
            }
            $axisMembers = self::rows($axis, 'members');
            if ($kind === 'reconciliation') {
                if ($axisMembers !== [] || $sourceSheet !== 'SLOVNÍK'
                    || $axis['nonempty_count'] !== $axis['explicit_cell_count']
                    || $axis['zero_count'] !== 0 || $axis['one_count'] !== 0
                    || $axis['master_match_count'] + $axis['master_mismatch_count']
                        !== $axis['dimension_count']
                    || preg_match('/^[0-9a-f]{64}$/', (string) self::nullableString(
                        $axis,
                        'dictionary_formula_vector_sha256',
                    )) !== 1
                    || preg_match('/^[0-9a-f]{64}$/', (string) self::nullableString(
                        $axis,
                        'dictionary_cached_vector_sha256',
                    )) !== 1
                ) {
                    throw new \UnexpectedValueException("Rekonciliační osa JMHZ {$key} není auditovatelná.");
                }
                $reconciliationNonempty += $axis['nonempty_count'];
            }
            if ($kind === 'derived_binary') {
                if ($sourceSheet !== 'MASTER' || $axis['blank_count'] !== 0
                    || $axis['zero_count'] + $axis['one_count'] !== $axis['dimension_count']
                    || $axis['nonempty_count'] !== $axis['one_count']
                    || $axis['dictionary_formula_count'] !== 0
                    || self::nullableString($axis, 'dictionary_formula_vector_sha256') !== null
                    || self::nullableString($axis, 'dictionary_cached_vector_sha256') !== null
                    || $axis['master_match_count'] !== 0 || $axis['master_mismatch_count'] !== 0
                    || count($axisMembers) !== $axis['one_count']
                ) {
                    throw new \UnexpectedValueException("Odvozená osa JMHZ {$key} není fail-closed.");
                }
                $zeroAxes += $axis['one_count'] === 0 ? 1 : 0;
                $derivedZeros += $axis['zero_count'];
                $derivedBlanks += $axis['blank_count'];
                if ($key === 'cy' && ($axis['one_count'] !== 83 || $axis['zero_count'] !== 359)) {
                    throw new \UnexpectedValueException('Odvozená osa IN37 nemá očekávaný zdrojový rozpor.');
                }
            }
            $seen = [];
            foreach ($axisMembers as $member) {
                $attributeId = self::string($member, 'attribute_id');
                if (!isset($masterAttributes[$attributeId]) || isset($seen[$attributeId])
                    || self::positiveInt($member, 'ordinal') !== $masterAttributes[$attributeId]
                    || !in_array(self::string($member, 'raw_type'), ['n', 's'], true)
                    || self::string($member, 'raw_value') !== '1'
                ) {
                    throw new \UnexpectedValueException("Odvozená osa JMHZ {$key} má neplatný člen.");
                }
                self::string($member, 'source_cell');
                self::verifyRowHash($member, "člen osy {$key}/{$attributeId}");
                $seen[$attributeId] = true;
                ++$members;
            }
            self::verifyRowHash($axis, "důkazní osa {$key}");
        }

        return [
            'reconciliation_axes' => $reconciliation,
            'reconciliation_nonempty_cells' => $reconciliationNonempty,
            'derived_axes' => $derived,
            'derived_one_cells' => $members,
            'derived_zero_cells' => $derivedZeros,
            'derived_blank_cells' => $derivedBlanks,
            'derived_zero_axes' => $zeroAxes,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private static function validateSourceOnlyEvidence(array $payload): array
    {
        $projectionStatuses = ['match' => 0, 'known_anomaly' => 0];
        foreach (self::rows($payload, 'projection_checks') as $row) {
            $status = self::string($row, 'status');
            if (!isset($projectionStatuses[$status])) {
                throw new \UnexpectedValueException('Projekční důkaz JMHZ má neplatný stav.');
            }
            self::string($row, 'scenario_key');
            self::string($row, 'source_sheet');
            self::string($row, 'source_column');
            self::nonNegativeInt($row, 'dimension_count');
            self::nullableString($row, 'expected_axis_key');
            self::string($row, 'raw_vector_sha256');
            self::verifyRowHash($row, 'projekční důkaz');
            ++$projectionStatuses[$status];
        }
        if ($projectionStatuses !== ['match' => 41, 'known_anomaly' => 9]) {
            throw new \UnexpectedValueException('Projekční důkazy JMHZ neodpovídají známému zdroji.');
        }

        $expectedAnomalies = [
            'duplicated_activity_selector_tokens',
            'rich_text_business_description',
            'shifted_interaction_columns',
            'interaction_without_matrix',
            'empty_interaction_matrix',
            'derived_header_trailing_digit',
            'leading_whitespace_header',
            'generated_empty_column_header',
            'pvpoj_header_drift',
            'manually_materialized_reconciliation_cells',
            'formula_holes',
        ];
        $actualAnomalies = [];
        foreach (self::rows($payload, 'anomalies') as $row) {
            $actualAnomalies[] = self::string($row, 'kind');
            self::strings($row, 'source_cells');
            if (!is_array($row['raw_details'] ?? null)) {
                throw new \UnexpectedValueException('Anomálie JMHZ nemá zdrojové detaily.');
            }
            self::verifyRowHash($row, 'zdrojová anomálie');
        }
        if ($actualAnomalies !== $expectedAnomalies) {
            throw new \UnexpectedValueException('Seznam známých anomálií JMHZ se změnil.');
        }

        return [
            'projection_checks' => array_sum($projectionStatuses),
            'anomalies' => count($actualAnomalies),
        ];
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private static function decode(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Nelze načíst manifest katalogu scénářů JMHZ.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest katalogu scénářů JMHZ nemá očekávanou strukturu.');
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
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není kladné číslo.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není nezáporné číslo.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není neprázdný text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není text.");
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
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ má neplatný řádek.");
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
            throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \UnexpectedValueException("Pole {$field} katalogu scénářů JMHZ obsahuje neplatný text.");
            }
        }

        return $value;
    }
}
