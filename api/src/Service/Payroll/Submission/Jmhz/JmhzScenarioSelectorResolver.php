<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzScenarioSelectorResolver
{
    private function __construct(
        private readonly JmhzScenarioRequirementSourceCatalog $scenarios,
        private readonly JmhzScenario1SelectorResolver $scenarioOne,
    ) {}

    public static function load(): self
    {
        return new self(
            JmhzScenarioRequirementSourceCatalog::load(),
            JmhzScenario1SelectorResolver::load(),
        );
    }

    /**
     * @return array{
     *   supported:bool,
     *   issue_code:?string,
     *   attribute_ids:list<string>,
     *   evidence:?array<string,mixed>,
     *   preparation_supported:bool,
     *   readiness_issue_code:?string,
     *   readiness_attribute_ids:list<string>
     * }
     */
    public function resolve(
        ?string $activityCode,
        ?string $relationshipDetailCode,
        ?string $manualScenarioKey = null,
    ): array {
        if ($manualScenarioKey !== null) {
            return $this->resolveManual(
                $activityCode,
                $relationshipDetailCode,
                $manualScenarioKey,
            );
        }

        $scenarioOne = $this->scenarioOne->resolve($activityCode, $relationshipDetailCode);
        if ($scenarioOne['supported']) {
            return [
                ...$scenarioOne,
                'preparation_supported' => true,
                'readiness_issue_code' => null,
                'readiness_attribute_ids' => [],
            ];
        }
        if ($scenarioOne['issue_code'] !== 'jmhz_scenario_not_supported') {
            return $this->blocked(
                (string) $scenarioOne['issue_code'],
                $scenarioOne['attribute_ids'],
            );
        }
        if ($activityCode === null || $activityCode === '') {
            throw new \UnexpectedValueException('Resolver scénáře 1 přijal neplatný druh činnosti.');
        }
        $scenarioKey = match (true) {
            $activityCode === 'M' => 'scenario_2',
            in_array($activityCode, ['K', 'N', 'O', 'P', 'Q', 'R', 'S'], true),
            preg_match('/^[1-9]$/D', $activityCode) === 1 && $relationshipDetailCode === '3'
                => 'scenario_3',
            preg_match('/^[1-9]$/D', $activityCode) === 1 && $relationshipDetailCode === '2'
                => 'scenario_4',
            in_array($activityCode, ['11', '13', '14'], true) => 'scenario_5',
            $activityCode === '12' => 'scenario_6',
            $activityCode === '10' => 'scenario_7',
            default => null,
        };
        if ($scenarioKey !== null) {
            return $this->classified($scenarioKey, $activityCode, $relationshipDetailCode, null);
        }

        return $this->blocked('jmhz_scenario_not_supported', ['10239', '10502']);
    }

    /**
     * @return array{
     *   supported:bool,
     *   issue_code:?string,
     *   attribute_ids:list<string>,
     *   evidence:?array<string,mixed>,
     *   preparation_supported:bool,
     *   readiness_issue_code:?string,
     *   readiness_attribute_ids:list<string>
     * }
     */
    private function resolveManual(
        ?string $activityCode,
        ?string $relationshipDetailCode,
        string $manualScenarioKey,
    ): array {
        if ($manualScenarioKey !== 'scenario_8') {
            return $this->blocked('jmhz_scenario_manual_selection_invalid', []);
        }
        $validated = $this->scenarioOne->resolve($activityCode, $relationshipDetailCode);
        if (!$validated['supported']
            && $validated['issue_code'] !== 'jmhz_scenario_not_supported'
        ) {
            return $this->blocked(
                (string) $validated['issue_code'],
                $validated['attribute_ids'],
            );
        }
        if ($activityCode === null || $activityCode === '') {
            throw new \UnexpectedValueException('Resolver scénáře 1 přijal neplatný druh činnosti.');
        }
        if ($activityCode === '10') {
            return $this->blocked('jmhz_scenario_8_activity_10_forbidden', ['10239', '10548']);
        }

        return $this->classified(
            'scenario_8',
            $activityCode,
            $relationshipDetailCode,
            $manualScenarioKey,
        );
    }

    /**
     * @return array{
     *   supported:true,issue_code:null,attribute_ids:list<string>,evidence:array<string,mixed>,
     *   preparation_supported:false,readiness_issue_code:string,readiness_attribute_ids:list<string>
     * }
     */
    private function classified(
        string $scenarioKey,
        string $activityCode,
        ?string $relationshipDetailCode,
        ?string $manualScenarioKey,
    ): array {
        $scenario = $this->scenarios->scenario($scenarioKey);
        $matrix = $this->scenarios->matrix($scenarioKey);
        $manifest = $this->scenarios->manifest()['payload'];
        $scenarioRow = $this->findByKey($manifest['scenarios'] ?? null, 'scenario_key', $scenarioKey);
        $matrixRow = $this->findByKey($manifest['matrices'] ?? null, 'matrix_key', $scenarioKey);
        $attributes = [];
        foreach ($this->scenarios->requirementsForMatrix($scenarioKey) as $requirement) {
            if ($requirement->requirement === JmhzFieldRequirementKind::Required) {
                $attributes[] = $requirement->attributeId;
            }
        }
        sort($attributes, SORT_STRING);

        return [
            'supported' => true,
            'issue_code' => null,
            'attribute_ids' => [],
            'evidence' => [
                'scenario_key' => $scenarioKey,
                'activity_code' => $activityCode,
                'relationship_detail_code' => $relationshipDetailCode,
                'manual_scenario_key' => $manualScenarioKey,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'scenario_row_sha256' => $scenarioRow['row_hash'],
                'matrix_sha256' => $matrixRow['matrix_hash'],
                'matrix_row_sha256' => $matrixRow['row_hash'],
                'xsd_entrypoint' => $scenario->xsdEntrypoint,
                'selection_kind' => $scenario->selectionKind->value,
                'matrix_source_sheet' => $matrix->sourceSheet,
            ],
            'preparation_supported' => false,
            'readiness_issue_code' => $scenarioKey === 'scenario_8'
                ? 'deferred_income_evidence_missing'
                : "jmhz_{$scenarioKey}_preparation_unsupported",
            'readiness_attribute_ids' => $attributes,
        ];
    }

    /**
     * @param list<string> $attributeIds
     * @return array{
     *   supported:false,issue_code:string,attribute_ids:list<string>,evidence:null,
     *   preparation_supported:false,readiness_issue_code:null,readiness_attribute_ids:list<string>
     * }
     */
    private function blocked(string $issueCode, array $attributeIds): array
    {
        return [
            'supported' => false,
            'issue_code' => $issueCode,
            'attribute_ids' => $attributeIds,
            'evidence' => null,
            'preparation_supported' => false,
            'readiness_issue_code' => null,
            'readiness_attribute_ids' => [],
        ];
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
}
