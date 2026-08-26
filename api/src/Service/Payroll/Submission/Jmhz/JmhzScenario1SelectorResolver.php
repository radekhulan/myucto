<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;

final class JmhzScenario1SelectorResolver
{
    public const SCENARIO_ROW_SHA256 = '1c2264dfdd94ceb8a1b779ae5cf7640b372d4dc10c976cee15fe59707cf906c2';
    public const MATRIX_SHA256 = '87868f6bca2def1792ec3e40c26c76c94571da91471d381827be5873f60d55d7';
    public const MATRIX_ROW_SHA256 = '7b6f17ed16d42932200c63fdd2053b94ae698536d45c8d46243a8705fcd2c162';
    public const ACTIVITY_ATTRIBUTE_ROW_SHA256 = 'caea529f9522a657042bc3ffd463ef2edeb61a49919b297df64afd276264ea15';
    public const ACTIVITY_CODEBOOK_SHA256 = '5a7be096e1ee6fd23a029e65cbf874432a2103be159eccaf6fb373247be62a35';
    public const RELATIONSHIP_DETAIL_ATTRIBUTE_ROW_SHA256 = '09caed5c6db82c410d121f3d5a3120fca45175ce826bc5ed6c30e38317f1178d';
    public const RELATIONSHIP_DETAIL_CODEBOOK_SHA256 = '2a9a3bd677817dfdc88adc989d3076e2f08ac762f9793a84629b14a5426c3393';
    public const RELATIONSHIP_DETAIL_NONE_ROW_SHA256 = '4f0fd24b8ed85aa1c6dc57d1c468f718987bb2429ffbec231456375221f46567';

    private const DIRECT_ACTIVITY_CODES = [
        '15', '16',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
        'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC',
    ];

    private readonly JmhzCodebookCatalog $codebooks;

    /**
     * @param array{manifest_sha256:string,payload:array<string,mixed>} $specManifest
     */
    public function __construct(
        private readonly JmhzScenarioRequirementSourceCatalog $scenarios,
        private readonly array $specManifest,
    ) {
        $this->codebooks = new JmhzCodebookCatalog($specManifest);
        $this->verifyPinnedSource();
    }

    public static function load(): self
    {
        $packages = new JmhzSpecPackageCatalog();
        return new self(
            JmhzScenarioRequirementSourceCatalog::load(),
            $packages->load(
                JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            ),
        );
    }

    /**
     * @return array{
     *   supported:bool,
     *   issue_code:?string,
     *   attribute_ids:list<string>,
     *   evidence:?array<string,mixed>
     * }
     */
    public function resolve(?string $activityCode, ?string $relationshipDetailCode): array
    {
        if ($activityCode === null || $activityCode === '') {
            return $this->blocked('jmhz_scenario_activity_code_missing', ['10239']);
        }
        try {
            $this->codebooks->requireValue('druh_cinnosti', $activityCode);
        } catch (JmhzCodebookUnavailableException|JmhzCodebookValueException) {
            return $this->blocked('jmhz_scenario_activity_code_invalid', ['10239']);
        }
        if ($relationshipDetailCode !== null) {
            try {
                $this->codebooks->requireValue(
                    'blizsi_urceni_pracovnepravn',
                    $relationshipDetailCode,
                );
            } catch (JmhzCodebookUnavailableException|JmhzCodebookValueException) {
                return $this->blocked('jmhz_scenario_relationship_detail_invalid', ['10502']);
            }
        }
        try {
            $relationshipDetailCode = PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                $activityCode,
                $relationshipDetailCode,
            );
        } catch (\InvalidArgumentException) {
            return $this->blocked(
                $relationshipDetailCode === null
                    ? 'jmhz_scenario_relationship_detail_missing'
                    : 'jmhz_scenario_relationship_detail_not_applicable',
                ['10502'],
            );
        }

        if (in_array($activityCode, self::DIRECT_ACTIVITY_CODES, true)) {
            return $this->supported($activityCode, null);
        }
        if (preg_match('/^[1-9]$/D', $activityCode) === 1) {
            if ($relationshipDetailCode === null) {
                return $this->blocked('jmhz_scenario_relationship_detail_missing', ['10502']);
            }
            if ($relationshipDetailCode === '1') {
                return $this->supported($activityCode, $relationshipDetailCode);
            }
        }

