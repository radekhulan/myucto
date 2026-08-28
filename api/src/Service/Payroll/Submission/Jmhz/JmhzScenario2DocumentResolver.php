<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzScenario2DocumentResolver
{
    /** @var list<string>|null */
    private ?array $requiredAttributeIds = null;

    /** @var array<string,string>|null */
    private ?array $scenarioEvidence = null;

    public function resolve(JmhzVerifiedPreparationSnapshot $preparation): JmhzScenario2Resolution
    {
        if ($preparation->builderVersion !== JmhzPreparationSnapshotBuilder::BUILDER_VERSION) {
            return new JmhzScenario2Resolution(null, [
                $this->blocker(
                    'jmhz_scenario2_source_version_unsupported',
                    'preparation',
                    $preparation->id,
                ),
            ]);
        }

        $scope = $this->object($preparation->payload['scope'] ?? null);
        if (!$this->hasScenarioTwo($scope['scenario_set'] ?? null)) {
            return new JmhzScenario2Resolution(null, [
                $this->blocker(
                    'jmhz_scenario2_scope_unsupported',
                    'preparation',
                    $preparation->id,
                ),
            ]);
        }

        $forms = [];
        $blockers = [];
        foreach ($this->rows($preparation->payload['people'] ?? null) as $person) {
            $employeeId = $person['employee_id'] ?? null;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $resolution = $this->object($employment['scenario_resolution'] ?? null);
                if (($resolution['scenario_key'] ?? null) !== 'scenario_2') {
                    continue;
                }

                $employmentId = $employment['employment_id'] ?? null;
                if (!is_int($employeeId) || $employeeId <= 0
                    || !is_int($employmentId) || $employmentId <= 0
                    || !$this->isFrozenFosterCarerResolution($resolution)
                ) {
                    $blockers[] = $this->blocker(
                        'jmhz_scenario2_frozen_resolution_invalid',
                        'employment',
                        is_int($employmentId) && $employmentId > 0 ? $employmentId : null,
                    );
                    continue;
                }

                $forms[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'activity_code' => 'M',
                    'scenario_evidence' => $this->frozenScenarioEvidence($resolution),
                ];
                $blockers[] = $this->blocker(
                    'jmhz_scenario2_evidence_gap',
                    'employment',
                    $employmentId,
                    $this->requiredAttributeIds(),
                );
            }
        }

        if ($forms === []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario2_frozen_resolution_missing',
                'preparation',
                $preparation->id,
            );
            return new JmhzScenario2Resolution(null, $this->normalizeBlockers($blockers));
        }

        usort($forms, static fn (array $left, array $right): int => [
            $left['employee_id'], $left['employment_id'],
        ] <=> [
            $right['employee_id'], $right['employment_id'],
        ]);

        $specification = $this->object($preparation->payload['specification'] ?? null);
        $candidate = new JmhzScenario2NormalizedDocument([
            'schema_reference' => JmhzScenario2NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => [
                'scenario_key' => 'scenario_2',
                'period_start' => $scope['period_start'] ?? null,
                'period_end' => $scope['period_end'] ?? null,
            ],
            'preparation_provenance' => [
                'builder_version' => $preparation->builderVersion,
                'source_manifest_sha256' => $preparation->sourceManifestSha256,
                'readiness_sha256' => $preparation->readinessSha256,
                'snapshot_fingerprint' => $preparation->snapshotFingerprint,
            ],
            'specification' => [
                'package_key' => $specification['package_key'] ?? null,
                'spec_manifest_sha256' => $specification['spec_manifest_sha256'] ?? null,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'xsd_entrypoint' => 'formPestoun.xsd',
            ],
            'forms' => $forms,
        ]);

        return new JmhzScenario2Resolution($candidate, $this->normalizeBlockers($blockers));
    }

    /** @return list<string> */
    private function requiredAttributeIds(): array
    {
        if ($this->requiredAttributeIds !== null) {
            return $this->requiredAttributeIds;
        }

        $attributeIds = [];
        foreach (JmhzScenarioRequirementSourceCatalog::load()
            ->requirementsForMatrix('scenario_2') as $requirement) {
            if ($requirement->requirement === JmhzFieldRequirementKind::Required) {
                $attributeIds[] = $requirement->attributeId;
            }
        }
        sort($attributeIds, SORT_STRING);

        return $this->requiredAttributeIds = $attributeIds;
    }

    /** @param array<string,mixed> $resolution */
    private function isFrozenFosterCarerResolution(array $resolution): bool
    {
        return $this->frozenScenarioEvidence($resolution) !== [];
    }

    /** @param array<string,mixed> $resolution @return array<string,string|null> */
    private function frozenScenarioEvidence(array $resolution): array
    {
        foreach ($this->scenarioEvidence() as $key => $expected) {
            $value = $resolution[$key] ?? null;
            if (!is_string($value) || $value !== $expected) {
                return [];
            }
        }
        $relationshipDetailCode = $resolution['relationship_detail_code'] ?? null;
        if ($relationshipDetailCode !== null && !is_string($relationshipDetailCode)) {
            return [];
        }
        if (($resolution['manual_scenario_key'] ?? null) !== null) {
            return [];
        }

        return [
            'scenario_key' => 'scenario_2',
            'activity_code' => 'M',
            'relationship_detail_code' => $relationshipDetailCode,
            'manual_scenario_key' => null,
            ...$this->scenarioEvidence(),
        ];
    }

    /** @return array<string,string> */
    private function scenarioEvidence(): array
    {
        if ($this->scenarioEvidence !== null) {
            return $this->scenarioEvidence;
        }

        $payload = JmhzScenarioRequirementSourceCatalog::load()->manifest()['payload'];
        $scenario = $this->rowByKey($payload['scenarios'] ?? null, 'scenario_key', 'scenario_2');
        $matrix = $this->rowByKey($payload['matrices'] ?? null, 'matrix_key', 'scenario_2');

        return $this->scenarioEvidence = [
            'activity_code' => 'M',
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'scenario_row_sha256' => $this->string($scenario['row_hash'] ?? null),
            'matrix_sha256' => $this->string($matrix['matrix_hash'] ?? null),
            'matrix_row_sha256' => $this->string($matrix['row_hash'] ?? null),
            'xsd_entrypoint' => 'formPestoun.xsd',
            'selection_kind' => 'activity_raw',
            'matrix_source_sheet' => 'M',
        ];
    }

    /** @return array<string,mixed> */
    private function rowByKey(mixed $rows, string $field, string $value): array
    {
        foreach ($this->rows($rows) as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }
        throw new \UnexpectedValueException('Připnutý katalog JMHZ nemá očekávaný scénář.');
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException('Připnutý katalog JMHZ má neplatný důkaz scénáře.');
        }

        return $value;
    }

    private function hasScenarioTwo(mixed $scenarioSet): bool
    {
        return is_array($scenarioSet) && array_is_list($scenarioSet)
            && in_array('scenario_2', $scenarioSet, true);
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row) && !array_is_list($row),
        ));
    }

    /** @param list<string> $attributeIds */
    private function blocker(
        string $code,
        string $entityType,
        ?int $entityId,
        array $attributeIds = [],
    ): JmhzScenario1Blocker {
        sort($attributeIds, SORT_STRING);

        return new JmhzScenario1Blocker($code, $entityType, $entityId, $attributeIds);
    }

    /** @param list<JmhzScenario1Blocker> $blockers @return list<JmhzScenario1Blocker> */
    private function normalizeBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $key = $blocker->code . '|' . $blocker->entityType . '|'
                . ($blocker->entityId ?? '') . '|' . implode(',', $blocker->attributeIds);
            $unique[$key] = $blocker;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
