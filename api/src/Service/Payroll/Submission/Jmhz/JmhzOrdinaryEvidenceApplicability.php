<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzOrdinaryEvidenceApplicability
{
    private ?JmhzScenarioSelectorResolver $scenarioSelector = null;

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $revision
     * @param array<string,mixed> $term
     */
    public function assertApplicable(
        array $evidence,
        int $supplierId,
        array $revision,
        int $employeeId,
        int $employmentId,
        array $term,
    ): void {
        $this->positiveInt($evidence['id'] ?? null, 'ordinary_evidence.id');
        $this->hash($evidence['source_manifest_sha256'] ?? null, 'ordinary_evidence.source_manifest_sha256');
        $this->hash($evidence['snapshot_fingerprint'] ?? null, 'ordinary_evidence.snapshot_fingerprint');
        $payload = $this->object($evidence['payload'] ?? null, 'ordinary_evidence.payload');
        $scope = $this->object($payload['scope'] ?? null, 'ordinary_evidence.scope');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        $periodStart = $revision['period_start'] ?? null;
        if (!is_string($periodStart) || preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            $this->invalid('jmhz_source_invalid', 'revision.period_start neni platne obdobi.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');

        if (($payload['schema_reference'] ?? null) !== JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE
            || ($payload['builder_version'] ?? null) !== JmhzOrdinaryEvidenceBuilder::BUILDER_VERSION
            || ($scope['supplier_id'] ?? null) !== $supplierId
            || ($scope['run_id'] ?? null) !== $runId
            || ($scope['source_revision_id'] ?? null) !== $revisionId
            || ($scope['revision_no'] ?? null) !== $revisionNo
            || ($scope['period_start'] ?? null) !== $periodStart
            || ($scope['period_end'] ?? null) !== $periodEnd
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_scope_mismatch',
                'Ordinary evidence neodpovida pripravovane mzdove revizi.',
            );
        }
        if (($scope['employee_id'] ?? null) !== $employeeId
            || ($scope['employment_id'] ?? null) !== $employmentId
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_scope_mismatch',
                'Ordinary evidence neodpovida zmrazene osobe a pracovnimu vztahu.',
            );
        }

        $selection = $this->scenarioSelector()->resolve(
            is_string($term['activity_code'] ?? null) ? $term['activity_code'] : null,
            is_string($term['jmhz_relationship_detail_code'] ?? null)
                ? $term['jmhz_relationship_detail_code']
                : null,
        );
        $scenarioResolution = $selection['evidence'] ?? null;
        $expectedScenarioKey = is_array($scenarioResolution)
            ? ($scenarioResolution['scenario_key'] ?? null)
            : null;
        if (!in_array($expectedScenarioKey, ['scenario_1', 'scenario_3'], true)
            || ($scope['scenario_key'] ?? null) !== $expectedScenarioKey
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_selector_mismatch',
                'Neměnné potvrzení už neodpovídá klasifikaci JMHZ. Vytvořte a schvalte novou opravnou revizi; původní evidence zůstane zachovaná.',
            );
        }

        $specification = $this->object($payload['specification'] ?? null, 'ordinary_evidence.specification');
        $catalog = JmhzScenarioRequirementSourceCatalog::load();
        $requirementIds = $expectedScenarioKey === 'scenario_1' ? ['10116', '10546'] : ['10546'];
        $requirements = [];
        foreach ($catalog->requirementsForMatrix($expectedScenarioKey) as $requirement) {
            if (in_array($requirement->attributeId, $requirementIds, true)) {
                $requirements[$requirement->attributeId] = $requirement->rowHash;
            }
        }
        if (!JmhzOrdinaryEvidenceCompatibility::acceptsSpecification($specification)
            || count($requirements) !== count($requirementIds)
            || CanonicalJson::encode($specification['attribute_requirement_row_sha256'] ?? null) !== CanonicalJson::encode($requirements)) {
            $this->invalid(
                'jmhz_ordinary_evidence_specification_mismatch',
                'Uložené podklady byly připraveny podle jiné verze pravidel JMHZ. Zkontrolujte jejich použitelnost pro novou přípravu; již přijaté hlášení kvůli změně verze znovu neodesílejte.',
                $this->specificationVersions($specification),
            );
        }
        $sourceRevision = $this->object($payload['source_revision'] ?? null, 'ordinary_evidence.source_revision');
        foreach (['input_snapshot_hash', 'result_snapshot_hash', 'ruleset_manifest_hash'] as $field) {
            if (($sourceRevision[$field] ?? null) !== ($revision[$field] ?? null)) {
                $this->invalid(
                    'jmhz_ordinary_evidence_source_mismatch',
                    'Ordinary evidence nevychazi ze stejne mzdove revize.',
                );
            }
        }
        $attributeValues = $payload['attribute_values'] ?? null;
        /*
         * 10116 („vykazují se srážky ze mzdy") je odvozený údaj, ne potvrzená
         * skutečnost — obě hodnoty jsou platné. 10546 (sleva zaměstnavatele na
         * pojistném) profil dál podporuje jen jako „Ne".
         */
        // Číselný klíč pole je v PHP int, ne string — proto `array_key_exists`
        // s celočíselnými indexy, ne porovnání `array_keys()` proti stringům.
        if (!is_array($attributeValues)
            || count($attributeValues) !== 2
            || !array_key_exists(10116, $attributeValues)
            || !array_key_exists(10546, $attributeValues)
            || !is_bool($attributeValues[10116])
            || $attributeValues[10546] !== false
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_values_mismatch',
                'Ordinary evidence obsahuje nepodporovanou pravni skutecnost.',
            );
        }
        $expectedInteractions = [];
        foreach (['IN13', 'IN28', 'IN30'] as $interactionId) {
            $expectedInteractions[] = [
                'interaction_id' => $interactionId,
                'triggered' => false,
                'row_sha256' => $catalog->interaction($interactionId)->rowHash,
            ];
        }
        $expectedDerivedInteractions = [[
            'interaction_id' => 'IN36',
            'triggered' => false,
            'source_attribute_id' => '10546',
            'row_sha256' => $catalog->interaction('IN36')->rowHash,
        ]];
        $actualInteractions = $this->rows($payload['interaction_decisions'] ?? null, 'ordinary_evidence.interaction_decisions');
        $actualDerivedInteractions = $this->rows($payload['derived_interactions'] ?? null, 'ordinary_evidence.derived_interactions');
        if (CanonicalJson::encode(['rows' => $actualInteractions]) !== CanonicalJson::encode(['rows' => $expectedInteractions])
            || CanonicalJson::encode(['rows' => $actualDerivedInteractions]) !== CanonicalJson::encode(['rows' => $expectedDerivedInteractions])
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_interaction_mismatch',
                'Ordinary evidence neodpovida pripnutym interakcim JMHZ.',
            );
        }
        $confirmation = $this->object($payload['confirmation'] ?? null, 'ordinary_evidence.confirmation');
        $sourceKind = $confirmation['source_kind'] ?? null;
        $sourceIsValid = $sourceKind === 'explicit_confirmation';
        if ($sourceKind === 'derived_from_frozen_payroll_sources') {
            $sourceIsValid = ($confirmation['source_term_id'] ?? null) === ($term['id'] ?? null)
                && ($confirmation['source_term_row_version'] ?? null) === ($term['row_version'] ?? null);
        }
        if (!$sourceIsValid
            || !is_int($confirmation['confirmed_by_user_id'] ?? null)
            || $confirmation['confirmed_by_user_id'] <= 0
            || !is_string($confirmation['confirmed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $confirmation['confirmed_at']) !== 1
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_confirmation_invalid',
                'Ordinary evidence nema platne potvrzeni.',
            );
        }
    }

    private function scenarioSelector(): JmhzScenarioSelectorResolver
    {
        return $this->scenarioSelector ??= JmhzScenarioSelectorResolver::load();
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->invalid('jmhz_source_invalid', "{$field} obsahuje neplatny radek.");
            }
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt kladne cele cislo.");
        }
        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platny SHA-256.");
        }
        return $value;
    }

    private function specificationVersions(array $specification): array
    {
        $context = [];
        foreach (['stored' => $specification['package_key'] ?? '', 'current' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY] as $prefix => $key) {
            if (is_string($key) && preg_match('/^jmhz-xsd-([0-9.]+)_dictionary-[0-9.]+_controls-source-([0-9.]+)_manifest-v[0-9]+$/D', $key, $matches) === 1) {
                $context[$prefix . '_xsd'] = $matches[1];
                $context[$prefix . '_controls'] = $matches[2];
            }
        }
        return $context;
    }

    private function invalid(string $code, string $message, array $context = []): never
    {
        throw new JmhzOrdinaryEvidenceApplicabilityException($code, $message, $context);
    }
}
