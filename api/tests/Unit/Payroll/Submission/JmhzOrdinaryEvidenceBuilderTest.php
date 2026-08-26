<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceException;
use PHPUnit\Framework\TestCase;

final class JmhzOrdinaryEvidenceBuilderTest extends TestCase
{
    public function testBuildsExplicitFalseEvidenceFromConsistentApprovedRevision(): void
    {
        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $this->source(),
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
        );

        self::assertSame(['10116' => false, '10546' => false], $snapshot->payload['attribute_values']);
        self::assertSame(
            ['IN13', 'IN28', 'IN30'],
            array_column($snapshot->payload['interaction_decisions'], 'interaction_id'),
        );
        self::assertSame(false, $snapshot->payload['derived_interactions'][0]['triggered']);
        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testBuildsOrdinaryEvidenceFromFrozenDefaultProfileWithoutMonthlyConfirmation(): void
    {
        $source = $this->sourceWithOrdinaryProfile();

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
            'derived_from_frozen_payroll_sources',
        );

        self::assertSame(
            'derived_from_frozen_payroll_sources',
            $snapshot->payload['confirmation']['source_kind'],
        );
        self::assertSame(81, $snapshot->payload['confirmation']['source_term_id']);
        self::assertSame(3, $snapshot->payload['confirmation']['source_term_row_version']);
    }

    public function testCurrentApprovedCorrectionCanFreezeOrdinaryEvidence(): void
    {
        $source = $this->sourceWithOrdinaryProfile();
        $source['revision']['revision_no'] = 2;
        $source['revision']['current_revision_no'] = 2;
        $source['revision']['revision_kind'] = 'correction';

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00.000000Z',
            'derived_from_frozen_payroll_sources',
        );

        self::assertSame(2, $snapshot->payload['scope']['revision_no']);
        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testDerivedDefaultNamesLegacyRevisionWithoutOrdinaryProfile(): void
    {
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $this->source(),
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00.000000Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('Stará revize bez zmrazeného profilu musí vyžádat nový přepočet.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame(
                'jmhz_ordinary_evidence_profile_missing',
                $exception->validationCode,
            );
            self::assertStringContainsString('znovu přepočítejte', $exception->getMessage());
        }
    }

    public function testDerivedDefaultRefusesEmploymentMarkedAsDeepMiningException(): void
    {
        $source = $this->sourceWithOrdinaryProfile([
            'deep_mining_work_applies' => true,
        ]);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00.000000Z',
                'derived_from_frozen_payroll_sources',
            );
            self::fail('Výjimečný vztah nesmí dostat automatické nulové potvrzení.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame(
                'jmhz_ordinary_evidence_monthly_exception_required',
                $exception->validationCode,
            );
        }
    }

    public function testMissingEnforcementEvidenceIsRejectedFailClosed(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        unset($input['people'][0]['enforcement_evidence']);
        $this->replaceInput($source, $input);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Chybějící enforcement evidence musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_source_invalid', $exception->validationCode);
        }
    }

    public function testEmptyClaimRegisterDoesNotRequireManualConfirmationWhenFrozenScopeSaysNotApplicable(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['enforcement_evidence']['claim_register_evidence_complete'] = false;
        $this->replaceInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['enforcement']['input']['evidence']['claim_register_complete'] = false;
        $result['people'][0]['enforcement']['result']['evidence_source'] = [
            'claim_register' => 'not_applicable',
            'dependants' => 'not_applicable',
            'spouse' => 'not_applicable',
        ];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $snapshot = (new JmhzOrdinaryEvidenceBuilder())->build(
            7,
            $source,
            101,
            $this->facts(),
            12,
            '2026-08-13T12:00:00Z',
        );

        self::assertSame(101, $snapshot->payload['scope']['employment_id']);
    }

    public function testMissingClaimRegisterScopeIsRejectedWhenMonthlyConfirmationIsFalse(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['enforcement_evidence']['claim_register_evidence_complete'] = false;
        $this->replaceInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['enforcement']['input']['evidence']['claim_register_complete'] = false;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Chybějící rozsah kontroly musí být odmítnut.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_source_invalid', $exception->validationCode);
        }
    }

    public function testResultDeductionIsRejectedEvenWhenInputRegistersAreEmpty(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['net_pay']['deducted_minor_units'] = 100;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $source,
                101,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Srážka ve výsledku musí být odmítnuta.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_deduction_conflict', $exception->validationCode);
        }
    }

    /**
     * Revize se dvěma osobami zmrazí evidenci za KAŽDÝ vztah zvlášť.
     *
     * Bez opravy tady builder skončil `jmhz_ordinary_evidence_scope_unsupported`
     * ("První ordinary profil vyžaduje právě jednu osobu"), takže firma s víc
     * zaměstnanci ordinary evidenci nezmrazila vůbec.
     */
    public function testEachEmploymentOfATwoPersonRevisionGetsItsOwnEvidence(): void
    {
        $source = $this->sourceWithTwoPeople();
        $builder = new JmhzOrdinaryEvidenceBuilder();

        $first = $builder->build(7, $source, 101, $this->facts(), 12, '2026-08-13T12:00:00Z');
        $second = $builder->build(7, $source, 102, $this->facts(), 12, '2026-08-13T12:00:00Z');

        self::assertSame(11, $first->payload['scope']['employee_id']);
        self::assertSame(101, $first->payload['scope']['employment_id']);
        self::assertSame(12, $second->payload['scope']['employee_id']);
        self::assertSame(102, $second->payload['scope']['employment_id']);
    }

    /** Vztah, který v revizi není, musí být pojmenovaná chyba. */
    public function testEvidenceForAnEmploymentOutsideTheRevisionIsRejected(): void
    {
        try {
            (new JmhzOrdinaryEvidenceBuilder())->build(
                7,
                $this->sourceWithTwoPeople(),
                999,
                $this->facts(),
                12,
                '2026-08-13T12:00:00Z',
            );
            self::fail('Vztah mimo revizi musí být odmítnut.');
        } catch (JmhzOrdinaryEvidenceException $exception) {
            self::assertSame('jmhz_ordinary_evidence_scope_mismatch', $exception->validationCode);
        }
    }

    /** @return array{revision:array<string,mixed>} */
    private function sourceWithTwoPeople(): array
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        self::assertIsArray($result);

        $person = $input['people'][0];
        $person['employee']['id'] = 12;
        $person['employments'][0]['employment'] = ['id' => 102, 'employee_id' => 12];
        $input['people'][] = $person;

        $resultPerson = $result['people'][0];
        $resultPerson['employee_id'] = 12;
        $result['people'][] = $resultPerson;

        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $this->replaceInput($source, $input);

        return $source;
    }

    /** @return array<string,false> */
    private function facts(): array
    {
        return [
            'reportable_wage_deductions_recorded' => false,
            'employee_social_discount_claimed' => false,
            'specific_legal_fact_occurred' => false,
            'ozp_employment_support_claimed' => false,
            'deep_mining_work_occurred' => false,
        ];
    }

    /** @return array{revision:array<string,mixed>} */
    private function source(): array
    {
        $enforcement = [
            'claim_register_evidence_complete' => true,
            'claims' => [],
            'dependants_evidence_complete' => false,
            'eligible_dependants' => 0,
            'eligible_spouse' => false,
            'has_multiple_payers' => false,
            'insolvency' => [
                'court_determined_amount_minor_units' => null,
                'decision_verified' => false,
                'employment_id' => null,
                'mode' => 'none',
                'payment_instruction_hash' => null,
                'payment_instruction_id' => null,
                'recipient_verified' => false,
            ],
            'pension_evidence' => 'unknown',
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'spouse_evidence_complete' => false,
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'people' => [[
                'employee' => ['id' => 11],
                'deduction_agreements' => [],
                'enforcement_evidence' => $enforcement,
                'employments' => [[
                    'employment' => ['id' => 101, 'employee_id' => 11],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'statutory' => [
                    'status' => 'calculated',
                    'net_pay' => [
                        'deductions' => [],
                        'deducted_minor_units' => 0,
                    ],
                ],
                'enforcement' => [
                    'input' => [
                        'claims' => [],
                        'evidence' => [
                            'claim_register_complete' => true,
                            'dependants_complete' => false,
                            'eligible_dependants' => 0,
                            'eligible_spouse' => false,
                            'has_multiple_payers' => false,
                            'pension' => 'unknown',
                            'protected_amount_override_minor_units' => null,
                            'protected_amount_override_verified' => false,
                            'spouse_complete' => false,
                        ],
                        'income' => [
                            'excluded_minor_units' => 0,
                            'garnishable_minor_units' => 0,
                            'issues' => [],
                            'status' => 'supported',
                            'trace' => [],
                        ],
                        'insolvency' => [
                            'court_determined_amount_minor_units' => null,
                            'decision_verified' => false,
                            'employment_id' => null,
                            'mode' => 'none',
                            'payment_instruction_hash' => null,
                            'payment_instruction_id' => null,
                            'recipient_verified' => false,
                        ],
                        'payment_date' => '2026-08-15',
                        'period' => '2026-07',
                    ],
                    'result' => [
                        'status' => 'supported',
                        'issues' => [],
                        'allocations' => [],
                        'total_withheld_minor_units' => 0,
                        'insolvency_applied' => false,
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        return ['revision' => [
            'id' => 301,
            'run_id' => 401,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => '2026-07-01',
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
            'ruleset_manifest_hash' => str_repeat('a', 64),
        ]];
    }

    /**
     * @param array<string,bool> $overrides
     * @return array{revision:array<string,mixed>}
     */
    private function sourceWithOrdinaryProfile(array $overrides = []): array
    {
        $source = $this->source();
        $input = json_decode(
            $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        $profile = array_replace([
            'source_term_id' => 81,
            'source_term_row_version' => 3,
            'orchard_discount_eligible' => false,
            'specific_legal_fact_applies' => false,
            'ozp_employment_support_applies' => false,
            'deep_mining_work_applies' => false,
        ], $overrides);
        $input['people'][0]['employments'][0]['ordinary_evidence_profile'] = $profile;
        $this->replaceInput($source, $input);
        return $source;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $input */
    private function replaceInput(array &$source, array $input): void
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
    }

}
