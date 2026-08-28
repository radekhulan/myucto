<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceScope;
use MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceSource;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzOrdinaryEvidenceBuilder
{
    public const BUILDER_VERSION = 'jmhz-ordinary-evidence.v1';

    private const FACT_KEYS = [
        'reportable_wage_deductions_recorded',
        'employee_social_discount_claimed',
        'specific_legal_fact_occurred',
        'ozp_employment_support_claimed',
        'deep_mining_work_occurred',
    ];

    /**
     * @param array<string,mixed> $facts
     * @return array<string,bool>
     */
    public static function normalizeFacts(array $facts): array
    {
        $keys = array_keys($facts);
        sort($keys, SORT_STRING);
        $expected = self::FACT_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new JmhzOrdinaryEvidenceException(
                'jmhz_ordinary_evidence_incomplete',
                'Potvrzení musí obsahovat přesně všech pět právních skutečností.',
            );
        }
        $normalized = [];
        foreach (self::FACT_KEYS as $key) {
            if (!is_bool($facts[$key])) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_invalid',
                    'Každá právní skutečnost musí být výslovné Ano nebo Ne.',
                );
            }
            if ($facts[$key] !== false) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_positive_unsupported',
                    'První ordinary profil podporuje jen výslovné Ne u všech pěti skutečností.',
                );
            }
            $normalized[$key] = false;
        }
        return $normalized;
    }

    /**
     * Ordinary evidence se zmrazuje ZA PRACOVNÍ VZTAH, ne za revizi.
     *
     * Úložiště je unikátní na `(supplier_id, source_revision_id, employee_id,
     * employment_id)`, takže jedna revize nese jednu evidenci za každý vztah.
     * Dřív si builder vztah odvozoval z toho, že revize má právě jednu osobu
     * s právě jedním vztahem — firma s víc zaměstnanci proto ordinary evidenci
     * nezmrazila vůbec. Cíl vztahu je proto explicitní parametr; přísnost
     * kontrol zůstává, jen se přestalo vyžadovat, že je vztah v revizi jediný.
     *
     * @param array<string,mixed> $source
     * @param array<string,bool> $facts
     */
    public function build(
        int $supplierId,
        array $source,
        int $targetEmploymentId,
        array $facts,
        int $confirmedBy,
        string $confirmedAt,
        string $sourceKind = 'explicit_confirmation',
    ): JmhzOrdinaryEvidenceSnapshot {
        if ($targetEmploymentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah musí být kladné číslo.');
        }
        $facts = self::normalizeFacts($facts);
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || !in_array($revision['revision_kind'] ?? null, ['regular', 'correction'], true)
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_revision_not_current_approved',
                'Měsíční podklady JMHZ vyžadují aktuální schválenou revizi mzdy.',
            );
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_ordinary_evidence_period_invalid', 'Období nezačíná prvním dnem měsíce.');
        }
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'input',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'result',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
            || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null) !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_mismatch', 'Zmrazený vstup neodpovídá firmě nebo období.');
        }
        [$employeeId, $employmentId, $person, $employment] = $this->targetEmployment(
            $input,
            $targetEmploymentId,
        );
        $profileSource = $this->ordinaryProfileSource($employment, $sourceKind);
        $this->assertNoKnownDeductionConflict($person, $result, $employeeId);
        $term = $this->object($employment['term'] ?? null, 'term');
        $employmentSource = $this->object($employment['employment'] ?? null, 'employment');
        $selectorActivityCode = is_string($term['activity_code'] ?? null)
            ? $term['activity_code']
            : null;
        $selectorRelationshipDetailCode = is_string($term['jmhz_relationship_detail_code'] ?? null)
            ? $term['jmhz_relationship_detail_code']
            : null;
        $selection = JmhzScenarioSelectorResolver::load()->resolve(
            $selectorActivityCode,
            $selectorRelationshipDetailCode,
        );
        $scenarioResolution = $selection['evidence'] ?? null;
        $scenarioKey = is_array($scenarioResolution)
            ? ($scenarioResolution['scenario_key'] ?? null)
            : null;
        $supportedProfile = $scenarioKey === 'scenario_1'
            || ($scenarioKey === 'scenario_3'
                && in_array(
                    $employmentSource['relation_type'] ?? null,
                    ['partner_dependent', 'statutory_body'],
                    true,
                )
                && ($term['activity_code'] ?? null) === 'S'
                && ($term['jmhz_relationship_detail_code'] ?? null) === '1');
        if ($selection['supported'] !== true || !$supportedProfile) {
            $this->invalid(
                'jmhz_ordinary_evidence_scenario_unsupported',
                'Revize nepatří do podporovaného běžného profilu JMHZ.',
            );
        }

        $catalog = JmhzScenarioRequirementSourceCatalog::load();
        $interactions = [];
        foreach (['IN13', 'IN28', 'IN30'] as $interactionId) {
            $definition = $catalog->interaction($interactionId);
            $interactions[] = [
                'interaction_id' => $interactionId,
                'triggered' => false,
                'row_sha256' => $definition->rowHash,
            ];
        }
        $in36 = $catalog->interaction('IN36');
        $requirements = [];
        $expectedRequirementIds = $scenarioKey === 'scenario_1'
            ? ['10116', '10546']
            : ['10546'];
        foreach ($catalog->requirementsForMatrix($scenarioKey) as $requirement) {
            if (in_array($requirement->attributeId, $expectedRequirementIds, true)) {
                $requirements[$requirement->attributeId] = $requirement->rowHash;
            }
        }
        if (array_map('strval', array_keys($requirements)) !== $expectedRequirementIds) {
            $this->invalid('jmhz_ordinary_evidence_catalog_mismatch', 'Katalog ordinary evidence není úplný.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $confirmedAt) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_confirmation_invalid', 'Čas potvrzení není kanonický UTC timestamp.');
        }
        $payload = [
            'schema_reference' => JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'scenario_key' => $scenarioKey,
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'attribute_requirement_row_sha256' => $requirements,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'attribute_values' => [
                '10116' => $facts['reportable_wage_deductions_recorded'],
                '10546' => $facts['employee_social_discount_claimed'],
            ],
            'interaction_decisions' => $interactions,
            'derived_interactions' => [[
                'interaction_id' => 'IN36',
                'triggered' => false,
                'source_attribute_id' => '10546',
                'row_sha256' => $in36->rowHash,
            ]],
            'confirmation' => array_filter([
                'source_kind' => $sourceKind,
                'source_term_id' => $profileSource['source_term_id'] ?? null,
                'source_term_row_version' => $profileSource['source_term_row_version'] ?? null,
                'confirmed_by_user_id' => $confirmedBy,
                'confirmed_at' => $confirmedAt,
            ], static fn (mixed $value): bool => $value !== null),
        ];
        return new JmhzOrdinaryEvidenceSnapshot($payload);
    }

    /**
     * Automatický běžný profil je dovolený jen z údajů zmrazených ve mzdové
     * revizi. Zapnutá výjimka se nesmí tiše přepsat nulovým měsíčním stavem.
     *
     * @param array<string,mixed> $employment
     * @return array{source_term_id:int,source_term_row_version:int}|array{}
     */
    private function ordinaryProfileSource(array $employment, string $sourceKind): array
    {
        if (!in_array(
            $sourceKind,
            ['explicit_confirmation', 'derived_from_frozen_payroll_sources'],
            true,
        )) {
            $this->invalid(
                'jmhz_ordinary_evidence_confirmation_invalid',
                'Zdroj potvrzení právních skutečností není podporován.',
            );
        }
        if ($sourceKind === 'explicit_confirmation') {
            return [];
        }

        $profileValue = $employment['ordinary_evidence_profile'] ?? null;
        if (!is_array($profileValue) || array_is_list($profileValue)) {
            $this->invalid(
                'jmhz_ordinary_evidence_profile_missing',
                'Tato revize vznikla před doplněním podkladů JMHZ. Mzdu znovu přepočítejte a schvalte.',
            );
        }
        $profile = $profileValue;
        $termId = $this->positiveInt($profile['source_term_id'] ?? null, 'source_term_id');
        $termVersion = $this->positiveInt(
            $profile['source_term_row_version'] ?? null,
            'source_term_row_version',
        );
        foreach ([
            'orchard_discount_eligible',
            'specific_legal_fact_applies',
            'ozp_employment_support_applies',
            'deep_mining_work_applies',
        ] as $key) {
            if (!is_bool($profile[$key] ?? null)) {
                $this->invalid(
                    'jmhz_ordinary_evidence_profile_incomplete',
                    'Zmrazené nastavení neobvyklých situací JMHZ není úplné.',
                );
            }
            if ($profile[$key] === true) {
                $this->invalid(
                    'jmhz_ordinary_evidence_monthly_exception_required',
                    'Pracovní vztah má evidovanou neobvyklou situaci. Doplňte její měsíční údaje.',
                );
            }
        }

        return [
            'source_term_id' => $termId,
            'source_term_row_version' => $termVersion,
        ];
    }

    /**
     * Najde ve zmrazeném vstupu právě ten vztah, za který se evidence potvrzuje.
     *
     * @param array<string,mixed> $input
     * @return array{int,int,array<string,mixed>,array<string,mixed>}
     */
    private function targetEmployment(array $input, int $targetEmploymentId): array
    {
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || $people === []) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazený vstup neobsahuje žádnou osobu.');
        }
        $found = null;
        foreach ($people as $candidate) {
            $person = $this->object($candidate, 'person');
            $employee = $this->object($person['employee'] ?? null, 'employee');
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            $employments = $person['employments'] ?? null;
            if (!is_array($employments) || !array_is_list($employments)) {
                $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Pracovní vztahy osoby nejsou seznam.');
            }
            foreach ($employments as $candidateEntry) {
                $entry = $this->object($candidateEntry, 'employment entry');
                $employment = $this->object($entry['employment'] ?? null, 'employment');
                $employmentId = $this->positiveInt($employment['id'] ?? null, 'employment.id');
                if ($employmentId !== $targetEmploymentId) {
                    continue;
                }
                if (($employment['employee_id'] ?? null) !== $employeeId) {
                    $this->invalid('jmhz_ordinary_evidence_scope_mismatch', 'Pracovní vztah nepatří zmrazené osobě.');
                }
                if ($found !== null) {
                    $this->invalid('jmhz_ordinary_evidence_scope_mismatch', 'Zmrazený vstup obsahuje pracovní vztah vícekrát.');
                }
                $found = [$employeeId, $employmentId, $person, $entry];
            }
        }
        if ($found === null) {
            $this->invalid(
                'jmhz_ordinary_evidence_scope_mismatch',
                'Zmrazená revize neobsahuje zvolený pracovní vztah.',
            );
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $result
     */
    private function assertNoKnownDeductionConflict(
        array $person,
        array $result,
        int $employeeId,
    ): void
    {
        $agreements = $person['deduction_agreements'] ?? null;
        if (!is_array($agreements) || !array_is_list($agreements)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazená evidence dohod o srážkách není úplná.');
        }
        $enforcement = $person['enforcement_evidence'] ?? null;
        if ($agreements !== []) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Revize obsahuje evidovanou dohodu o srážkách.');
        }
        $enforcement = $this->object($enforcement, 'enforcement_evidence');
        $claims = $enforcement['claims'] ?? null;
        $insolvency = $this->object($enforcement['insolvency'] ?? null, 'insolvency');
        if (!is_array($claims) || !array_is_list($claims)
            || $claims !== [] || ($insolvency['mode'] ?? null) !== 'none'
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Revize obsahuje exekuční nebo insolvenční evidenci.');
        }
        // Kontroluje se osoba, za jejíž vztah se evidence potvrzuje. Ostatní
        // osoby revize mají vlastní evidenci a vlastní kontrolu, takže se
        // nevyžaduje, aby byla v revizi osoba jediná.
        $resultPeople = $result['people'] ?? null;
        if (!is_array($resultPeople) || !array_is_list($resultPeople) || $resultPeople === []) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek neobsahuje žádnou osobu.');
        }
        $resultPerson = null;
        foreach ($resultPeople as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate)
                && ($candidate['employee_id'] ?? null) === $employeeId
            ) {
                if ($resultPerson !== null) {
                    $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek obsahuje osobu vícekrát.');
                }
                $resultPerson = $candidate;
            }
        }
        if (!is_array($resultPerson)) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek neobsahuje zmrazenou osobu.');
        }
        $statutory = $this->object($resultPerson['statutory'] ?? null, 'statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Zákonný výsledek osoby není vypočtený.');
        }
        $net = $this->object($statutory['net_pay'] ?? null, 'net_pay');
        $deductions = $net['deductions'] ?? null;
        if (!is_array($deductions) || !array_is_list($deductions)
            || !is_int($net['deducted_minor_units'] ?? null)
            || $deductions !== [] || $net['deducted_minor_units'] !== 0
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Výsledek obsahuje evidovanou srážku ze mzdy.');
        }
        $resultEnforcement = $this->object($resultPerson['enforcement'] ?? null, 'result.enforcement');
        $enforcementInput = $this->object($resultEnforcement['input'] ?? null, 'result.enforcement.input');
        $enforcementResult = $this->object($resultEnforcement['result'] ?? null, 'result.enforcement.result');
        try {
            $calculationInput = GarnishmentInput::fromCanonicalArray($enforcementInput);
            $calculationEvidence = new EnforcementPersonMonthEvidence(
                $calculationInput->claims,
                $calculationInput->eligibleDependants,
                $calculationInput->dependantsEvidenceComplete,
                $calculationInput->eligibleSpouse,
                $calculationInput->spouseEvidenceComplete,
                $calculationInput->pensionEvidence,
                $calculationInput->hasMultiplePayers,
                $calculationInput->protectedAmountOverrideMinorUnits,
                $calculationInput->protectedAmountOverrideVerified,
                $calculationInput->claimRegisterEvidenceComplete,
                $calculationInput->insolvency,
                $calculationInput->spousePensionEvidence,
            );
        } catch (\Throwable) {
            $this->invalid(
                'jmhz_ordinary_evidence_source_invalid',
                'Výsledek neobsahuje platný vstup výpočtu srážek.',
            );
        }
        if (($enforcement['claim_register_evidence_complete'] ?? null) !== true) {
            try {
                $evidenceScope = EnforcementEvidenceScope::fromCanonicalArray(
                    $this->object($enforcementResult['evidence_source'] ?? null, 'result.enforcement.result.evidence_source'),
                );
            } catch (\Throwable) {
                $this->invalid(
                    'jmhz_ordinary_evidence_source_invalid',
                    'Výsledek neobsahuje platný rozsah kontroly exekuční evidence.',
                );
            }
            if ($evidenceScope->claimRegister !== EnforcementEvidenceSource::NotApplicable) {
                $this->invalid(
                    'jmhz_ordinary_evidence_deduction_conflict',
                    'Kontrola evidence pohledávek není doložená ani označená jako nepoužitelná.',
                );
            }
        }
        if (($enforcementResult['status'] ?? null) !== 'supported'
            || ($enforcementResult['issues'] ?? null) !== []
            || CanonicalJson::encode($calculationEvidence->toCanonicalArray()) !== CanonicalJson::encode($enforcement)
            || ($enforcementResult['allocations'] ?? null) !== []
            || ($enforcementResult['total_withheld_minor_units'] ?? null) !== 0
            || ($enforcementResult['insolvency_applied'] ?? null) !== false
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Výsledek srážek neodpovídá potvrzení ordinary profilu.');
        }
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $field): array
    {
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_hash_mismatch', "Otisk {$field} snapshotu nesouhlasí.");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $json) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Vstupní snapshot není kanonický objekt.');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být objekt.");
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzOrdinaryEvidenceException($code, $message);
    }
}
