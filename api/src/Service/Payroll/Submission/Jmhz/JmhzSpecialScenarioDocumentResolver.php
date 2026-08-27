<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * P0 náhled scénářů 3–7: výhradně promítne zmrazené zařazení a důkaz
 * katalogu. Hodnoty formulářů se nedopočítávají ani nedoplňují odhadem.
 */
final class JmhzSpecialScenarioDocumentResolver
{
    /** @var array<string,true> */
    private const SCENARIOS = [
        'scenario_3' => true,
        'scenario_4' => true,
        'scenario_5' => true,
        'scenario_6' => true,
        'scenario_7' => true,
    ];

    /** @var array<string,list<string>>|null */
    private ?array $requiredAttributeIds = null;

    private ?JmhzScenarioSelectorResolver $selector = null;

    public function resolve(JmhzVerifiedPreparationSnapshot $preparation): ?JmhzSpecialScenarioResolution
    {
        $scope = $this->object($preparation->payload['scope'] ?? null);
        if (!$this->hasSpecialScenario($scope['scenario_set'] ?? null)) {
            return null;
        }
        if ($preparation->builderVersion !== JmhzPreparationSnapshotBuilder::BUILDER_VERSION) {
            return new JmhzSpecialScenarioResolution(null, [
                $this->blocker('jmhz_special_scenarios_source_version_unsupported', 'preparation', $preparation->id),
            ]);
        }

        $forms = [];
        $blockers = [];
        foreach ($this->rows($preparation->payload['people'] ?? null) as $person) {
            $employeeId = $person['employee_id'] ?? null;
            foreach ($this->rows($person['employments'] ?? null) as $employment) {
                $resolution = $this->object($employment['scenario_resolution'] ?? null);
                $scenarioKey = $resolution['scenario_key'] ?? null;
                if (!is_string($scenarioKey) || !isset(self::SCENARIOS[$scenarioKey])) {
                    continue;
                }

                $employmentId = $employment['employment_id'] ?? null;
                $evidence = $this->frozenScenarioEvidence($resolution, $scenarioKey);
                if (!is_int($employeeId) || $employeeId <= 0
                    || !is_int($employmentId) || $employmentId <= 0
                    || $evidence === null
                ) {
                    $blockers[] = $this->blocker(
                        'jmhz_special_scenarios_frozen_resolution_invalid',
                        'employment',
                        is_int($employmentId) && $employmentId > 0 ? $employmentId : null,
                    );
                    continue;
                }

                $forms[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'scenario_evidence' => $evidence,
                ];
                $blockers[] = $this->blocker(
                    'jmhz_special_scenarios_evidence_gap',
                    'employment',
                    $employmentId,
                    $this->requiredAttributeIds($scenarioKey),
                );
            }
        }

        if ($forms === []) {
            $blockers[] = $this->blocker(
                'jmhz_special_scenarios_frozen_resolution_missing',
                'preparation',
                $preparation->id,
            );

            return new JmhzSpecialScenarioResolution(null, $this->normalizeBlockers($blockers));
        }

        usort($forms, static fn (array $left, array $right): int => [
            $left['employee_id'], $left['employment_id'], $left['scenario_evidence']['scenario_key'],
        ] <=> [
            $right['employee_id'], $right['employment_id'], $right['scenario_evidence']['scenario_key'],
        ]);

        $specification = $this->object($preparation->payload['specification'] ?? null);
        $scenarioKeys = array_values(array_unique(array_map(
            static fn (array $form): string => $form['scenario_evidence']['scenario_key'],
            $forms,
        )));
        sort($scenarioKeys, SORT_STRING);

        return new JmhzSpecialScenarioResolution(
            new JmhzSpecialScenarioNormalizedDocument([
                'schema_reference' => JmhzSpecialScenarioNormalizedDocument::SCHEMA_REFERENCE,
                'scope' => [
                    'scenario_keys' => $scenarioKeys,
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
                ],
                'forms' => $forms,
            ]),
            $this->normalizeBlockers($blockers),
        );
    }

    /** @param array<string,mixed> $resolution @return array<string,mixed>|null */
    private function frozenScenarioEvidence(array $resolution, string $scenarioKey): ?array
    {
        $activityCode = $resolution['activity_code'] ?? null;
        $relationshipDetailCode = $resolution['relationship_detail_code'] ?? null;
        if (!is_string($activityCode) || $activityCode === ''
            || ($relationshipDetailCode !== null && !is_string($relationshipDetailCode))
            || ($resolution['manual_scenario_key'] ?? null) !== null
        ) {
            return null;
        }

        $selection = $this->selector()->resolve($activityCode, $relationshipDetailCode);
        $evidence = $selection['evidence'] ?? null;
        if (!$selection['supported'] || !is_array($evidence)
            || ($evidence['scenario_key'] ?? null) !== $scenarioKey
            || $resolution !== $evidence
        ) {
            return null;
        }

        return $evidence;
    }

    /** @return list<string> */
    private function requiredAttributeIds(string $scenarioKey): array
    {
        if ($this->requiredAttributeIds === null) {
            $this->requiredAttributeIds = [];
            $catalog = JmhzScenarioRequirementSourceCatalog::load();
            foreach (array_keys(self::SCENARIOS) as $key) {
                $attributeIds = [];
                foreach ($catalog->requirementsForMatrix($key) as $requirement) {
                    if ($requirement->requirement === JmhzFieldRequirementKind::Required) {
                        $attributeIds[] = $requirement->attributeId;
                    }
                }
                sort($attributeIds, SORT_STRING);
                $this->requiredAttributeIds[$key] = $attributeIds;
            }
        }

        return $this->requiredAttributeIds[$scenarioKey];
    }

    private function selector(): JmhzScenarioSelectorResolver
    {
        return $this->selector ??= JmhzScenarioSelectorResolver::load();
    }

    private function hasSpecialScenario(mixed $scenarioSet): bool
    {
        if (!is_array($scenarioSet) || !array_is_list($scenarioSet)) {
            return false;
        }
        foreach ($scenarioSet as $scenarioKey) {
            if (is_string($scenarioKey) && isset(self::SCENARIOS[$scenarioKey])) {
                return true;
            }
        }

        return false;
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
