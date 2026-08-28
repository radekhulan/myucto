<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException;
use PHPUnit\Framework\TestCase;

final class JmhzEldpEvidenceBuilderTest extends TestCase
{
    public function testBuildsOneOrdinarySectionFromApprovedEvidence(): void
    {
        $snapshot = (new JmhzEldpEvidenceBuilder())->build(
            7,
            101,
            $this->source(),
            $this->confirmation(),
        );

        self::assertSame('payroll-jmhz-eldp-evidence.v1', $snapshot->payload['schema_reference']);
        self::assertSame('1++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
        self::assertSame(10_000, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertNull($snapshot->payload['eldp_sections'][0]['excluded_days']);
        self::assertNull($snapshot->payload['eldp_sections'][0]['deducted_days']);
        self::assertSame(
            'b78f8fef6e2b4c54b33d1ce5116c89b1ac229458c38e5d5dc482fb955f0d476f',
            $snapshot->payload['specification']['eldp_code_row_sha256'],
        );
    }

    public function testBuildsEvidenceFromCurrentApprovedCorrectionRevision(): void
    {
        $source = $this->source();
        $source['revision']['revision_no'] = 2;
        $source['revision']['current_revision_no'] = 2;
        $source['revision']['revision_kind'] = 'correction';

        $snapshot = (new JmhzEldpEvidenceBuilder())->build(
            7,
            101,
            $source,
            $this->confirmation(),
        );

        self::assertSame(401, $snapshot->payload['scope']['source_revision_id']);
        self::assertSame('1++', $snapshot->payload['eldp_sections'][0]['code']);
    }

    public function testRejectsOffByOneCalendarDay(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['insurance_days'] = 30;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('inkluzivnímu intervalu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $this->source(), $confirmation);
    }

    public function testUsesUncappedAssessmentBaseForEldp(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 999_900;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $snapshot = (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());

        self::assertSame(10_000, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
    }

    public function testRejectsImplicitOrActiveInteractions(): void
    {
        $confirmation = $this->confirmation();
        unset($confirmation['in04_active']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('explicitní Ne');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $this->source(), $confirmation);
    }

    public function testRejectsUnsupportedActivityAndCodeInference(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['term']['activity_code'] = '15';
        $input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code'] = '1';
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('neodpovídá druhu pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testDerivesParticipatingDpcWithFullInsuranceMonth(): void
    {
        $source = $this->agreementSource('dpc', 'A', 'dpc', 'participates', 1_200_000, 1_200_000, 1_200_000);

        $confirmation = (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);

        self::assertSame('A++', $confirmation['code']);
        self::assertSame(31, $confirmation['insurance_days']);
        self::assertSame(12_000, $confirmation['assessment_base_czk']);
    }

    public function testDerivesStatutoryBodyAsSPlusPlusInScenarioThree(): void
    {
        $source = $this->agreementSource(
            'statutory_body',
            'S',
            'corporate_body',
            'participates',
            450_000,
            450_000,
            450_000,
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 31;
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('scenario_3', $snapshot->payload['scope']['scenario_key']);
        self::assertSame('S++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(4_500, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
    }

    public function testDerivesPartnerDependentActivityAsSPlusPlusInScenarioThree(): void
    {
        $source = $this->agreementSource(
            'partner_dependent',
            'S',
            'corporate_body',
            'participates',
            450_000,
            450_000,
            450_000,
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 31;
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('scenario_3', $snapshot->payload['scope']['scenario_key']);
        self::assertSame('S++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(4_500, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
    }

    public function testDerivesNonParticipatingDppAsCodeLessZeroDaySection(): void
    {
        $source = $this->agreementSource('dpp', 'T', 'dpp', 'does_not_participate', 640_000, 0, 0);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
        $section = $snapshot->payload['eldp_sections'][0];

        self::assertSame('2026-07-01', $snapshot->payload['insurance_interval']['insurance_from']);
        self::assertSame('2026-07-31', $snapshot->payload['insurance_interval']['insurance_to']);
        self::assertNull($section['valid_from']);
        self::assertNull($section['valid_to']);
        self::assertSame(0, $section['insurance_days']);
        self::assertNull($section['code']);
        self::assertNull($section['assessment_base_czk']);
        self::assertNull($snapshot->payload['specification']['eldp_code_row_sha256']);
    }

    public function testRejectsRelationAndActivityFamilyMismatch(): void
    {
        $source = $this->agreementSource('dpp', 'A', 'dpp', 'does_not_participate', 640_000, 0, 0);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('neodpovídá druhu pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    public function testDerivesParticipatingDppWithPinnedTPlusPlusCode(): void
    {
        $source = $this->agreementSource('dpp', 'T', 'dpp', 'participates', 640_000, 640_000, 640_000);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('T++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(6_400, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
        self::assertNotNull($snapshot->payload['specification']['eldp_code_row_sha256']);
    }

    public function testKeepsNonParticipatingDpcFailClosed(): void
    {
        $source = $this->agreementSource('dpc', 'A', 'dpc', 'does_not_participate', 300_000, 0, 0);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('účastný pracovní poměr, DPČ, DPP');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    public function testRejectsFractionalCzechCrownWithoutRounding(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['assessment_base_minor_units'] = 1_000_001;
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 1_000_001;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('celé Kč');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsMismatchedNestedParticipationRelationship(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['participation']['relationship_id'] = 'employment:999';
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('jednoznačný výsledek účasti');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsEvidenceDaysDifferentFromFrozenWorkSummary(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['evidence_days'] = 30;
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Pracovní souhrn');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsUnworkedInteractionInOrdinarySlice(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['interactions']['IN07'] = true;
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Pracovní souhrn');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testAllowsConfirmedVacationWithoutEldpExcludedOrDeductedDays(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $entry = &$input['people'][0]['employments'][0];
        $entry['absences'] = [[
            'id' => 901,
            'absence_type' => 'vacation',
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-14',
        ]];
        $summary = &$entry['time_month']['jmhz_work_summary'];
        $summary['interactions']['IN07'] = true;
        $summary['values']['unworked_total_millihours'] = 16_000;
        $summary['values']['unworked_paid_millihours'] = 16_000;
        $summary['values']['vacation_millihours'] = 16_000;
        unset($summary, $entry);
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );

        self::assertNull($snapshot->payload['eldp_sections'][0]['excluded_days']);
        self::assertNull($snapshot->payload['eldp_sections'][0]['deducted_days']);
        self::assertFalse($snapshot->payload['confirmation']['in03_active']);
        self::assertFalse($snapshot->payload['confirmation']['in04_active']);
    }

    public function testKeepsUnpaidLeaveFailClosedWithoutEldpInteractionEvidence(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'] = [[
            'id' => 902,
            'absence_type' => 'unpaid_leave',
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-14',
        ]];
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('placenou dovolenou');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    public function testRejectsIntervalShorterThanFrozenEmploymentMonth(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['evidence_days'] = 29;
        $source = $this->withInput($source, $input);
        $confirmation = $this->confirmation();
        $confirmation['insurance_from'] = '2026-07-02';
        $confirmation['insurance_to'] = '2026-07-30';
        $confirmation['valid_from'] = '2026-07-02';
        $confirmation['valid_to'] = '2026-07-30';
        $confirmation['insurance_days'] = 29;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('průniku pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $confirmation);
    }

    public function testRejectsZeroAssessmentBaseForPositiveOrdinarySection(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['assessment_base_minor_units'] = 0;
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 0;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $confirmation = $this->confirmation();
        $confirmation['assessment_base_czk'] = 0;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('kladné celé číslo');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $confirmation);
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withInput(array $source, array $input): array
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        return $source;
    }

    /** @return array<string,mixed> */
    private function confirmation(): array
    {
        return [
            'insurance_from' => '2026-07-01',
            'insurance_to' => '2026-07-31',
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-07-31',
            'insurance_days' => 31,
            'code' => '1++',
            'assessment_base_czk' => 10_000,
            'in03_active' => false,
            'in04_active' => false,
            'confirmation_note' => 'Syntetické potvrzení běžného měsíce bez zvláštností.',
        ];
    }

    /** @return array<string,mixed> */
    private function agreementSource(
        string $relationType,
        string $activityCode,
        string $kind,
        string $participationStatus,
        int $participationIncomeMinor,
        int $assessmentBaseMinor,
        int $cappedAssessmentBaseMinor,
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['employment']['relation_type'] = $relationType;
        $input['people'][0]['employments'][0]['term']['activity_code'] = $activityCode;
        $input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code'] =
            in_array($relationType, ['dpc', 'dpp'], true) ? null : '1';
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 0;
        $source = $this->withInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $relationship = &$result['people'][0]['statutory']['social_insurance']['relationships'][0];
        $relationship['kind'] = $kind;
        $relationship['participation']['status'] = $participationStatus;
        $relationship['participation']['participation_income_minor_units'] = $participationIncomeMinor;
        $relationship['participation']['group_income_minor_units'] = $participationIncomeMinor;
        $relationship['assessment_base_minor_units'] = $assessmentBaseMinor;
        $relationship['capped_assessment_base_minor_units'] = $cappedAssessmentBaseMinor;
        unset($relationship);
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        return $source;
    }

    /** @return array<string,mixed> */
    private function source(): array
    {
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'people' => [[
                'employee' => ['id' => 11],
                'employments' => [[
                    'employment' => [
                        'id' => 101,
                        'employee_id' => 11,
                        'relation_type' => 'employment',
                        'start_date' => '2026-01-01',
                        'actual_start_date' => '2026-01-01',
                        'end_date' => null,
                    ],
                    'term' => [
                        'id' => 201,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'time_month' => [
                        'jmhz_work_summary' => [
                            'id' => 301,
                            'derivation_version' => 'jmhz-work-month.v2',
                            'summary_sha256' => str_repeat('d', 64),
                            'conditional_blocks_confirmed' => true,
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'evidence_days' => 31,
                                'unworked_total_millihours' => null,
                                'unworked_paid_millihours' => null,
                                'dpn_without_employer_compensation_millihours' => null,
                                'dpn_with_employer_compensation_millihours' => null,
                                'vacation_millihours' => null,
                                'care_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'absences' => [],
                    'inputs' => [],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'employments' => [[
                    'employment_id' => 101,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'relationships' => [[
                            'relationship_id' => 'employment:101',
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => 'employment:101',
                                'status' => 'participates',
                                'reason_codes' => [],
                                'participation_income_minor_units' => 1_000_000,
                                'group_income_minor_units' => 1_000_000,
                            ],
                            'assessment_base_minor_units' => 1_000_000,
                            'capped_assessment_base_minor_units' => 1_000_000,
                        ]],
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        return [
            'revision' => [
                'id' => 401,
                'run_id' => 501,
                'revision_no' => 1,
                'current_revision_no' => 1,
                'revision_kind' => 'regular',
                'status' => 'approved',
                'period_start' => '2026-07-01',
                'ruleset_manifest_hash' => str_repeat('a', 64),
                'input_snapshot_json' => $inputJson,
                'input_snapshot_hash' => hash('sha256', $inputJson),
                'result_snapshot_json' => $resultJson,
                'result_snapshot_hash' => hash('sha256', $resultJson),
            ],
        ];
    }
}
