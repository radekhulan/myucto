<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;

final class JmhzPreparationSnapshotBuilder
{
    public const LEGACY_BUILDER_VERSION = 'jmhz-preparation-source.v1';
    public const PREVIOUS_V2_BUILDER_VERSION = 'jmhz-preparation-source.v2';
    public const PREVIOUS_BUILDER_VERSION = 'jmhz-preparation-source.v3';
    public const PREVIOUS_V4_BUILDER_VERSION = 'jmhz-preparation-source.v4';
    public const PREVIOUS_V5_BUILDER_VERSION = 'jmhz-preparation-source.v5';
    public const PREVIOUS_V6_BUILDER_VERSION = 'jmhz-preparation-source.v6';
    public const PREVIOUS_V7_BUILDER_VERSION = 'jmhz-preparation-source.v7';
    public const BUILDER_VERSION = 'jmhz-preparation-source.v8';

    private ?JmhzScenario1SelectorResolver $scenarioSelector = null;

    /**
     * @param array<string,mixed> $source
     * @param array<int,array<string,mixed>> $identitySources
     * @param array<int,array<string,mixed>> $mappingSources
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $sourceIssues
     * @param array<int,array<string,mixed>> $eldpSources
     * @param array<int,array<string,mixed>> $ordinaryEvidenceSources ordinary
     *        evidence podle `employment_id` — jedna na KAŽDÝ pracovní vztah
     *        revize, protože se zmrazuje per vztah (viz
     *        {@see JmhzOrdinaryEvidenceBuilder::build()}). Revize přes dvě
     *        mzdové účtárny má vždy ≥2 vztahy, takže jediná evidence na revizi
     *        by víceúčtárenské podání znemožnila.
     */
    public function build(
        int $supplierId,
        string $environment,
        array $source,
        array $identitySources,
        array $mappingSources,
        array $sourceIssues = [],
        array $eldpSources = [],
        array $ordinaryEvidenceSources = [],
    ): JmhzPreparationSnapshot {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musi byt kladne cislo.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            $this->invalid('jmhz_preparation_environment_invalid', 'Prostredi pripravy JMHZ neni platne.');
        }
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_revision_not_current_approved',
                'Priprava JMHZ vyzaduje aktualni schvalenou mzdovou revizi.',
            );
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_period_invalid', 'Obdobi JMHZ nezacina prvnim dnem mesice.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'revision.input_snapshot',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'revision.result_snapshot',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
        ) {
            $this->invalid(
                'jmhz_revision_input_mismatch',
                'Zmrazeny vstup neodpovida firme nebo obdobi JMHZ.',
            );
        }
        if (($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null)
                !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid(
                'jmhz_revision_result_mismatch',
                'Vysledek revize nevychazi ze stejneho zmrazeneho vstupu.',
            );
        }
        $resultPeople = $this->indexResultPeople($result);

        $issues = $sourceIssues;

        $normalizedPeople = [];
        $sourceVersions = [];
        $seenEmployments = [];
        $usedOrdinaryEvidence = [];
        foreach ($this->rows($input['people'] ?? null, 'input.people') as $personIndex => $person) {
            $employee = $this->object($person['employee'] ?? null, "input.people.{$personIndex}.employee");
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            $personResult = $resultPeople[$employeeId] ?? null;
            if (!is_array($personResult)) {
                $this->invalid(
                    'jmhz_result_person_set_mismatch',
                    'Vysledek revize nepokryva presne zmrazene osoby.',
                );
            }
            $employmentResults = $this->indexResultEmployments($personResult);
            $normalizedEmployments = [];
            $primaryEmploymentCount = 0;
            foreach ($this->rows($person['employments'] ?? null, "input.people.{$personIndex}.employments") as $employmentIndex => $entry) {
                $employment = $this->object(
                    $entry['employment'] ?? null,
                    "input.people.{$personIndex}.employments.{$employmentIndex}.employment",
                );
                $employmentId = $this->positiveInt($employment['id'] ?? null, 'employment.id');
                if (($employment['employee_id'] ?? null) !== $employeeId || isset($seenEmployments[$employmentId])) {
                    $this->invalid('jmhz_employment_scope_mismatch', 'Zmrazeny vztah nema jednoznacneho vlastnika.');
                }
                $seenEmployments[$employmentId] = true;
                $isPrimary = $employment['is_primary'] ?? null;
                if ($isPrimary === true) {
                    $primaryEmploymentCount++;
                } elseif ($isPrimary !== false) {
                    $issues[] = $this->issue(
                        'jmhz_primary_employment_unresolved',
                        'employment',
                        $employmentId,
                        ['10495'],
                    );
                }
                $term = $entry['term'] ?? null;
                $scenarioResolution = null;
                if (!is_array($term) || array_is_list($term)) {
                    $issues[] = $this->issue('effective_term_missing', 'employment', $employmentId);
                } else {
                    $this->inspectTerm($term, $employmentId, $issues);
                    $selection = $this->scenarioSelector()->resolve(
                        is_string($term['activity_code'] ?? null)
                            ? $term['activity_code']
                            : null,
                        is_string($term['jmhz_relationship_detail_code'] ?? null)
                            ? $term['jmhz_relationship_detail_code']
                            : null,
                    );
                    if (!$selection['supported']) {
                        $issueCode = $selection['issue_code'];
                        if (!is_string($issueCode)) {
                            throw new \UnexpectedValueException('Resolver scénáře JMHZ nevrátil blocker.');
                        }
                        $issues[] = $this->issue(
                            $issueCode,
                            'employment',
                            $employmentId,
                            $selection['attribute_ids'],
                        );
                    } else {
                        $scenarioResolution = $selection['evidence'];
                    }
                }
                $this->inspectWorkMonth($entry['time_month'] ?? null, $employmentId, $issues);
                $averageEarning = $this->inspectAverageEarning(
                    $entry['average_earning'] ?? null,
                    $employmentId,
                    $periodStart,
                    $issues,
                );
                $workSummary = is_array($entry['time_month'] ?? null)
                    ? ($entry['time_month']['jmhz_work_summary'] ?? null)
                    : null;
                $eldp = $eldpSources[$employmentId] ?? null;
                if (!is_array($eldp)) {
                    if (!$this->hasSpecificEldpIssue($sourceIssues, $employmentId)) {
                        $issues[] = $this->issue('jmhz_eldp_evidence_missing', 'employment', $employmentId, [
                            '10240', '10241', '10242', '10245', '10354', '10355',
                            '10356', '10357', '10358', '10359', '10360', '10362',
                            '10536', '10366', '10375', '10462', '10463', '10464',
                            '10465', '10466', '10468', '10469', '10473', '10474',
                            '10475',
                        ]);
                    }
                    $eldp = null;
                } else {
                    $this->assertEldpSource(
                        $eldp,
                        $supplierId,
                        $revision,
                        $employeeId,
                        $employmentId,
                        $periodStart,
                        $term,
                        $workSummary,
                    );
                }
                // Ordinary evidence je stejně jako ELDP zmrazená per vztah:
                // chybějící evidence je ADRESNÝ nález na vztahu, ne globální
                // nález na revizi — účetní tak ví, komu ji má doplnit.
                $ordinary = $ordinaryEvidenceSources[$employmentId] ?? null;
                if (!is_array($ordinary)) {
                    $issues[] = $this->issue(
                        'jmhz_ordinary_evidence_missing',
                        'employment',
                        $employmentId,
                        [
                            '10116', '10546', '10408', '10409', '10410',
                            '10347', '10348', '10349', '10270', '10271', '10272',
                        ],
                    );
                    $ordinary = null;
                } else {
                    $this->assertOrdinaryEvidence(
                        $ordinary,
                        $supplierId,
                        $runId,
                        $revisionId,
                        $revisionNo,
                        $periodStart,
                        $periodEnd,
                        $revision,
                        $employeeId,
                        $employmentId,
                        $term,
                    );
                    $usedOrdinaryEvidence[$employmentId] = $ordinary;
                }
                $componentMappings = [];
                $earnings = [];
                foreach ($this->rows($entry['inputs'] ?? null, 'employment.inputs') as $inputRow) {
                    $component = $this->object($inputRow['component'] ?? null, 'input.component');
                    $componentId = $this->positiveInt($component['component_id'] ?? null, 'component.component_id');
                    if (!hash_equals(
                        $this->hash(
                            $inputRow['component_snapshot_hash'] ?? null,
                            'input.component_snapshot_hash',
                        ),
                        hash('sha256', CanonicalJson::encode($component)),
                    )) {
                        $this->invalid(
                            'jmhz_component_snapshot_hash_mismatch',
                            'Otisk snapshotu mzdove slozky nesouhlasi.',
                        );
                    }
                    $treatment = $component['jmhz_treatment'] ?? null;
                    if ($treatment === 'manual_review') {
                        $issues[] = $this->issue('component_jmhz_manual_review', 'component', $componentId);
                    } elseif ($treatment === 'included') {
                        $mapping = $mappingSources[$componentId] ?? null;
                        if (!is_array($mapping)) {
                            $issues[] = $this->issue('component_jmhz_mapping_missing', 'component', $componentId);
                        } else {
                            $this->assertMapping($mapping, $componentId);
                            $componentMappings[] = $mapping;
                            $amount = $inputRow['amount_minor'] ?? null;
                            if (!is_int($amount) || $amount < 0) {
                                $issues[] = $this->issue(
                                    'jmhz_negative_or_deferred_income_unsupported',
                                    'component',
                                    $componentId,
                                );
                            } else {
                                $targets = [
                                    (string) $mapping['target_attribute_id'],
                                    ...$this->stringList(
                                        $mapping['ancestor_attribute_ids'] ?? null,
                                        'mapping.ancestor_attribute_ids',
                                    ),
                                ];
                                foreach (array_values(array_unique($targets)) as $target) {
                                    $earnings[$target] = $this->checkedAdd(
                                        $earnings[$target] ?? 0,
                                        $amount,
                                    );
                                }
                            }
                        }
                    } elseif ($treatment !== 'excluded') {
                        $issues[] = $this->issue('component_jmhz_treatment_invalid', 'component', $componentId);
                    }
                }
                usort(
                    $componentMappings,
                    static fn (array $left, array $right): int =>
                        (int) ($left['component_definition_id'] ?? 0)
                        <=> (int) ($right['component_definition_id'] ?? 0),
                );
                $identity = $identitySources[$employmentId] ?? null;
                if (!is_array($identity)) {
                    $issues[] = $this->issue('jmhz_identity_incomplete', 'employment', $employmentId, ['10051', '10228']);
                    $identity = null;
                } else {
                    $this->assertIdentity(
                        $identity,
                        $environment,
                        $employeeId,
                        $employmentId,
                    );
                }
                $employmentResult = $employmentResults[$employmentId] ?? null;
                if (!is_array($employmentResult)) {
                    $this->invalid(
                        'jmhz_result_employment_set_mismatch',
                        'Vysledek revize nepokryva presne zmrazene pracovni vztahy.',
                    );
                }
                $insurance = $this->inspectDiscounts(
                    $personResult,
                    $employmentId,
                    $issues,
                );
                ksort($earnings, SORT_STRING);
                $normalizedEmployments[] = [
                    'employment_id' => $employmentId,
                    'identity' => $identity,
                    'employment' => $employment,
                    'term' => $term,
                    'scenario_resolution' => $scenarioResolution,
                    'eldp' => is_array($eldp) ? $eldp['payload'] : null,
                    'ordinary_evidence' => is_array($ordinary) ? $ordinary['payload'] : null,
                    'work_month' => $entry['time_month'] ?? null,
                    'average_earning' => $averageEarning,
                    'earnings_by_attribute_minor' => $earnings,
                    'insurance' => $insurance,
                    'calculation' => $employmentResult,
                    'component_mappings' => $componentMappings,
                ];
                $identityVersions = null;
                if (is_array($identity)) {
                    $identityVersions = [
                        'identity_id' => $identity['identity']['id'] ?? null,
                        'identity_row_version' =>
                            $identity['identity']['row_version'] ?? null,
                        'person_external_id' =>
                            $identity['person_external_identifier']['id'] ?? null,
                        'person_external_row_version' =>
                            $identity['person_external_identifier']['row_version'] ?? null,
                        'employment_external_id' =>
                            $identity['employment_external_identifier']['id'] ?? null,
                        'employment_external_row_version' =>
                            $identity['employment_external_identifier']['row_version'] ?? null,
                        'employment_external_source_reference_hash' =>
                            $identity['employment_external_identifier']['source_reference_hash'] ?? null,
                    ];
                }
                $mappingVersions = array_map(
                    static fn (array $mapping): array => [
                        'mapping_id' => $mapping['mapping_id'] ?? null,
                        'mapping_row_version' => $mapping['mapping_row_version'] ?? null,
                        'mapping_hash' => $mapping['mapping_hash'] ?? null,
                    ],
                    $componentMappings,
                );
                $sourceVersions[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'term_id' => is_array($term) ? ($term['id'] ?? null) : null,
                    'term_row_version' => is_array($term)
                        ? ($term['row_version'] ?? null)
                        : null,
                    'scenario_resolution' => is_array($scenarioResolution)
                        ? [
                            'scenario_row_sha256' =>
                                $scenarioResolution['scenario_row_sha256'] ?? null,
                            'matrix_sha256' =>
                                $scenarioResolution['matrix_sha256'] ?? null,
                            'matrix_row_sha256' =>
                                $scenarioResolution['matrix_row_sha256'] ?? null,
                        ]
                        : null,
                    'work_summary_id' => is_array($workSummary)
                        ? ($workSummary['id'] ?? null)
                        : null,
                    'work_summary_sha256' => is_array($workSummary)
                        ? ($workSummary['summary_sha256'] ?? null)
                        : null,
                    'average_earning_id' => is_array($averageEarning)
                        ? ($averageEarning['id'] ?? null)
                        : null,
                    'average_earning_row_version' => is_array($averageEarning)
                        ? ($averageEarning['row_version'] ?? null)
                        : null,
                    'average_earning_input_hash' => is_array($averageEarning)
                        ? ($averageEarning['input_hash'] ?? null)
                        : null,
                    'eldp_evidence_id' => is_array($eldp) ? ($eldp['id'] ?? null) : null,
                    'eldp_source_manifest_sha256' => is_array($eldp)
                        ? ($eldp['source_manifest_sha256'] ?? null)
                        : null,
                    'eldp_snapshot_fingerprint' => is_array($eldp)
                        ? ($eldp['snapshot_fingerprint'] ?? null)
                        : null,
                    'ordinary_evidence_id' => is_array($ordinary)
                        ? ($ordinary['id'] ?? null)
                        : null,
                    'ordinary_evidence_source_manifest_sha256' => is_array($ordinary)
                        ? ($ordinary['source_manifest_sha256'] ?? null)
                        : null,
                    'ordinary_evidence_snapshot_fingerprint' => is_array($ordinary)
                        ? ($ordinary['snapshot_fingerprint'] ?? null)
                        : null,
                    'identity' => $identityVersions,
                    'mappings' => $mappingVersions,
                ];
            }
            if ($primaryEmploymentCount !== 1) {
                $issues[] = $this->issue(
                    'jmhz_primary_employment_unresolved',
                    'person',
                    $employeeId,
                    ['10495'],
                );
            }
            if (count($employmentResults) !== count($normalizedEmployments)) {
                $this->invalid(
                    'jmhz_result_employment_set_mismatch',
                    'Vysledek revize obsahuje jinou mnozinu pracovnich vztahu.',
                );
            }
            $this->assertSocialRelationshipSet(
                $personResult,
                array_column($normalizedEmployments, 'employment_id'),
            );
            $normalizedPeople[] = [
                'employee_id' => $employeeId,
                'person_summary' => $personResult,
                'employments' => $normalizedEmployments,
            ];
        }
        if ($normalizedPeople === []) {
            $issues[] = $this->issue('jmhz_employment_set_empty', 'revision', $revisionId);
        }
        if (count($resultPeople) !== count($normalizedPeople)) {
            $this->invalid(
                'jmhz_result_person_set_mismatch',
                'Vysledek revize obsahuje jinou mnozinu osob.',
            );
        }
        // Evidence, která patří k vztahu mimo tuhle revizi, je chyba — ne nález.
        // Nesmí projít tiše: zmrazila by se příprava s cizí právní skutečností.
        $foreignEvidence = array_diff_key($ordinaryEvidenceSources, $usedOrdinaryEvidence);
        if ($foreignEvidence !== []) {
            $this->invalid(
                'jmhz_ordinary_evidence_scope_mismatch',
                'Ordinary evidence patri k pracovnimu vztahu mimo pripravovanou revizi.',
            );
        }
        ksort($usedOrdinaryEvidence, SORT_NUMERIC);
        $ordinaryPayloads = [];
        $ordinaryVersions = [];
        foreach ($usedOrdinaryEvidence as $employmentId => $ordinary) {
            $ordinaryPayloads[] = $ordinary['payload'];
            $ordinaryVersions[] = [
                'employment_id' => $employmentId,
                'id' => $ordinary['id'] ?? null,
                'source_manifest_sha256' => $ordinary['source_manifest_sha256'] ?? null,
                'snapshot_fingerprint' => $ordinary['snapshot_fingerprint'] ?? null,
            ];
        }
        $registrations = $this->officeRegistrations(
            $source['offices'] ?? null,
            $normalizedPeople,
            $runId,
            $issues,
        );
        $office = count($registrations) === 1
            && $registrations[0]['social_security_variable_symbol'] !== null
                ? $registrations[0]
                : null;

        $issues = $this->normalizeIssues($issues);
        $payload = [
            'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $this->hash($revision['input_snapshot_hash'] ?? null, 'input_snapshot_hash'),
                'result_snapshot_hash' => $this->hash($revision['result_snapshot_hash'] ?? null, 'result_snapshot_hash'),
                'ruleset_manifest_hash' => $this->hash($revision['ruleset_manifest_hash'] ?? null, 'ruleset_manifest_hash'),
            ],
            'header' => [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'environment' => $environment,
            ],
            'employer_summary' => [
                'employer' => $input['employer'] ?? null,
                'office' => $office,
                'offices' => $registrations,
            ],
            'people' => $normalizedPeople,
            'ordinary_evidence' => $ordinaryPayloads,
            'source_versions' => [
                'office_id' => is_array($office) ? ($office['id'] ?? null) : null,
                'office_ids' => array_column($registrations, 'id'),
                'employments' => $sourceVersions,
                'ordinary_evidence' => $ordinaryVersions,
            ],
            'readiness_issue_codes' => array_column($issues, 'code'),
            'readiness_issues' => $issues,
        ];

        return new JmhzPreparationSnapshot($payload, $issues);
    }

    /**
     * Registrace u OSSZ, za které se z revize podává.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Účtárna se bere z PRACOVNÍHO VZTAHU, ne z běhu
     * ─────────────────────────────────────────────────────────────────────────
     * Dřív se variabilní symbol četl z účtárny běhu (`payroll_runs.office_id`).
     * To je ale jen filtr rozsahu běhu: u celofiremního běhu je `NULL` (a taková
     * příprava tedy nikdy nebyla připravená) a u běhu přes víc účtáren by
     * ukazoval na jedinou z nich, takže by se hlášení odeslalo pod cizím
     * variabilním symbolem. Registrace je vlastností účtárny vztahu, proto se
     * účtárny odvozují ze zmrazeného vstupu — stejně jako v
     * {@see JmhzPvpojPreviewBuilder::offices()}, ze kterého vzniká přehled
     * o výši pojistného.
     *
     * Chybějící variabilní symbol zůstává blokujícím nálezem, ale nově je
     * ADRESNÝ: nese `office` a jeho id, takže účetní ví, KTEROU registraci má
     * doplnit. U jednoúčtárenského běhu je to fakticky totéž hlášení jako dřív.
     *
     * @param list<array<string,mixed>> $people normalizované osoby
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return list<array{
     *   id:int,code:string,name:string,social_security_variable_symbol:?string
     * }>
     */
    private function officeRegistrations(
        mixed $catalog,
        array $people,
        int $runId,
        array &$issues,
    ): array {
        $known = [];
        foreach ($this->rows($catalog ?? [], 'offices') as $office) {
            $id = $office['id'] ?? null;
            if (is_int($id) && $id > 0) {
                $known[$id] = $office;
            }
        }
        $officeIds = [];
        foreach ($people as $person) {
            foreach ($this->rows(
                $person['employments'] ?? null,
                'people.employments',
            ) as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                $source = $employment['employment'] ?? null;
                $officeId = is_array($source) ? ($source['office_id'] ?? null) : null;
                if (!is_int($officeId) || $officeId <= 0) {
                    $issues[] = $this->issue(
                        'jmhz_employment_without_office',
                        'employment',
                        $employmentId,
                        ['10221'],
                    );
                    continue;
                }
                $officeIds[$officeId] = true;
            }
        }
        $ids = array_keys($officeIds);
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            $issues[] = $this->issue(
                'social_security_variable_symbol_missing',
                'run',
                $runId,
                ['10221'],
            );

            return [];
        }
        $registrations = [];
        foreach ($ids as $officeId) {
            $office = $known[$officeId] ?? null;
            if ($office === null) {
                $issues[] = $this->issue(
                    'jmhz_social_office_unknown',
                    'office',
                    $officeId,
                    ['10221'],
                );
                continue;
            }
            $symbol = $office['social_security_variable_symbol'] ?? null;
            // Deset číslic je totéž, co vynucuje serializér u atributu 10221;
            // kratší hodnota by prošla přípravou a spadla až na XSD.
            $valid = is_string($symbol)
                && preg_match('/^[0-9]{10}$/D', $symbol) === 1;
            if (!$valid) {
                $issues[] = $this->issue(
                    'social_security_variable_symbol_missing',
                    'office',
                    $officeId,
                    ['10221'],
                );
            }
            $registrations[] = [
                'id' => $officeId,
                'code' => is_string($office['code'] ?? null) ? $office['code'] : '',
                'name' => is_string($office['name'] ?? null) ? $office['name'] : '',
                'social_security_variable_symbol' => $valid ? (string) $symbol : null,
            ];
        }

        return $registrations;
    }

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $revision
     * @param array<string,mixed> $term
     */
    private function assertOrdinaryEvidence(
        array $evidence,
        int $supplierId,
        int $runId,
        int $revisionId,
        int $revisionNo,
        string $periodStart,
        string $periodEnd,
        array $revision,
        int $employeeId,
        int $employmentId,
        array $term,
    ): void {
        $this->positiveInt($evidence['id'] ?? null, 'ordinary_evidence.id');
        $this->hash(
            $evidence['source_manifest_sha256'] ?? null,
            'ordinary_evidence.source_manifest_sha256',
        );
        $this->hash(
            $evidence['snapshot_fingerprint'] ?? null,
            'ordinary_evidence.snapshot_fingerprint',
        );
        $payload = $this->object(
            $evidence['payload'] ?? null,
            'ordinary_evidence.payload',
        );
        $scope = $this->object($payload['scope'] ?? null, 'ordinary_evidence.scope');
        if (($payload['schema_reference'] ?? null)
                !== JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE
            || ($payload['builder_version'] ?? null)
                !== JmhzOrdinaryEvidenceBuilder::BUILDER_VERSION
            || ($scope['supplier_id'] ?? null) !== $supplierId
            || ($scope['run_id'] ?? null) !== $runId
            || ($scope['source_revision_id'] ?? null) !== $revisionId
            || ($scope['revision_no'] ?? null) !== $revisionNo
            || ($scope['period_start'] ?? null) !== $periodStart
            || ($scope['period_end'] ?? null) !== $periodEnd
            || ($scope['scenario_key'] ?? null) !== 'scenario_1'
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
        $specification = $this->object(
            $payload['specification'] ?? null,
            'ordinary_evidence.specification',
        );
        if (($specification['package_key'] ?? null)
                !== JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY
            || ($specification['spec_manifest_sha256'] ?? null)
                !== JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256
            || ($specification['scenario_catalog_key'] ?? null)
                !== JmhzScenarioRequirementSourceCatalog::CATALOG_KEY
            || ($specification['scenario_manifest_sha256'] ?? null)
                !== JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256
            || ($specification['control_catalog_key'] ?? null)
                !== JmhzControlSourceCatalog::CATALOG_KEY
            || ($specification['control_manifest_sha256'] ?? null)
                !== JmhzControlSourceCatalog::MANIFEST_SHA256
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_specification_mismatch',
                'Ordinary evidence neodpovida pripnute specifikaci JMHZ.',
            );
        }
        $sourceRevision = $this->object(
            $payload['source_revision'] ?? null,
            'ordinary_evidence.source_revision',
        );
        foreach ([
            'input_snapshot_hash',
            'result_snapshot_hash',
            'ruleset_manifest_hash',
        ] as $field) {
            if (($sourceRevision[$field] ?? null) !== ($revision[$field] ?? null)) {
                $this->invalid(
                    'jmhz_ordinary_evidence_source_mismatch',
                    'Ordinary evidence nevychazi ze stejne mzdove revize.',
                );
            }
        }
        if (($payload['attribute_values'] ?? null) !== [
            '10116' => false,
            '10546' => false,
        ]) {
            $this->invalid(
                'jmhz_ordinary_evidence_values_mismatch',
                'Ordinary evidence obsahuje nepodporovanou pravni skutecnost.',
            );
        }
        $catalog = JmhzScenarioRequirementSourceCatalog::load();
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
        $actualInteractions = $this->rows(
            $payload['interaction_decisions'] ?? null,
            'ordinary_evidence.interaction_decisions',
        );
        $actualDerivedInteractions = $this->rows(
            $payload['derived_interactions'] ?? null,
            'ordinary_evidence.derived_interactions',
        );
        if (CanonicalJson::encode($actualInteractions) !== CanonicalJson::encode($expectedInteractions)
            || CanonicalJson::encode($actualDerivedInteractions)
                !== CanonicalJson::encode($expectedDerivedInteractions)
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_interaction_mismatch',
                'Ordinary evidence neodpovida pripnutym interakcim JMHZ.',
            );
        }
        $confirmation = $this->object(
            $payload['confirmation'] ?? null,
            'ordinary_evidence.confirmation',
        );
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
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D',
                $confirmation['confirmed_at'],
            ) !== 1
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_confirmation_invalid',
                'Ordinary evidence nema platne potvrzeni.',
            );
        }
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return array<string,mixed>|null
     */
    private function inspectAverageEarning(
        mixed $value,
        int $employmentId,
        string $periodStart,
        array &$issues,
    ): ?array {
        if ($value === null) {
            $issues[] = $this->issue(
                'jmhz_average_hourly_earning_missing',
                'employment',
                $employmentId,
                ['10345'],
            );
            return null;
        }
        $average = $this->object($value, 'employment.average_earning');
        $year = (int) substr($periodStart, 0, 4);
        $quarter = intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1;
        if (($average['applicable_year'] ?? null) !== $year
            || ($average['applicable_quarter'] ?? null) !== $quarter
            || ($average['status'] ?? null) !== 'approved'
        ) {
            $this->invalid(
                'jmhz_average_hourly_earning_mismatch',
                'Prumerny hodinovy vydelek neodpovida obdobi nebo neni schvaleny.',
            );
        }
        $this->positiveInt($average['id'] ?? null, 'average_earning.id');
        $this->positiveInt(
            $average['row_version'] ?? null,
            'average_earning.row_version',
        );
        $this->positiveInt(
            $average['revision_no'] ?? null,
            'average_earning.revision_no',
        );
        $this->hash($average['ruleset_hash'] ?? null, 'average_earning.ruleset_hash');
        $this->hash($average['input_hash'] ?? null, 'average_earning.input_hash');
        if (!is_string($average['ruleset_id'] ?? null)
            || trim($average['ruleset_id']) === ''
            || !in_array($average['source_kind'] ?? null, ['actual', 'probable'], true)
            || !is_int($average['average_hourly_minor'] ?? null)
            || $average['average_hourly_minor'] <= 0
        ) {
            $this->invalid(
                'jmhz_average_hourly_earning_invalid',
                'Zmrazeny prumerny hodinovy vydelek nema platna data.',
            );
        }
        if (($average['support_status'] ?? null) !== 'supported') {
            $issues[] = $this->issue(
                'jmhz_average_hourly_earning_unverified',
                'employment',
                $employmentId,
                ['10345'],
            );
        }

        return $average;
    }

    private function scenarioSelector(): JmhzScenario1SelectorResolver
    {
        return $this->scenarioSelector ??= JmhzScenario1SelectorResolver::load();
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function indexResultPeople(array $result): array
    {
        $indexed = [];
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'result.employee_id',
            );
            if (isset($indexed[$employeeId])) {
                $this->invalid(
                    'jmhz_result_person_set_mismatch',
                    'Vysledek revize obsahuje osobu vicekrat.',
                );
            }
            $indexed[$employeeId] = $person;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<int,array<string,mixed>>
     */
    private function indexResultEmployments(array $person): array
    {
        $indexed = [];
        foreach ($this->rows(
            $person['employments'] ?? null,
            'result.person.employments',
        ) as $employment) {
            $employmentId = $this->positiveInt(
                $employment['employment_id'] ?? null,
                'result.employment_id',
            );
            if (isset($indexed[$employmentId])) {
                $this->invalid(
                    'jmhz_result_employment_set_mismatch',
                    'Vysledek revize obsahuje pracovni vztah vicekrat.',
                );
            }
            $indexed[$employmentId] = $employment;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /**
     * @param array<string,mixed> $personResult
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return array<string,mixed>|null
     */
    private function inspectDiscounts(
        array $personResult,
        int $employmentId,
        array &$issues,
    ): ?array {
        $statutory = $personResult['statutory'] ?? null;
        $social = is_array($statutory)
            ? ($statutory['social_insurance'] ?? null)
            : null;
        if (!is_array($social) || array_is_list($social)
            || ($social['status'] ?? null) !== 'calculated'
        ) {
            $issues[] = $this->issue(
                'jmhz_social_result_not_calculated',
                'employment',
                $employmentId,
                ['10370', '10371', '10481', '10482'],
            );
            return null;
        }
        $employeeDiscount = $social['working_pensioner_discount_minor_units'] ?? null;
        if (!is_int($employeeDiscount) || $employeeDiscount < 0) {
            $issues[] = $this->issue(
                'jmhz_social_result_not_calculated',
                'employment',
                $employmentId,
            );
        } elseif ($employeeDiscount > 0) {
            $issues[] = $this->issue(
                'jmhz_employee_social_discount_unsupported',
                'employment',
                $employmentId,
            );
        }
        $matched = null;
        foreach ($this->rows(
            $social['relationships'] ?? null,
            'social_insurance.relationships',
        ) as $relationship) {
            if (($relationship['relationship_id'] ?? null)
                === "employment:{$employmentId}"
            ) {
                if ($matched !== null) {
                    $this->invalid(
                        'jmhz_social_relationship_mismatch',
                        'Socialni vysledek obsahuje vztah vicekrat.',
                    );
                }
                $matched = $relationship;
            }
        }
        if ($matched === null) {
            $this->invalid(
                'jmhz_social_relationship_mismatch',
                'Socialni vysledek nepokryva pracovni vztah.',
            );
        }
        $this->inspectPartTimeDiscount($matched, $employmentId, $issues);
        return $matched;
    }

    /**
     * Uplatněná sleva podle § 7a se v měsíčním hlášení vykazuje třemi
     * položkami u dotčené součásti: příznakem 10372, rozsahem kratší
     * pracovní nebo služební doby 10373 a písmenem důvodu 10374. Blokuje se
     * proto jen to, co skutečně chybí — nárok, který limity § 7a odst. 3
     * vyloučily, se v podání neuplatňuje a XML pro něj žádnou položku nenese.
     *
     * @param array<string,mixed> $relationship
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    private function inspectPartTimeDiscount(
        array $relationship,
        int $employmentId,
        array &$issues,
    ): void {
        $evidence = $relationship['part_time_employer_discount'] ?? null;
        if ($evidence === 'not_claimed') {
            return;
        }
        if ($evidence !== 'verified') {
            $issues[] = $this->issue(
                'jmhz_employer_part_time_discount_unverified',
                'employment',
                $employmentId,
                ['10372'],
            );
            return;
        }
        $outcome = $relationship['part_time_employer_discount_outcome'] ?? null;
        if ($outcome !== null && $outcome !== 'applied') {
            return;
        }
        if ($outcome === null) {
            $issues[] = $this->issue(
                'jmhz_employer_part_time_discount_outcome_missing',
                'employment',
                $employmentId,
                ['10372'],
            );
            return;
        }
        $reason = SocialPartTimeDiscountReason::tryFrom(
            is_string($relationship['part_time_employer_discount_reason'] ?? null)
                ? $relationship['part_time_employer_discount_reason']
                : '',
        );
        if ($reason === null) {
            $issues[] = $this->issue(
                'jmhz_employer_part_time_discount_reason_missing',
                'employment',
                $employmentId,
                ['10374'],
            );
            return;
        }
        if (!$reason->requiresShorterWorkingTime()) {
            return;
        }
        // Kontrola 138 ČSSZ žádá 10373 právě u důvodů A až F. Sjednaná týdenní
        // doba je jediný pramen té hodnoty; bez ní se sleva vykázat nedá.
        $weekly = $relationship['agreed_weekly_working_millihours'] ?? null;
        if (!is_int($weekly) || $weekly <= 0 || $weekly % 10 !== 0) {
            $issues[] = $this->issue(
                'jmhz_employer_part_time_discount_working_time_missing',
                'employment',
                $employmentId,
                ['10373'],
            );
        }
    }

    /**
     * @param array<string,mixed> $personResult
     * @param list<int> $employmentIds
     */
    private function assertSocialRelationshipSet(
        array $personResult,
        array $employmentIds,
    ): void {
        $statutory = $this->object(
            $personResult['statutory'] ?? null,
            'person_result.statutory',
        );
        $social = $this->object(
            $statutory['social_insurance'] ?? null,
            'person_result.statutory.social_insurance',
        );
        $actual = [];
        foreach ($this->rows(
            $social['relationships'] ?? null,
            'social_insurance.relationships',
        ) as $relationship) {
            $relationshipId = $relationship['relationship_id'] ?? null;
            if (!is_string($relationshipId)
                || preg_match('/^employment:([1-9][0-9]*)$/D', $relationshipId, $matches) !== 1
            ) {
                $this->invalid(
                    'jmhz_social_relationship_mismatch',
                    'Socialni vysledek obsahuje neplatny vztah.',
                );
            }
            $actual[] = (int) $matches[1];
        }
        sort($actual, SORT_NUMERIC);
        sort($employmentIds, SORT_NUMERIC);
        if ($actual !== $employmentIds) {
            $this->invalid(
                'jmhz_social_relationship_mismatch',
                'Socialni vysledek neobsahuje presne zmrazene pracovni vztahy.',
            );
        }
    }

    /**
     * @param array<string,mixed> $eldp
     * @param array<string,mixed> $revision
     */
    private function assertEldpSource(
        array $eldp,
        int $supplierId,
        array $revision,
        int $employeeId,
        int $employmentId,
        string $periodStart,
        mixed $term,
        mixed $workSummary,
    ): void {
        $payload = $this->object($eldp['payload'] ?? null, 'eldp.payload');
        $scope = $this->object($payload['scope'] ?? null, 'eldp.scope');
        $sourceRevision = $this->object($payload['source_revision'] ?? null, 'eldp.source_revision');
        $sourceEvidence = $this->object($payload['source_evidence'] ?? null, 'eldp.source_evidence');
        $sections = $this->rows($payload['eldp_sections'] ?? null, 'eldp.sections');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        if (($payload['schema_reference'] ?? null) !== JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE
            || ($scope['supplier_id'] ?? null) !== $supplierId
            || ($scope['source_revision_id'] ?? null) !== $revisionId
            || ($scope['employee_id'] ?? null) !== $employeeId
            || ($scope['employment_id'] ?? null) !== $employmentId
            || ($scope['period_start'] ?? null) !== $periodStart
            || ($scope['scenario_key'] ?? null) !== 'scenario_1'
            || ($sourceRevision['input_snapshot_hash'] ?? null) !== ($revision['input_snapshot_hash'] ?? null)
            || ($sourceRevision['result_snapshot_hash'] ?? null) !== ($revision['result_snapshot_hash'] ?? null)
            || ($sourceRevision['ruleset_manifest_hash'] ?? null) !== ($revision['ruleset_manifest_hash'] ?? null)
            || !is_array($term) || array_is_list($term)
            || ($sourceEvidence['term_id'] ?? null) !== ($term['id'] ?? null)
            || ($sourceEvidence['term_row_version'] ?? null) !== ($term['row_version'] ?? null)
            || !is_array($workSummary) || array_is_list($workSummary)
            || ($sourceEvidence['work_summary_id'] ?? null) !== ($workSummary['id'] ?? null)
            || ($sourceEvidence['work_summary_sha256'] ?? null) !== ($workSummary['summary_sha256'] ?? null)
            || count($sections) !== 1
            || !is_int($eldp['id'] ?? null)
            || !is_string($eldp['source_manifest_sha256'] ?? null)
            || !is_string($eldp['snapshot_fingerprint'] ?? null)
        ) {
            $this->invalid('jmhz_eldp_evidence_mismatch', 'Evidence ELDP neodpovídá zmrazenému pracovnímu vztahu.');
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt seznam.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                $this->invalid('jmhz_source_invalid', "{$field} obsahuje neplatnou hodnotu.");
            }
            $result[] = $item;
        }
        return $result;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            $this->invalid('jmhz_amount_overflow', 'Agregace castky JMHZ pretekla.');
        }
        return $left + $right;
    }

    /** @param array<string,mixed> $mapping */
    private function assertMapping(array $mapping, int $componentId): void
    {
        if ($mapping['component_definition_id'] !== $componentId
            || $mapping['package_key']
                !== JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY
            || $mapping['spec_manifest_sha256']
                !== JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256
        ) {
            $this->invalid(
                'jmhz_component_mapping_mismatch',
                'Mapovani mzdove slozky neodpovida pripnutemu baliku JMHZ.',
            );
        }
        $mappingHash = $this->hash(
            $mapping['mapping_hash'] ?? null,
            'mapping.mapping_hash',
        );
        $expected = [
            'supplier_id' => $mapping['supplier_id'] ?? null,
            'component_definition_id' => $mapping['component_definition_id'],
            'mapping_id' => $mapping['mapping_id'] ?? null,
            'mapping_row_version' => $mapping['mapping_row_version'] ?? null,
            'package_key' => $mapping['package_key'],
            'spec_manifest_sha256' => $mapping['spec_manifest_sha256'],
            'target_attribute_id' => $mapping['target_attribute_id'] ?? null,
            'target_xsd_mapping' => $mapping['target_xsd_mapping'] ?? null,
            'parent_attribute_id' => $mapping['parent_attribute_id'] ?? null,
            'ancestor_attribute_ids' => $mapping['ancestor_attribute_ids'] ?? null,
            'aggregation_role' => $mapping['aggregation_role'] ?? null,
            'aggregation_scope' => $mapping['aggregation_scope'] ?? null,
            'topology_hash' => $mapping['topology_hash'] ?? null,
        ];
        if (!hash_equals(
            $mappingHash,
            hash('sha256', CanonicalJson::encode($expected)),
        )) {
            $this->invalid(
                'jmhz_component_mapping_hash_mismatch',
                'Otisk mapovani mzdove slozky nesouhlasi.',
            );
        }
    }

    /** @param array<string,mixed> $identity */
    private function assertIdentity(
        array $identity,
        string $environment,
        int $employeeId,
        int $employmentId,
    ): void {
        $person = $identity['person_external_identifier'] ?? null;
        $employment = $identity['employment_external_identifier'] ?? null;
        $jmhzEmployment = $identity['jmhz_employment_external_identifier'] ?? null;
        $history = $identity['identity'] ?? null;
        if (!is_array($person) || !is_array($employment)
            || !is_array($jmhzEmployment) || !is_array($history)
            || ($person['identifier_type'] ?? null) !== 'ik_mpsv'
            || ($identity['jmhz_environment'] ?? null) !== $environment
            || ($employment['identifier_type'] ?? null) !== 'id_ppv'
            || ($history['employee_id'] ?? null) !== $employeeId
            || ($employment['environment'] ?? null) !== $environment
            || ($employment['employment_id'] ?? null) !== $employmentId
            || ($employment['employee_id'] ?? null) !== $employeeId
            || ($jmhzEmployment['id'] ?? null) !== ($employment['id'] ?? null)
            || ($jmhzEmployment['value'] ?? null) !== ($employment['value'] ?? null)
        ) {
            $this->invalid(
                'jmhz_identity_scope_mismatch',
                'Citliva identita JMHZ neodpovida osobe, vztahu nebo prostredi.',
            );
        }
    }

    /**
     * @param array<string,mixed> $term
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    private function inspectTerm(array $term, int $employmentId, array &$issues): void
    {
        if (($term['jmhz_external_codebooks_verified_for_period'] ?? null) !== true) {
            $issues[] = $this->issue('jmhz_workplace_codebooks_unverified', 'employment', $employmentId, ['10229', '10230', '10231']);
        }
        foreach ([
            'jmhz_apz_contribution_status' => '10232',
            'jmhz_functional_benefits_status' => '10247',
            'jmhz_temporary_assignment_status' => '10251',
        ] as $field => $attributeId) {
            if (!in_array($term[$field] ?? null, ['yes', 'no'], true)) {
                $issues[] = $this->issue('jmhz_verified_boolean_missing', 'employment', $employmentId, [$attributeId]);
            }
        }
        if (($term['jmhz_apz_contribution_status'] ?? null) === 'yes'
            && !is_string($term['jmhz_apz_instrument_code'] ?? null)
        ) {
            $issues[] = $this->issue('jmhz_apz_instrument_missing', 'employment', $employmentId, ['10233']);
        }
        if (($term['jmhz_temporary_assignment_status'] ?? null) === 'yes') {
            $issues[] = $this->issue('jmhz_temporary_assignment_unsupported', 'employment', $employmentId, ['10252', '10457', '10492', '10493', '10494']);
        }
        if (($term['risky_work'] ?? null) === true) {
            $issues[] = $this->issue('jmhz_risky_work_unsupported', 'employment', $employmentId, ['10273', '10274']);
        }
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    private function inspectWorkMonth(mixed $timeMonth, int $employmentId, array &$issues): void
    {
        if (!is_array($timeMonth) || array_is_list($timeMonth)
            || ($timeMonth['status'] ?? null) !== 'approved'
        ) {
            $issues[] = $this->issue('jmhz_work_month_not_approved', 'employment', $employmentId, ['10259', '10260', '10261', '10265', '10268']);
            return;
        }
        $summary = $timeMonth['jmhz_work_summary'] ?? null;
        if (!is_array($summary) || array_is_list($summary)
            || ($timeMonth['jmhz_work_summary_status'] ?? null) !== 'frozen_work_summary'
            || ($summary['derivation_version'] ?? null) !== 'jmhz-work-month.v2'
        ) {
            $issues[] = $this->issue('jmhz_work_summary_v2_missing', 'employment', $employmentId, ['10259', '10260', '10261', '10265', '10268']);
        }
    }

    /**
     * @param list<string> $attributeIds
     * @return array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}
     */
    private function issue(
        string $code,
        string $entityType,
        ?int $entityId,
        array $attributeIds = [],
    ): array
    {
        sort($attributeIds, SORT_STRING);
        return ['code' => $code, 'entity_type' => $entityType, 'entity_id' => $entityId, 'attribute_ids' => $attributeIds];
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $sourceIssues
     */
    private function hasSpecificEldpIssue(array $sourceIssues, int $employmentId): bool
    {
        foreach ($sourceIssues as $issue) {
            if ($issue['entity_type'] === 'employment'
                && $issue['entity_id'] === $employmentId
                && str_starts_with($issue['code'], 'jmhz_eldp_')
                && $issue['code'] !== 'jmhz_eldp_evidence_missing'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}>
     */
    private function normalizeIssues(array $issues): array
    {
        usort($issues, static fn (array $left, array $right): int => [
            $left['code'], $left['entity_type'], $left['entity_id'] ?? 0, implode(',', $left['attribute_ids']),
        ] <=> [
            $right['code'], $right['entity_type'], $right['entity_id'] ?? 0, implode(',', $right['attribute_ids']),
        ]);
        $unique = [];
        foreach ($issues as $issue) {
            $unique[CanonicalJson::encode($issue)] = $issue;
        }
        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $expectedHash, string $field): array
    {
        if (!is_string($json) || $json === '') {
            $this->invalid('jmhz_snapshot_missing', "{$field} chybi.");
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new JmhzPreparationSnapshotException('jmhz_snapshot_invalid', "{$field} neni platny JSON.", $exception);
        }
        $object = $this->object($decoded, $field);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json || !hash_equals($this->hash($expectedHash, $field), hash('sha256', $canonical))) {
            $this->invalid('jmhz_snapshot_hash_mismatch', "Otisk {$field} nesouhlasi.");
        }
        return $object;
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

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platne datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platne datum.");
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

    private function invalid(string $code, string $message): never
    {
        throw new JmhzPreparationSnapshotException($code, $message);
    }
}
