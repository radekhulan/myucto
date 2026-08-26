<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzEldpEvidenceBuilder
{
    public const BUILDER_VERSION = 'jmhz-eldp-evidence.v1';

    private const ATTRIBUTE_IDS = [
        '10239', '10502', '10354', '10355', '10240', '10241', '10242',
        '10356', '10245', '10357', '10358', '10359', '10360', '10362',
        '10536', '10366', '10473', '10474', '10475', '10375', '10462',
        '10463', '10464', '10465', '10466', '10468', '10469',
    ];

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $specManifest = null;

    /**
     * Odvodí potvrzení pouze pro běžný bezabsenční řez. Výsledný kandidát vždy
     * projde stejnou úplnou validací jako ručně dodané potvrzení.
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public function deriveOrdinaryConfirmation(
        int $supplierId,
        int $employmentId,
        array $source,
    ): array {
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
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
        [$employeeId, $entry] = $this->findEmployment($input, $employmentId);
        $employment = $this->object($entry['employment'] ?? null, 'employment');
        $term = $this->object($entry['term'] ?? null, 'term');
        $employmentFrom = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        if (!is_string($employmentFrom)) {
            $this->invalid('jmhz_eldp_interval_outside_employment', 'Pracovní vztah nemá zmrazené datum nástupu.');
        }
        $employmentTo = $employment['end_date'] ?? null;
        $insuranceFrom = max($periodStart, $employmentFrom);
        $insuranceTo = is_string($employmentTo) ? min($periodEnd, $employmentTo) : $periodEnd;
        if ($insuranceFrom > $insuranceTo) {
            $this->invalid('jmhz_eldp_interval_invalid', 'Pracovní vztah nemá ve vykazovaném měsíci platný interval ELDP.');
        }
        $activityCode = $term['activity_code'] ?? null;
        if (!is_string($activityCode)) {
            $this->invalid('jmhz_eldp_ordinary_activity_unsupported', 'Pracovní vztah nemá zmrazený druh činnosti pro ELDP.');
        }
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        $assessmentBaseMinor = $this->nonNegativeInt(
            $relationship['assessment_base_minor_units'] ?? null,
            'assessment_base_minor_units',
        );
        $insuranceDays = (new \DateTimeImmutable($insuranceFrom))
            ->diff(new \DateTimeImmutable($insuranceTo))->days + 1;
        $confirmation = [
            'insurance_from' => $insuranceFrom,
            'insurance_to' => $insuranceTo,
            'valid_from' => $insuranceFrom,
            'valid_to' => $insuranceTo,
            'insurance_days' => $insuranceDays,
            'code' => $activityCode . '++',
            'assessment_base_czk' => intdiv($assessmentBaseMinor, 100),
            'in03_active' => false,
            'in04_active' => false,
            'confirmation_note' => '',
        ];

        $this->build($supplierId, $employmentId, $source, $confirmation);
        return $confirmation;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $confirmation
     */
    public function build(
        int $supplierId,
        int $employmentId,
        array $source,
        array $confirmation,
    ): JmhzEldpEvidenceSnapshot {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException('Firma a pracovní vztah musí být kladná čísla.');
        }
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || !in_array($revision['revision_kind'] ?? null, ['regular', 'correction'], true)
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid('jmhz_eldp_revision_not_current_approved', 'ELDP vyžaduje aktuální schválenou řádnou nebo opravnou revizi.');
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_eldp_period_invalid', 'Období ELDP nezačíná prvním dnem měsíce.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
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
            $this->invalid('jmhz_eldp_source_mismatch', 'ELDP zdroj neodpovídá firmě, období nebo výsledku revize.');
        }

        [$employeeId, $entry] = $this->findEmployment($input, $employmentId);
        $employment = $this->object($entry['employment'] ?? null, 'employment');
        $term = $this->object($entry['term'] ?? null, 'term');
        if (($employment['relation_type'] ?? null) !== 'employment') {
            $this->invalid('jmhz_eldp_relationship_kind_unsupported', 'První ELDP řez podporuje jen pracovní poměr.');
        }
        $activityCode = $term['activity_code'] ?? null;
        if (!is_string($activityCode)
            || preg_match('/^[1-9]$/D', $activityCode) !== 1
            || ($term['jmhz_relationship_detail_code'] ?? null) !== '1'
        ) {
            $this->invalid('jmhz_eldp_ordinary_activity_unsupported', 'První ELDP řez podporuje jen činnost 1–9 s bližším určením Žádné.');
        }
        $selection = JmhzScenario1SelectorResolver::load()->resolve($activityCode, '1');
        if (!$selection['supported']) {
            $this->invalid('jmhz_eldp_scenario_unsupported', 'Pracovní vztah nepatří do podporovaného standardního scénáře.');
        }
        $absences = $entry['absences'] ?? null;
        if (!is_array($absences) || !array_is_list($absences) || $absences !== []) {
            $this->invalid('jmhz_eldp_absences_unsupported', 'První ELDP řez nepodporuje měsíc s absencí.');
        }
        $workSummary = is_array($entry['time_month'] ?? null)
            ? ($entry['time_month']['jmhz_work_summary'] ?? null)
            : null;
        if (!is_array($workSummary)
            || ($workSummary['derivation_version'] ?? null) !== 'jmhz-work-month.v2'
            || !is_int($workSummary['id'] ?? null)
            || $workSummary['id'] <= 0
            || !is_string($workSummary['summary_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $workSummary['summary_sha256']) !== 1
        ) {
            $this->invalid('jmhz_eldp_work_summary_missing', 'ELDP vyžaduje zmrazený pracovní souhrn JMHZ v2.');
        }
        $relationship = $this->socialRelationship($result, $employeeId, $employmentId);
        $uncappedBase = $this->nonNegativeInt($relationship['assessment_base_minor_units'] ?? null, 'assessment_base_minor_units');
        $cappedBase = $this->nonNegativeInt($relationship['capped_assessment_base_minor_units'] ?? null, 'capped_assessment_base_minor_units');
        if ($uncappedBase !== $cappedBase) {
            $this->invalid('jmhz_eldp_capped_base_unsupported', 'První ELDP řez nepodporuje krácení ročním maximem.');
        }
        if ($cappedBase % 100 !== 0 || intdiv($cappedBase, 100) > 9_999_999_999) {
            $this->invalid('jmhz_eldp_assessment_base_not_whole_czk', 'Vyměřovací základ ELDP musí být celé Kč v rozsahu XSD.');
        }

        $insuranceFrom = $this->date($confirmation['insurance_from'] ?? null, 'insurance_from');
        $insuranceTo = $this->date($confirmation['insurance_to'] ?? null, 'insurance_to');
        $validFrom = $this->date($confirmation['valid_from'] ?? null, 'valid_from');
        $validTo = $this->date($confirmation['valid_to'] ?? null, 'valid_to');
        $employmentFrom = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        $employmentTo = $employment['end_date'] ?? null;
        if (!is_string($employmentFrom)) {
            $this->invalid('jmhz_eldp_interval_outside_employment', 'Pracovní vztah nemá zmrazené datum nástupu.');
        }
        $expectedFrom = max($periodStart, $employmentFrom);
        $expectedTo = is_string($employmentTo) ? min($periodEnd, $employmentTo) : $periodEnd;
        if ($expectedFrom > $expectedTo
            || $insuranceFrom !== $expectedFrom
            || $insuranceTo !== $expectedTo
            || $validFrom !== $insuranceFrom
            || $validTo !== $insuranceTo
        ) {
            $this->invalid('jmhz_eldp_interval_invalid', 'Interval ELDP musí přesně odpovídat průniku pracovního vztahu s vykazovaným měsícem.');
        }
        $days = $this->positiveInt($confirmation['insurance_days'] ?? null, 'insurance_days');
        $inclusiveDays = (new \DateTimeImmutable($insuranceFrom))
            ->diff(new \DateTimeImmutable($insuranceTo))->days + 1;
        if ($days !== $inclusiveDays) {
            $this->invalid('jmhz_eldp_days_mismatch', 'Počet dnů ELDP neodpovídá inkluzivnímu intervalu.');
        }
        $this->assertWorkSummaryConsistency($workSummary, $days);
        $code = $confirmation['code'] ?? null;
        if (!is_string($code) || $code !== $activityCode . '++') {
            $this->invalid('jmhz_eldp_code_activity_mismatch', 'Kód ELDP neodpovídá činnosti pracovního vztahu.');
        }
        $entryMetadata = $this->codebook()->requireValue('kod_eldp', $code);
        $confirmedBase = $this->positiveInt($confirmation['assessment_base_czk'] ?? null, 'assessment_base_czk');
        if ($confirmedBase * 100 !== $uncappedBase) {
            $this->invalid('jmhz_eldp_assessment_base_mismatch', 'Potvrzený základ ELDP neodpovídá zákonnému výsledku.');
        }
        if (($confirmation['in03_active'] ?? null) !== false
            || ($confirmation['in04_active'] ?? null) !== false
        ) {
            $this->invalid('jmhz_eldp_interaction_unsupported', 'První ELDP řez vyžaduje explicitní Ne pro IN03 i IN04.');
        }
        $note = $confirmation['confirmation_note'] ?? '';
        if (!is_string($note) || mb_strlen(trim($note), 'UTF-8') > 500) {
            $this->invalid('jmhz_eldp_confirmation_note_invalid', 'Volitelná poznámka ELDP smí mít nejvýše 500 znaků.');
        }

        $spec = $this->specManifest();
        $codebook = $this->findCodebook($spec['payload'], 'kod_eldp');
        $payload = [
            'schema_reference' => JmhzEldpEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'period_start' => $periodStart,
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
                'eldp_codebook_content_sha256' => $codebook['content_hash'],
                'eldp_code_row_sha256' => $entryMetadata['row_hash'],
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'source_evidence' => [
                'term_id' => $term['id'] ?? null,
                'term_row_version' => $term['row_version'] ?? null,
                'work_summary_id' => $workSummary['id'],
                'work_summary_sha256' => $workSummary['summary_sha256'],
                'social_relationship' => $relationship,
                'scenario_resolution' => $selection['evidence'],
                'attribute_ids' => self::ATTRIBUTE_IDS,
            ],
            'insurance_interval' => [
                'insurance_from' => $insuranceFrom,
                'insurance_to' => $insuranceTo,
            ],
            'eldp_sections' => [[
                'ordinal' => 1,
                'code' => $code,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'insurance_days' => $days,
                'assessment_base_czk' => $confirmedBase,
                'excluded_days' => null,
                'deducted_days' => null,
            ]],
            'confirmation' => [
                'in03_active' => false,
                'in04_active' => false,
                'note' => trim($note),
            ],
        ];
        return new JmhzEldpEvidenceSnapshot($payload);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:int,1:array<string,mixed>}
     */
    private function findEmployment(array $input, int $employmentId): array
    {
        $match = null;
        foreach ($this->rows($input['people'] ?? null, 'input.people') as $person) {
            $employee = $this->object($person['employee'] ?? null, 'employee');
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            foreach ($this->rows($person['employments'] ?? null, 'person.employments') as $entry) {
                $employment = $this->object($entry['employment'] ?? null, 'employment');
                if (($employment['id'] ?? null) === $employmentId) {
                    if ($match !== null || ($employment['employee_id'] ?? null) !== $employeeId) {
                        $this->invalid('jmhz_eldp_employment_scope_mismatch', 'Pracovní vztah není ve snapshotu jednoznačný.');
                    }
                    $match = [$employeeId, $entry];
                }
            }
        }
        if ($match === null) {
            $this->invalid('jmhz_eldp_employment_not_found', 'Pracovní vztah není ve zdrojové revizi.');
        }
        return $match;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function socialRelationship(array $result, int $employeeId, int $employmentId): array
    {
        $matchedPerson = null;
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            if (($person['employee_id'] ?? null) !== $employeeId) {
                continue;
            }
            if ($matchedPerson !== null) {
                $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek obsahuje osobu vícekrát.');
            }
            $matchedPerson = $person;
        }
        if ($matchedPerson === null) {
            $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek vztahu chybí.');
        }
        $calculationMatches = 0;
        foreach ($this->rows($matchedPerson['employments'] ?? null, 'result.employments') as $employment) {
            if (($employment['employment_id'] ?? null) === $employmentId) {
                ++$calculationMatches;
            }
        }
        if ($calculationMatches !== 1) {
            $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Výsledek výpočtu nepokrývá pracovní vztah právě jednou.');
        }
        $person = $matchedPerson;
            $statutory = $this->object($person['statutory'] ?? null, 'statutory');
            $social = $this->object($statutory['social_insurance'] ?? null, 'social_insurance');
            if (($social['status'] ?? null) !== 'calculated') {
                $this->invalid('jmhz_eldp_social_not_calculated', 'Sociální pojištění není vypočtené.');
            }
            $match = null;
            foreach ($this->rows($social['relationships'] ?? null, 'social.relationships') as $relationship) {
                if (($relationship['relationship_id'] ?? null) === "employment:{$employmentId}") {
                    if ($match !== null) {
                        $this->invalid('jmhz_eldp_social_relationship_mismatch', 'Sociální výsledek obsahuje vztah vícekrát.');
                    }
                    $match = $relationship;
                }
            }
            $participation = is_array($match)
                && is_array($match['participation'] ?? null)
                ? $match['participation']
                : null;
            if (!is_array($match) || ($match['kind'] ?? null) !== 'employment'
                || ($participation['status'] ?? null) !== 'participates'
                || ($participation['relationship_id'] ?? null) !== "employment:{$employmentId}"
            ) {
                $this->invalid('jmhz_eldp_social_relationship_unsupported', 'Vztah nemá běžnou účast na sociálním pojištění.');
            }
            return $match;
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    private function specManifest(): array
    {
        return $this->specManifest ??= (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
    }

    private function codebook(): JmhzCodebookCatalog
    {
        return new JmhzCodebookCatalog($this->specManifest());
    }

    /** @param array<string,mixed> $workSummary */
    private function assertWorkSummaryConsistency(array $workSummary, int $insuranceDays): void
    {
        $values = $this->object($workSummary['values'] ?? null, 'work_summary.values');
        $interactions = $this->object($workSummary['interactions'] ?? null, 'work_summary.interactions');
        if (($workSummary['conditional_blocks_confirmed'] ?? null) !== true
            || ($values['evidence_days'] ?? null) !== $insuranceDays
            || ($interactions['IN07'] ?? null) !== false
            || ($interactions['IN08'] ?? null) !== false
        ) {
            $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn nepotvrzuje běžný bezabsenční ELDP interval.');
        }
        foreach ([
            'unworked_total_millihours',
            'unworked_paid_millihours',
            'dpn_without_employer_compensation_millihours',
            'dpn_with_employer_compensation_millihours',
            'vacation_millihours',
            'care_millihours',
            'employee_obstacle_paid_millihours',
            'employer_obstacle_millihours',
        ] as $field) {
            if (!array_key_exists($field, $values) || $values[$field] !== null) {
                $this->invalid('jmhz_eldp_work_summary_mismatch', 'Pracovní souhrn obsahuje neodpracované hodiny mimo ordinary ELDP řez.');
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function findCodebook(array $payload, string $key): array
    {
        foreach ($this->rows($payload['codebooks'] ?? null, 'codebooks') as $codebook) {
            if (($codebook['codebook_key'] ?? null) === $key) {
                return $codebook;
            }
        }
        throw new \UnexpectedValueException("Číselník {$key} chybí.");
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $field): array
    {
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            $this->invalid('jmhz_eldp_source_hash_mismatch', "Otisk {$field} snapshotu nesouhlasí.");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $json) {
            $this->invalid('jmhz_eldp_source_invalid', "Snapshot {$field} není kanonický objekt.");
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->invalid('jmhz_eldp_source_invalid', "{$field} obsahuje neplatný řádek.");
            }
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být nezáporné celé číslo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} musí být datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_eldp_source_invalid', "{$field} není platné datum.");
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzEldpEvidenceException($code, $message);
    }
}