        return $this->blocked(
            'jmhz_scenario_not_supported',
            ['10239', '10502'],
        );
    }

    /**
     * @param list<string> $attributeIds
     * @return array{supported:false,issue_code:string,attribute_ids:list<string>,evidence:null}
     */
    private function blocked(string $code, array $attributeIds): array
    {
        return [
            'supported' => false,
            'issue_code' => $code,
            'attribute_ids' => $attributeIds,
            'evidence' => null,
        ];
    }

    /** @return array{supported:true,issue_code:null,attribute_ids:list<string>,evidence:array<string,mixed>} */
    private function supported(string $activityCode, ?string $relationshipDetailCode): array
    {
        return [
            'supported' => true,
            'issue_code' => null,
            'attribute_ids' => [],
            'evidence' => [
                'scenario_key' => 'scenario_1',
                'activity_code' => $activityCode,
                'relationship_detail_code' => $relationshipDetailCode,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'scenario_row_sha256' => self::SCENARIO_ROW_SHA256,
                'matrix_sha256' => self::MATRIX_SHA256,
                'matrix_row_sha256' => self::MATRIX_ROW_SHA256,
                'activity_attribute_row_sha256' => self::ACTIVITY_ATTRIBUTE_ROW_SHA256,
                'activity_codebook_sha256' => self::ACTIVITY_CODEBOOK_SHA256,
                'relationship_detail_attribute_row_sha256' => self::RELATIONSHIP_DETAIL_ATTRIBUTE_ROW_SHA256,
                'relationship_detail_codebook_sha256' => self::RELATIONSHIP_DETAIL_CODEBOOK_SHA256,
                'relationship_detail_entry_row_sha256' => $relationshipDetailCode === '1'
                    ? self::RELATIONSHIP_DETAIL_NONE_ROW_SHA256
                    : null,
                'xsd_entrypoint' => 'formBezPriznaku.xsd',
            ],
        ];
    }

    private function verifyPinnedSource(): void
    {
        $scenario = $this->scenarios->scenario('scenario_1');
        $matrix = $this->scenarios->matrix('scenario_1');
        $manifest = $this->scenarios->manifest();
        $payload = $manifest['payload'];
        $scenarioRow = $this->findByKey($payload['scenarios'] ?? null, 'scenario_key', 'scenario_1');
        $matrixRow = $this->findByKey($payload['matrices'] ?? null, 'matrix_key', 'scenario_1');
        if ($scenario->xsdEntrypoint !== 'formBezPriznaku.xsd'
            || $scenario->selectionKind !== JmhzScenarioSelectionKind::ActivityRaw
            || $matrix->kind !== JmhzMatrixKind::Scenario
            || ($scenarioRow['row_hash'] ?? null) !== self::SCENARIO_ROW_SHA256
            || ($matrixRow['matrix_hash'] ?? null) !== self::MATRIX_SHA256
            || ($matrixRow['row_hash'] ?? null) !== self::MATRIX_ROW_SHA256
            || $this->attributeHash('10239') !== self::ACTIVITY_ATTRIBUTE_ROW_SHA256
            || $this->codebookHash('druh_cinnosti') !== self::ACTIVITY_CODEBOOK_SHA256
            || $this->attributeHash('10502') !== self::RELATIONSHIP_DETAIL_ATTRIBUTE_ROW_SHA256
            || $this->codebookHash('blizsi_urceni_pracovnepravn')
                !== self::RELATIONSHIP_DETAIL_CODEBOOK_SHA256
            || ($this->codebooks->requireValue('blizsi_urceni_pracovnepravn', '1')['row_hash'] ?? null)
                !== self::RELATIONSHIP_DETAIL_NONE_ROW_SHA256
        ) {
            throw new \UnexpectedValueException('Připnutý resolver scénáře 1 JMHZ neodpovídá zdrojovým katalogům.');
        }
    }

    /** @return array<string,mixed> */
    private function findByKey(mixed $rows, string $field, string $value): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException('Zdrojový katalog scénářů JMHZ nemá očekávaný tvar.');
        }
        foreach ($rows as $row) {
            if (is_array($row) && ($row[$field] ?? null) === $value) {
                return $row;
            }
        }
        throw new \UnexpectedValueException('Zdrojový katalog scénářů JMHZ nemá připnutý řádek.');
    }

    private function codebookHash(string $key): string
    {
        $rows = $this->specManifest['payload']['codebooks'] ?? null;
        $row = $this->findByKey($rows, 'codebook_key', $key);
        $hash = $row['content_hash'] ?? null;
        if (!is_string($hash)) {
            throw new \UnexpectedValueException('Číselník JMHZ nemá obsahový otisk.');
        }
        return $hash;
    }

    private function attributeHash(string $attributeId): string
    {
        $rows = $this->specManifest['payload']['dictionary_attributes'] ?? null;
        $row = $this->findByKey($rows, 'attribute_id', $attributeId);
        $hash = $row['row_hash'] ?? null;
        if (!is_string($hash)) {
            throw new \UnexpectedValueException('Atribut JMHZ nemá řádkový otisk.');
        }
        return $hash;
    }
}
