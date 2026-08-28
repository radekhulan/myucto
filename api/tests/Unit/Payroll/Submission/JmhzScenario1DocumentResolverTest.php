<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1DocumentResolverTest extends TestCase
{
    public function testSourceReadyV4RemainsBlockedByUnfrozenLegalDecisions(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->preparation(),
            $this->pvpoj(),
        );

        self::assertSame('blocked', $resolution->status());
        self::assertNotNull($resolution->candidate);
        self::assertSame(
            [
                'jmhz_attribute_10116_unresolved',
                'jmhz_attribute_10546_unresolved',
                'jmhz_interaction_in13_unresolved',
                'jmhz_interaction_in28_unresolved',
                'jmhz_interaction_in30_unresolved',
            ],
            array_column(
                array_map(
                    static fn ($blocker): array => $blocker->toArray(),
                    $resolution->blockers,
                ),
                'code',
            ),
        );
        self::assertSame(
            ['IN13' => null, 'IN28' => null, 'IN30' => null, 'IN36' => null],
            $resolution->candidate->payload['interactions'],
        );
        self::assertSame(
            ['10328' => 1000, '10329' => 1000, '10330' => 0, '10331' => 0],
            $resolution->candidate->payload['people'][0]['employments'][0]
                ['earnings_by_attribute_czk'],
        );
        self::assertSame(
            $resolution->candidate->sha256(),
            (new JmhzScenario1DocumentResolver())
                ->resolve($this->preparation(), $this->pvpoj())
                ->candidate?->sha256(),
        );
    }

    public function testV5UsesImmutableOrdinaryEvidenceWithoutUnresolvedBlockers(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['schema_reference'] = JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE;
        $payload['builder_version'] = JmhzPreparationSnapshotBuilder::BUILDER_VERSION;
        unset($payload['scope']['scenario_key']);
        $payload['scope']['scenario_set'] = ['scenario_1'];
        $payload['ordinary_evidence'] = [[
            'scope' => ['employee_id' => 11, 'employment_id' => 101],
            'attribute_values' => ['10116' => false, '10546' => false],
        ]];
        $payload['source_versions']['ordinary_evidence'] = [[
            'employment_id' => 101,
            'id' => 601,
            'source_manifest_sha256' => str_repeat('4', 64),
            'snapshot_fingerprint' => str_repeat('5', 64),
        ]];
        $preparation = new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );

        self::assertSame('resolved', $resolution->status());
        self::assertSame([], $resolution->blockers);
        self::assertSame(
            'scenario_1',
            $resolution->candidate?->payload['scope']['scenario_key'] ?? null,
        );
        self::assertSame(
            ['IN13' => false, 'IN28' => false, 'IN30' => false, 'IN36' => false],
            $resolution->candidate?->payload['interactions'],
        );
        self::assertFalse(
            $resolution->candidate?->payload['people'][0]['summary']['deductions_recorded'],
        );
        self::assertFalse(
            $resolution->candidate?->payload['people'][0]['summary']
                ['taxpayer_declaration_signed'],
        );
        self::assertSame(
            [
                'base' => 1000,
                'computed' => 150,
                'after_credits' => 150,
                'bonus' => 0,
                'taxable_income' => 1000,
            ],
            $resolution->candidate?->payload['people'][0]['summary']
                ['advance_tax_czk'],
        );
        self::assertSame(
            ['advance_tax_after_credits' => 150, 'tax_bonus' => 0],
            $resolution->candidate?->payload['employer']['summary_totals'],
        );
        self::assertSame(
            [
                'assessment_base_czk' => 1000,
                'reported_income_czk' => 1000,
                'paragraph5_letter' => 'a',
            ],
            $resolution->candidate?->payload['people'][0]['employments'][0]['social_base'],
        );
    }

    public function testV11MixedScenarioPreparationCannotBecomeScenarioOneDocument(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['schema_reference'] = JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE;
        $payload['builder_version'] = JmhzPreparationSnapshotBuilder::BUILDER_VERSION;
        unset($payload['scope']['scenario_key']);
        $payload['scope']['scenario_set'] = ['scenario_1', 'scenario_2'];
        $preparation = $this->withVersionedPayload(
            $preparation,
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            $payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );

        self::assertSame('blocked', $resolution->status());
        self::assertNull($resolution->candidate);
        self::assertSame(
            ['jmhz_scenario1_scope_unsupported'],
            array_map(static fn ($blocker): string => $blocker->code, $resolution->blockers),
        );
    }

    public function testV11OrdinaryAndStatutoryProfilesShareOneRegularDocument(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['schema_reference'] = JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE;
        $payload['builder_version'] = JmhzPreparationSnapshotBuilder::BUILDER_VERSION;
        unset($payload['scope']['scenario_key']);
        $payload['scope']['scenario_set'] = ['scenario_1', 'scenario_3'];
        $payload['ordinary_evidence'] = [
            [
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ],
            [
                'scope' => ['employee_id' => 12, 'employment_id' => 102],
                'attribute_values' => ['10546' => false],
            ],
        ];
        $payload['source_versions']['ordinary_evidence'] = [
            ['employment_id' => 101],
            ['employment_id' => 102],
        ];
        $statutoryPerson = $payload['people'][0];
        $statutoryPerson['employee_id'] = 12;
        $statutoryEmployment = &$statutoryPerson['employments'][0];
        $statutoryEmployment['employment_id'] = 102;
        $statutoryEmployment['employment']['relation_type'] = 'partner_dependent';
        $statutoryEmployment['term']['activity_code'] = 'S';
        $statutoryEmployment['term']['jmhz_relationship_detail_code'] = '1';
        $statutoryEmployment['scenario_resolution'] = [
            'scenario_key' => 'scenario_3',
            'activity_code' => 'S',
            'relationship_detail_code' => '1',
        ];
        $statutoryEmployment['insurance']['relationship_id'] = 'employment:102';
        $statutoryEmployment['insurance']['participation']['relationship_id'] = 'employment:102';
        $statutoryPerson['person_summary']['statutory']['net_pay']['relationships'] = [
            ['relationship_id' => 'employment:102'],
        ];
        unset($statutoryEmployment);
        $payload['people'][] = $statutoryPerson;
        $preparation = $this->withVersionedPayload(
            $preparation,
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            $payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );

        self::assertSame('resolved', $resolution->status());
        self::assertSame([], $resolution->blockers);
        self::assertSame(
            ['scenario_1', 'scenario_3'],
            array_column(
                array_map(
                    static fn (array $person): array => $person['employments'][0]['selector'],
                    $resolution->candidate?->payload['people'] ?? [],
                ),
                'scenario_key',
            ),
        );
    }

    public function testUsesFrozenPayslipAllocationForEmployerSocialContribution(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset(
            $payload['people'][0]['person_summary']['statutory']['social_insurance']
                ['employer_contribution_minor_units'],
        );
        $payload['people'][0]['person_summary']['payslip_document'] = [
            'employer_social_minor_units' => 24_800,
        ];

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
            $this->pvpoj(),
        );

        self::assertSame(248, $resolution->candidate?->payload['people'][0]
            ['summary']['employer_social_czk']);
        self::assertNotContains(
            'jmhz_scenario1_whole_czk_required',
            array_column(
                array_map(
                    static fn ($blocker): array => $blocker->toArray(),
                    $resolution->blockers,
                ),
                'code',
            ),
        );
    }

    public function testHistoricalPreparationIsVerifiedButNotNormalized(): void
    {
        $preparation = $this->preparation();
        $preparation = new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            JmhzPreparationSnapshotBuilder::PREVIOUS_BUILDER_VERSION,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $preparation->payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            null,
        );

        self::assertNull($resolution->candidate);
        self::assertSame(
            'jmhz_scenario1_source_version_unsupported',
            $resolution->blockers[0]->code,
        );
    }

    public function testMissingStatutoryBranchesAndPvpojAreExplicitBlockers(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['person_summary']['statutory']['health_insurance']);
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']);
        unset($payload['people'][0]['person_summary']['statutory']['net_pay']);
        $preparation = $this->withPayload($preparation, $payload);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            null,
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_health_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_income_tax_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_net_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_pvpoj_unavailable', $codes);
    }

    public function testMissingHealthOrTaxIssuesEvidenceIsNotTreatedAsEmpty(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['person_summary']['statutory']['health_insurance']['issues']);
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']['issues']);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_health_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_income_tax_result_not_calculated', $codes);
        self::assertNotContains('jmhz_scenario1_net_result_not_calculated', $codes);
    }

    public function testMissingWithholdingOrDeductionKeysNeverBecomeSilentZero(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']['withholding_tax_minor_units']);
        unset($payload['people'][0]['person_summary']['statutory']['net_pay']['deductions']);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_income_tax_result_not_calculated', $codes);
        self::assertContains('jmhz_scenario1_net_result_not_calculated', $codes);
    }

    public function testMissingExplicitZeroAndHalereNeverBecomeSilentZero(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset($payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10330']);
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10329'] = 100_001;
        $preparation = $this->withPayload($preparation, $payload);

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_earnings_vector_incomplete', $codes);
        self::assertContains('jmhz_scenario1_whole_czk_required', $codes);
        self::assertArrayNotHasKey(
            '10329',
            $resolution->candidate?->payload['people'][0]['employments'][0]
                ['earnings_by_attribute_czk'] ?? [],
        );
    }

    public function testFullyAppliedTaxCreditIsCarriedAsPerKindBreakdown(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload(
                $this->preparation(),
                $this->payloadWithCredits(257_000, 257_000, [
                    'taxpayer' => 257_000,
                ]),
            ),
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertNotContains(
            'jmhz_scenario1_tax_credit_breakdown_unavailable',
            $codes,
        );
        self::assertSame(
            [
                'basic' => 2570,
                'disability_basic' => null,
                'disability_extended' => null,
                'ztp_p' => null,
            ],
            $resolution->candidate?->payload['people'][0]['summary']
                ['tax_credits_czk'],
        );
    }

    public function testPartiallyAppliedTaxCreditIsBlockedInsteadOfSplitByGuess(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload(
                $this->preparation(),
                $this->payloadWithCredits(257_000, 150_00, [
                    'taxpayer' => 257_000,
                ]),
            ),
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains(
            'jmhz_scenario1_partial_tax_credit_unsupported',
            $codes,
        );
        self::assertNull(
            $resolution->candidate?->payload['people'][0]['summary']
                ['tax_credits_czk']['basic'],
        );
    }

    public function testBreakdownThatDoesNotSumToTheClaimedTotalIsRefused(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload(
                $this->preparation(),
                $this->payloadWithCredits(257_000, 257_000, [
                    'taxpayer' => 200_000,
                ]),
            ),
            $this->pvpoj(),
        );

        self::assertContains(
            'jmhz_scenario1_tax_credit_breakdown_unavailable',
            array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    public function testChildCreditStaysBlockedUntilItsOwnBlockIsFrozen(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['people'][0]['person_summary']['statutory']['income_tax']
            ['advance_tax']['child_credit_minor_units'] = 161_700;

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
            $this->pvpoj(),
        );

        self::assertContains(
            'jmhz_scenario1_child_credit_breakdown_unavailable',
            array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    /**
     * @param array<string,int> $breakdown
     * @return array<string,mixed>
     */
    private function payloadWithCredits(
        int $claimed,
        int $applied,
        array $breakdown,
    ): array {
        $payload = $this->preparation()->payload;
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = $claimed;
        $tax['applied_non_refundable_credits_minor_units'] = $applied;
        $tax['claimed_non_refundable_credit_breakdown'] = $breakdown;
        $tax['advance_tax']['non_refundable_credits_minor_units'] = $claimed;
        unset($tax);
        $payload['people'][0]['employments'][0]['term']
            ['tax_declaration_signed'] = true;

        return $payload;
    }

    public function testMissingAdvanceTaxKeysNeverBecomeSilentZero(): void
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        unset(
            $payload['people'][0]['person_summary']['statutory']['income_tax']
                ['advance_tax']['tax_after_credits_minor_units'],
            $payload['people'][0]['employments'][0]['term']
                ['tax_declaration_signed'],
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->withPayload($preparation, $payload),
            $this->pvpoj(),
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_scenario1_advance_tax_incomplete', $codes);
        self::assertContains('jmhz_taxpayer_declaration_unresolved', $codes);
        self::assertNull(
            $resolution->candidate?->payload['people'][0]['summary']
                ['advance_tax_czk']['after_credits'],
        );
        self::assertNull(
            $resolution->candidate?->payload['people'][0]['summary']
                ['taxpayer_declaration_signed'],
        );
        self::assertNull(
            $resolution->candidate?->payload['employer']['summary_totals']
                ['advance_tax_after_credits'],
        );
    }

    public function testBlockedCandidateCannotBeUsedAsResolvedDocument(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->preparation(),
            $this->pvpoj(),
        );

        $this->expectException(JmhzPreparationSnapshotException::class);
        try {
            $resolution->requireResolvedDocument();
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_scenario1_resolution_blocked',
                $exception->validationCode,
            );
            throw $exception;
        }
    }

    /**
     * Hlášení se podává za REGISTRACI u OSSZ, takže z revize přes dvě mzdové
     * účtárny musí vzniknout dvě datové věty: každá pod svým variabilním
     * symbolem a jen se svými součástmi. Kdyby jedna věta nesla obě populace,
     * kontrola 12 ČSSZ (pojistné zaměstnanců proti součtu součástí) by nikdy
     * neseděla a lidé by šli pod cizí registraci.
     */
    public function testEachRegistrationGetsItsOwnVariableSymbolAndPeople(): void
    {
        $preparation = $this->multiOfficePreparation();

        $first = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(),
            null,
            4,
        );
        $second = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(officeId: 5, variableSymbol: '9990001234'),
            null,
            5,
        );

        self::assertSame([], $first->blockers);
        self::assertSame([], $second->blockers);
        self::assertSame(
            '1234567890',
            $first->candidate?->payload['header']['variable_symbol'],
        );
        self::assertSame(
            '9990001234',
            $second->candidate?->payload['header']['variable_symbol'],
        );
        self::assertSame(
            [11],
            array_column($first->candidate?->payload['people'] ?? [], 'employee_id'),
        );
        self::assertSame(
            [12],
            array_column($second->candidate?->payload['people'] ?? [], 'employee_id'),
        );
        self::assertSame(4, $first->candidate?->payload['scope']['office_id']);
        self::assertSame(5, $second->candidate?->payload['scope']['office_id']);
        self::assertNotSame(
            $first->candidate?->sha256(),
            $second->candidate?->sha256(),
        );
    }

    public function testSelectedRegistrationIgnoresReadinessIssuesFromAnotherOffice(): void
    {
        $preparation = $this->multiOfficePreparation();
        $payload = $preparation->payload;
        $payload['readiness_issues'] = [
            ['code' => 'office_four_employment', 'entity_type' => 'employment', 'entity_id' => 101, 'attribute_ids' => []],
            ['code' => 'office_five_employment', 'entity_type' => 'employment', 'entity_id' => 102, 'attribute_ids' => []],
            ['code' => 'office_four_person', 'entity_type' => 'person', 'entity_id' => 11, 'attribute_ids' => []],
            ['code' => 'office_five_person', 'entity_type' => 'person', 'entity_id' => 12, 'attribute_ids' => []],
            ['code' => 'office_four_registration', 'entity_type' => 'office', 'entity_id' => 4, 'attribute_ids' => []],
            ['code' => 'office_five_registration', 'entity_type' => 'office', 'entity_id' => 5, 'attribute_ids' => []],
            ['code' => 'whole_revision', 'entity_type' => 'revision', 'entity_id' => 301, 'attribute_ids' => []],
        ];
        $readiness = $preparation->readiness;
        $readiness['status'] = 'blocked';
        $readiness['issue_count'] = count($payload['readiness_issues']);
        $preparation = new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $preparation->builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $readiness,
            $payload,
        );

        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $this->pvpoj(officeId: 5, variableSymbol: '9990001234'),
            null,
            5,
        );
        $codes = array_map(
            static fn ($blocker): string => $blocker->code,
            $resolution->blockers,
        );

        self::assertContains('jmhz_preparation_not_ready', $codes);
        self::assertContains('office_five_employment', $codes);
        self::assertContains('office_five_person', $codes);
        self::assertContains('office_five_registration', $codes);
        self::assertContains('whole_revision', $codes);
        self::assertNotContains('office_four_employment', $codes);
        self::assertNotContains('office_four_person', $codes);
        self::assertNotContains('office_four_registration', $codes);
    }

    public function testRunWithTwoRegistrationsNeverPicksOneSilently(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->multiOfficePreparation(),
            $this->pvpoj(),
        );

        self::assertContains(
            'jmhz_social_multiple_offices',
            array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
        self::assertNull(
            $resolution->candidate?->payload['header']['variable_symbol'],
        );
    }

    public function testPvpojOfAnotherRegistrationIsRefused(): void
    {
        $resolution = (new JmhzScenario1DocumentResolver())->resolve(
            $this->multiOfficePreparation(),
            $this->pvpoj(officeId: 5, variableSymbol: '9990001234'),
            null,
            4,
        );

        self::assertContains(
            'jmhz_scenario1_pvpoj_source_mismatch',
            array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    /**
     * Příprava v7 se dvěma registracemi: employee 11 v účtárně 4,
     * employee 12 v účtárně 5 — a s ordinary evidencí za KAŽDÝ vztah.
     */
    private function multiOfficePreparation(): JmhzVerifiedPreparationSnapshot
    {
        $preparation = $this->preparation();
        $payload = $preparation->payload;
        $payload['schema_reference'] = JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE;
        $payload['builder_version'] = JmhzPreparationSnapshotBuilder::BUILDER_VERSION;
        unset($payload['scope']['scenario_key']);
        $payload['scope']['scenario_set'] = ['scenario_1'];
        $payload['ordinary_evidence'] = [
            [
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ],
            [
                'scope' => ['employee_id' => 12, 'employment_id' => 102],
                'attribute_values' => ['10116' => false, '10546' => false],
            ],
        ];
        $payload['source_versions']['ordinary_evidence'] = [
            [
                'employment_id' => 101,
                'id' => 601,
                'source_manifest_sha256' => str_repeat('4', 64),
                'snapshot_fingerprint' => str_repeat('5', 64),
            ],
            [
                'employment_id' => 102,
                'id' => 602,
                'source_manifest_sha256' => str_repeat('6', 64),
                'snapshot_fingerprint' => str_repeat('7', 64),
            ],
        ];
        $payload['employer_summary']['office'] = null;
        $payload['employer_summary']['offices'] = [
            [
                'id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'social_security_variable_symbol' => '1234567890',
            ],
            [
                'id' => 5,
                'code' => 'UC5',
                'name' => 'Mzdová účtárna 5',
                'social_security_variable_symbol' => '9990001234',
            ],
        ];
        $payload['people'][0]['employments'][0]['employment']['office_id'] = 4;
        $second = $payload['people'][0];
        $second['employee_id'] = 12;
        $second['employments'][0]['employment_id'] = 102;
        $second['employments'][0]['employment']['office_id'] = 5;
        $second['employments'][0]['insurance']['relationship_id'] = 'employment:102';
        $second['person_summary']['statutory']['net_pay']['relationships']
            = [['relationship_id' => 'employment:102']];
        $payload['people'][] = $second;

        return $this->withVersionedPayload(
            $preparation,
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            $payload,
        );
    }

    /** @param array<string,mixed> $payload */
    private function withVersionedPayload(
        JmhzVerifiedPreparationSnapshot $preparation,
        string $builderVersion,
        array $payload,
    ): JmhzVerifiedPreparationSnapshot {
        return new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $payload,
        );
    }

    private function preparation(): JmhzVerifiedPreparationSnapshot
    {
        $payload = [
            'schema_reference' => 'payroll-jmhz-preparation-source.v4',
            'builder_version' => JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [['relationship_id' => 'employment:101']],
                            'net_before_deductions_minor_units' => 86_500,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 86_500,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => ['value' => '2000000000000000000001'],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => ['confirmation' => ['in03_active' => false, 'in04_active' => false]],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'interactions' => ['IN07' => false, 'IN08' => false],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => [
                        'relationship_id' => 'employment:101',
                        'kind' => 'employment',
                        'participation' => [
                            'relationship_id' => 'employment:101',
                            'status' => 'participates',
                            'participation_income_minor_units' => 100_000,
                        ],
                        'assessment_base_minor_units' => 100_000,
                        'capped_assessment_base_minor_units' => 100_000,
                        'employer_rate_category' => 'ordinary',
                    ],
                ]],
            ]],
            'source_versions' => ['office_id' => 9, 'employments' => []],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
        $readiness = [
            'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
            'status' => 'source_ready',
            'issue_count' => 0,
            'issues' => [],
            'official_submission_supported' => false,
        ];
        return new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_1',
            JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            $readiness,
            $payload,
        );
    }

    private function pvpoj(
        int $officeId = 4,
        string $variableSymbol = '1234567890',
    ): JmhzPvpojPreview {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            [
                'office_id' => $officeId,
                'code' => 'UC' . $officeId,
                'name' => 'Mzdová účtárna ' . $officeId,
                'variable_symbol' => $variableSymbol,
            ],
            [[
                'office_id' => $officeId,
                'employee_contribution_minor_units' => 7_100,
                'employer_contribution_minor_units' => 24_800,
                'amount_minor_units' => 31_900,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnance' => 71,
                ],
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function withPayload(
        JmhzVerifiedPreparationSnapshot $preparation,
        array $payload,
    ): JmhzVerifiedPreparationSnapshot {
        return new JmhzVerifiedPreparationSnapshot(
            $preparation->id,
            $preparation->supplierId,
            $preparation->environment,
            $preparation->runId,
            $preparation->sourceRevisionId,
            $preparation->revisionNo,
            $preparation->periodStart,
            $preparation->periodEnd,
            $preparation->scenarioKey,
            $preparation->builderVersion,
            $preparation->sourceManifestSha256,
            $preparation->readinessSha256,
            $preparation->snapshotFingerprint,
            $preparation->manifest,
            $preparation->readiness,
            $payload,
        );
    }
}
