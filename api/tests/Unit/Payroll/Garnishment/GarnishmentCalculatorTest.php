<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDeductionPolicy2026;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentBatchCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GarnishmentCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array{
     *   name:string,
     *   net_minor_units:int,
     *   eligible_dependants:int,
     *   eligible_spouse:bool,
     *   spouse_pension:string,
     *   claim_category:string,
     *   expected_protected_minor_units:int,
     *   expected_third_minor_units:int,
     *   expected_excess_minor_units:int,
     *   expected_withheld_minor_units:int
     * }}>
     */
    public static function goldenCases(): iterable
    {
        $fixture = file_get_contents(dirname(__DIR__, 3) . '/Fixtures/Payroll/garnishment-2026-golden.json');
        self::assertIsString($fixture);
        $decoded = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['cases'] ?? null);

        foreach ($decoded['cases'] as $case) {
            self::assertIsArray($case);
            $name = $case['name'] ?? null;
            $net = $case['net_minor_units'] ?? null;
            $dependants = $case['eligible_dependants'] ?? null;
            $spouse = $case['eligible_spouse'] ?? null;
            $spousePension = $case['spouse_pension'] ?? null;
            $category = $case['claim_category'] ?? null;
            $protected = $case['expected_protected_minor_units'] ?? null;
            $third = $case['expected_third_minor_units'] ?? null;
            $excess = $case['expected_excess_minor_units'] ?? null;
            $withheld = $case['expected_withheld_minor_units'] ?? null;
            self::assertIsString($name);
            self::assertIsInt($net);
            self::assertIsInt($dependants);
            self::assertIsBool($spouse);
            self::assertIsString($spousePension);
            self::assertIsString($category);
            self::assertIsInt($protected);
            self::assertIsInt($third);
            self::assertIsInt($excess);
            self::assertIsInt($withheld);

            yield $name => [[
                'name' => $name,
                'net_minor_units' => $net,
                'eligible_dependants' => $dependants,
                'eligible_spouse' => $spouse,
                'spouse_pension' => $spousePension,
                'claim_category' => $category,
                'expected_protected_minor_units' => $protected,
                'expected_third_minor_units' => $third,
                'expected_excess_minor_units' => $excess,
                'expected_withheld_minor_units' => $withheld,
            ]];
        }
    }

    /**
     * @param array{
     *   name:string,
     *   net_minor_units:int,
     *   eligible_dependants:int,
     *   eligible_spouse:bool,
     *   spouse_pension:string,
     *   claim_category:string,
     *   expected_protected_minor_units:int,
     *   expected_third_minor_units:int,
     *   expected_excess_minor_units:int,
     *   expected_withheld_minor_units:int
     * } $case
     */
    #[DataProvider('goldenCases')]
    public function testSyntheticGoldenCases(array $case): void
    {
        $result = $this->calculate(
            (int) $case['net_minor_units'],
            [
                $this->statutoryClaim(
                    'claim-1',
                    ClaimCategory::from((string) $case['claim_category']),
                    10_000_000,
                ),
            ],
            (int) $case['eligible_dependants'],
            (bool) $case['eligible_spouse'],
            spousePensionEvidence: SpousePensionEvidence::from(
                (string) $case['spouse_pension'],
            ),
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame($case['expected_protected_minor_units'], $result->protectedAmountMinorUnits);
        self::assertSame($case['expected_third_minor_units'], $result->thirdMinorUnits);
        self::assertSame($case['expected_excess_minor_units'], $result->fullyAttachableExcessMinorUnits);
        self::assertSame($case['expected_withheld_minor_units'], $result->totalWithheldMinorUnits);
        self::assertSame(
            $case['net_minor_units'] - $case['expected_withheld_minor_units'],
            $result->employeePaymentMinorUnits,
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->rulesetHash);
    }

    /**
     * Nařízení vlády č. 441/2024 Sb. účinné od 1. 1. 2025: čtvrtina na manžela
     * náleží jen při doloženém starobním, invalidním 2./3. stupně nebo
     * sirotčím důchodu povinného nebo jeho manžela/partnera.
     */
    public function testSpouseWithoutDocumentedPensionDoesNotRaiseProtectedAmount(): void
    {
        $result = $this->calculate(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::NotDocumented,
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(1_410_200, $result->protectedAmountMinorUnits);
        self::assertSame(863_200, $result->totalWithheldMinorUnits);
    }

    public function testDocumentedPensionAddsSpouseQuarter(): void
    {
        $result = $this->calculate(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::Documented,
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(1_762_700, $result->protectedAmountMinorUnits);
        self::assertSame(745_700, $result->totalWithheldMinorUnits);
    }

    /**
     * Nedoložený a nezjištěný stav dávají tutéž nezabavitelnou částku —
     * zákonná podmínka není splněna ani v jednom případě. Liší se jen tím,
     * že nezjištěný stav shodí měsíc do ručního posouzení.
     */
    public function testUnknownSpousePensionBlocksTheMonthInsteadOfSilentlyRecalculating(): void
    {
        $result = $this->calculate(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::Unknown,
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertSame(
            [
                'spouse_allowance_evidence_incomplete',
                'spouse_quarter_pension_evidence_unknown',
            ],
            $result->issues,
        );
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    /**
     * Výslovné „důchod doložen není" je úplná evidence, ne chybějící podklad —
     * blokátor se nesmí objevit.
     */
    public function testNotDocumentedSpousePensionIsNotAnEvidenceGap(): void
    {
        $result = $this->calculate(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::NotDocumented,
        );

        self::assertSame([], $result->issues);
    }

    public function testSpouseQuarterCountsAlongsideChildrenOnlyWhenDocumented(): void
    {
        $documented = $this->calculate(
            6_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleDependants: 2,
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::Documented,
        );
        $undocumented = $this->calculate(
            6_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleDependants: 2,
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::NotDocumented,
        );

        self::assertSame(2_467_800, $documented->protectedAmountMinorUnits);
        self::assertSame(2_115_300, $undocumented->protectedAmountMinorUnits);
        self::assertSame(
            352_500,
            $documented->protectedAmountMinorUnits
                - $undocumented->protectedAmountMinorUnits,
        );
    }

    /**
     * Čtvrtiny se sčítají a teprve součet se zaokrouhluje nahoru na celé
     * koruny. Manžel + jedno dítě proto dává 21 153 Kč, ne 21 154 Kč, které
     * by vyšly ze dvou samostatně zaokrouhlených čtvrtin.
     */
    public function testSpouseQuarterRoundsUpOnlyAfterTheAllowancesAreSummed(): void
    {
        $result = $this->calculate(
            6_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleDependants: 1,
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::Documented,
        );

        self::assertSame(2_115_300, $result->protectedAmountMinorUnits);
    }

    /**
     * Při souběhu plátců určuje nezabavitelnou částku soud — doložení důchodu
     * na ni nemá vliv a nezjištěný stav nesmí shodit běh do blokátoru.
     */
    public function testMultiplePayersIgnoreTheSpousePensionEvidence(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))
            ->calculate($this->input(
                4_000_000,
                [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
                eligibleSpouse: true,
                spousePensionEvidence: SpousePensionEvidence::Unknown,
                hasMultiplePayers: true,
                protectedAmountOverrideMinorUnits: 1_800_000,
            ));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(1_800_000, $result->protectedAmountMinorUnits);
        self::assertNotContains(
            'spouse_quarter_pension_evidence_unknown',
            $result->issues,
        );
    }

    /**
     * Snímek pořízený před novelou klíč neobsahuje — načte se fail-closed jako
     * nezjištěný stav, ne jako doložený důchod.
     */
    public function testLegacySnapshotWithoutSpousePensionKeyReadsAsUnknown(): void
    {
        $snapshot = $this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleSpouse: true,
            spousePensionEvidence: SpousePensionEvidence::Documented,
        )->toCanonicalArray();
        unset($snapshot['evidence']['spouse_pension']);

        $restored = GarnishmentInput::fromCanonicalArray($snapshot);

        self::assertSame(
            SpousePensionEvidence::Unknown,
            $restored->spousePensionEvidence,
        );
    }

    public function testFourActiveEnforcementsUnlockSecondThirdForNonPriorityClaims(): void
    {
        $claims = [];
        foreach (range(1, 4) as $number) {
            $claims[] = $this->statutoryClaim(
                "claim-{$number}",
                ClaimCategory::NonPriority,
                10_000_000,
                "2026-01-0{$number}",
            );
        }

        $result = $this->calculate(4_000_000, $claims);

        self::assertTrue($result->fourEnforcementRuleApplied);
        self::assertSame(1_726_400, $result->totalWithheldMinorUnits);
    }

    public function testPensionExceptionKeepsOneThirdWhenThirdIsBelowAdministratorMinimum(): void
    {
        $claims = [];
        foreach (range(1, 4) as $number) {
            $claims[] = $this->statutoryClaim(
                "claim-{$number}",
                ClaimCategory::NonPriority,
                1_000_000,
                "2026-01-0{$number}",
            );
        }

        $result = $this->calculate(1_700_000, $claims, pensionEvidence: PensionEvidence::Verified);

        self::assertFalse($result->fourEnforcementRuleApplied);
        self::assertSame(96_600, $result->thirdMinorUnits);
        self::assertSame(96_600, $result->totalWithheldMinorUnits);
    }

    public function testSameDayClaimsAreAllocatedProportionallyAndDeterministically(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-a',
                ClaimCategory::NonPriority,
                1_000_000,
                orderIssuedOn: '2021-12-31',
            ),
            $this->statutoryClaim(
                'claim-b',
                ClaimCategory::NonPriority,
                2_000_000,
                orderIssuedOn: '2021-12-31',
            ),
        ]);

        self::assertSame(287_733, $result->allocationFor('claim-a')?->totalMinorUnits);
        self::assertSame(575_467, $result->allocationFor('claim-b')?->totalMinorUnits);

        $reverse = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-b',
                ClaimCategory::NonPriority,
                2_000_000,
                orderIssuedOn: '2021-12-31',
            ),
            $this->statutoryClaim(
                'claim-a',
                ClaimCategory::NonPriority,
                1_000_000,
                orderIssuedOn: '2021-12-31',
            ),
        ]);
        self::assertSame($result->toCanonicalJson(), $reverse->toCanonicalJson());
    }

    public function testCurrentMaintenancePrecedesArrearsAndOtherPriorityInSecondPool(): void
    {
        $result = $this->calculate(3_000_000, [
            $this->statutoryClaim('other', ClaimCategory::OtherPriority, 300_000, '2026-01-01'),
            $this->statutoryClaim('arrears', ClaimCategory::MaintenanceArrears, 300_000, '2026-01-02', 300_000),
            $this->statutoryClaim('current', ClaimCategory::CurrentMaintenance, 300_000, '2026-01-03', 300_000),
        ]);

        self::assertSame(300_000, $result->allocationFor('current')?->secondPoolMinorUnits);
        self::assertSame(229_900, $result->allocationFor('arrears')?->secondPoolMinorUnits);
        self::assertSame(0, $result->allocationFor('other')?->secondPoolMinorUnits);
    }

    public function testEmployerFeeIsTakenOnceFromFirstThirdForPost2021Order(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
        ]);

        self::assertSame(5_000, $result->employerFlatFeeMinorUnits);
        self::assertSame(858_200, $result->allocationFor('claim-1')?->firstPoolMinorUnits);
        self::assertSame(863_200, $result->totalWithheldMinorUnits);

        $oldOrder = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-old',
                ClaimCategory::NonPriority,
                10_000_000,
                orderIssuedOn: '2021-12-31',
            ),
        ]);
        self::assertSame(0, $oldOrder->employerFlatFeeMinorUnits);
    }

    public function testEmployerFeeConvergesWhenOnlyOneCrownCanBeWithheld(): void
    {
        $result = $this->calculate(1_410_500, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
        ]);

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(100, $result->thirdMinorUnits);
        self::assertSame(100, $result->employerFlatFeeMinorUnits);
        self::assertSame(100, $result->totalWithheldMinorUnits);
        self::assertSame(1_410_400, $result->employeePaymentMinorUnits);
    }

    public function testApprovedInsolvencyUsesPriorityCapacityAndSingleAdministratorAllocation(): void
    {
        $result = $this->calculate(
            4_000_000,
            [],
            insolvency: new InsolvencyInstruction(
                InsolvencyMode::ApprovedStandard,
                decisionVerified: true,
                recipientVerified: true,
                paymentInstructionId: 101,
                paymentInstructionHash: str_repeat('a', 64),
                employmentId: 202,
            ),
        );

        self::assertTrue($result->insolvencyApplied);
        self::assertSame(1_726_400, $result->totalWithheldMinorUnits);
        self::assertSame(1_726_400, $result->allocationFor('insolvency-administrator')?->totalMinorUnits);
    }

    public function testApprovedInsolvencyWithoutImmutablePaymentInstructionFailsClosed(): void
    {
        $result = $this->calculate(
            4_000_000,
            [],
            insolvency: new InsolvencyInstruction(
                InsolvencyMode::ApprovedStandard,
                decisionVerified: true,
                recipientVerified: true,
            ),
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains(
            'insolvency_payment_instruction_missing',
            $result->issues,
        );
        self::assertSame([], $result->allocations);
    }

    public function testIncompleteEvidenceFailsClosedWithoutAnyAllocation(): void
    {
        $claim = new DeductionClaim(
            'incomplete',
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            1_000_000,
            null,
            legalTitleVerified: false,
            orderOrNoticeDelivered: false,
            orderIssuedOn: null,
            priorityClassificationVerified: false,
        );

        $result = $this->calculate(4_000_000, [$claim]);

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertSame(0, $result->totalWithheldMinorUnits);
        self::assertSame([], $result->allocations);
        self::assertContains('claim:incomplete:legal_title_not_verified', $result->issues);
        self::assertContains('claim:incomplete:delivery_date_missing', $result->issues);
    }

    public function testVoluntaryAgreementUsesFirstPoolButCannotClaimPriority(): void
    {
        $valid = new DeductionClaim(
            'agreement',
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::NonPriority,
            100_000,
            '2026-01-01',
            legalTitleVerified: false,
            orderOrNoticeDelivered: false,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
        );
        $result = $this->calculate(4_000_000, [$valid]);
        self::assertSame(100_000, $result->allocationFor('agreement')?->firstPoolMinorUnits);

        $invalid = new DeductionClaim(
            'invalid-agreement',
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::OtherPriority,
            100_000,
            '2026-01-01',
            legalTitleVerified: false,
            orderOrNoticeDelivered: false,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
        );
        $manual = $this->calculate(4_000_000, [$invalid]);
        self::assertSame(GarnishmentStatus::ManualReview, $manual->status);
        self::assertContains(
            'claim:invalid-agreement:voluntary_agreement_cannot_be_priority',
            $manual->issues,
        );
    }

    public function testMissingMultiplePayerDecisionFailsClosed(): void
    {
        $input = $this->input(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000),
        ], hasMultiplePayers: true);

        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($input);

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('multiple_payers_protected_amount_decision_missing', $result->issues);
    }

    public function testIncomeResolverAccumulatesAttachableItemsAndExcludesTravelReimbursement(): void
    {
        $resolver = new GarnishableIncomeResolver();
        $resolved = $resolver->resolve([
            new GarnishableIncomeItem('wage', GarnishableIncomeKind::Wage, 3_000_000, 'payer-main'),
            new GarnishableIncomeItem('pension', GarnishableIncomeKind::Pension, 500_000, 'payer-main'),
            new GarnishableIncomeItem('travel', GarnishableIncomeKind::TravelReimbursement, 200_000, 'payer-main'),
        ], evidenceComplete: true);

        self::assertSame(GarnishmentStatus::Supported, $resolved->status);
        self::assertSame(3_500_000, $resolved->garnishableMinorUnits);
        self::assertSame(200_000, $resolved->excludedMinorUnits);
    }

    public function testSeveranceFailsClosedUntilItIsSplitByStatutoryMultiple(): void
    {
        $resolved = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('severance', GarnishableIncomeKind::Severance, 6_000_000, 'payer-main'),
        ], evidenceComplete: true);

        self::assertSame(GarnishmentStatus::ManualReview, $resolved->status);
        self::assertContains(
            'income:severance:severance_period_split_required',
            $resolved->issues,
        );
        self::assertSame(0, $resolved->garnishableMinorUnits);
    }

    public function testIncomeEvidenceDefaultsToIncomplete(): void
    {
        $resolved = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('known-wage', GarnishableIncomeKind::Wage, 100_000, 'payer-main'),
        ]);

        self::assertSame(GarnishmentStatus::ManualReview, $resolved->status);
        self::assertContains('income_register_evidence_incomplete', $resolved->issues);
    }

    public function testIncomeFromDifferentPayersFailsClosed(): void
    {
        $resolved = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem(
                'wage',
                GarnishableIncomeKind::Wage,
                3_000_000,
                payerId: 'employer',
            ),
            new GarnishableIncomeItem(
                'pension',
                GarnishableIncomeKind::Pension,
                500_000,
                payerId: 'pension-office',
            ),
        ], evidenceComplete: true);

        self::assertSame(GarnishmentStatus::ManualReview, $resolved->status);
        self::assertContains(
            'multiple_income_payers_require_separate_calculation',
            $resolved->issues,
        );
        self::assertSame(0, $resolved->garnishableMinorUnits);
    }

    public function testDueMonetaryClaimDefaultsToUnverified(): void
    {
        $claim = new DeductionClaim(
            'claim-default-evidence',
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            1_000_000,
            '2026-01-01',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2022-01-01',
            priorityClassificationVerified: true,
            enforcementOrderId: 'order-default-evidence',
        );

        $result = $this->calculate(4_000_000, [$claim]);

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains(
            'claim:claim-default-evidence:due_monetary_claim_not_verified',
            $result->issues,
        );
    }

    public function testUnknownIncomeKindFailsClosed(): void
    {
        $resolved = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('unknown', GarnishableIncomeKind::Unknown, 100_000, 'payer-main'),
        ], evidenceComplete: true);

        self::assertSame(GarnishmentStatus::ManualReview, $resolved->status);
        self::assertSame(0, $resolved->garnishableMinorUnits);
    }

    public function testIncompleteIncomeRegisterFailsClosed(): void
    {
        $resolved = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('known-wage', GarnishableIncomeKind::Wage, 100_000, 'payer-main'),
        ], evidenceComplete: false);

        self::assertSame(GarnishmentStatus::ManualReview, $resolved->status);
        self::assertSame(0, $resolved->garnishableMinorUnits);
        self::assertContains('income_register_evidence_incomplete', $resolved->issues);
    }

    public function testFourClaimRowsFromOneOrderDoNotTriggerFourEnforcementRule(): void
    {
        $claims = [];
        foreach (range(1, 4) as $number) {
            $claims[] = $this->statutoryClaim(
                "claim-{$number}",
                ClaimCategory::NonPriority,
                1_000_000,
                enforcementOrderId: 'one-order',
            );
        }

        $result = $this->calculate(4_000_000, $claims);

        self::assertFalse($result->fourEnforcementRuleApplied);
        self::assertSame(863_200, $result->totalWithheldMinorUnits);
    }

    public function testUnknownPensionEvidenceWithFourOrdersFailsClosed(): void
    {
        $claims = [];
        foreach (range(1, 4) as $number) {
            $claims[] = $this->statutoryClaim(
                "claim-{$number}",
                ClaimCategory::NonPriority,
                1_000_000,
            );
        }

        $result = $this->calculate(
            1_700_000,
            $claims,
            pensionEvidence: PensionEvidence::Unknown,
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('four_enforcement_pension_exception_evidence_unknown', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    public function testVerifiedMultiplePayerDecisionOverridesProtectedAmount(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            hasMultiplePayers: true,
            protectedAmountOverrideMinorUnits: 1_000_000,
        ));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(1_000_000, $result->protectedAmountMinorUnits);
        self::assertSame(1_000_000, $result->thirdMinorUnits);
        self::assertSame(1_000_000, $result->totalWithheldMinorUnits);
        self::assertTrue($result->roundingTrace[0]['court_decision_override']);
    }

    /**
     * Osoba bez jediné aktivní pohledávky a bez jakéhokoli uplatněného nároku:
     * dokládat není co, takže nesmí vzniknout issue. Tohle je ten scénář, kvůli
     * kterému dřív celý mzdový běh skončil na nepřebitelném blokátoru
     * `enforcement_manual_review` — a to u KAŽDÉ osoby a KAŽDÝ měsíc.
     */
    public function testPersonWithoutClaimsNeedsNoMonthlyEvidence(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            $this->input(
                4_000_000,
                [],
                claimRegisterEvidenceComplete: false,
                dependantsEvidenceComplete: false,
                spouseEvidenceComplete: false,
            ),
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
        self::assertSame(
            [
                'claim_register' => 'not_applicable',
                'dependants' => 'not_applicable',
                'spouse' => 'not_applicable',
            ],
            $result->evidenceSource?->toCanonicalArray(),
        );
    }

    /**
     * Uplatněný nárok na vyživovanou osobu ZVEDÁ nezabavitelnou částku, a ta se
     * počítá i v měsíci bez exekuce — odvozuje se z ní strop dobrovolné dohody
     * o srážkách (§ 148 odst. 2 zákoníku práce). Běh proto neblokuje, ale
     * kapacita dohod je nula, dokud nárok nikdo nedoloží.
     */
    public function testUnattestedAllowanceWithoutClaimsClosesVoluntaryCapacity(): void
    {
        $calculator = new GarnishmentCalculator(CzechPayrollRulesets2026::provider());
        $result = $calculator->calculate($this->input(
            4_000_000,
            [],
            eligibleDependants: 2,
            eligibleSpouse: true,
            dependantsEvidenceComplete: false,
            spouseEvidenceComplete: false,
        ));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame([], $result->issues);
        self::assertSame(
            [
                'claim_register' => 'declared',
                'dependants' => 'nothing_withheld',
                'spouse' => 'nothing_withheld',
            ],
            $result->evidenceSource?->toCanonicalArray(),
        );
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));

        $attested = $calculator->calculate($this->input(
            4_000_000,
            [],
            eligibleDependants: 2,
            eligibleSpouse: true,
        ));
        self::assertSame('declared', $attested->evidenceSource?->dependants->value);
        self::assertGreaterThan(0, $calculator->voluntaryDeductionCapacity($attested));
    }

    /**
     * Jakmile je co srážet, doklad se vyžaduje dál — nedoložený nárok
     * na vyživovanou osobu u osoby s exekucí zůstává ručním posouzením.
     */
    public function testUnattestedAllowanceStillBlocksWhenAClaimIsActive(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            $this->input(
                4_000_000,
                [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000)],
                eligibleDependants: 1,
                dependantsEvidenceComplete: false,
            ),
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('dependants_evidence_incomplete', $result->issues);
        self::assertSame('missing', $result->evidenceSource?->dependants->value);
    }

    public function testIncompleteClaimRegisterFailsClosedEvenWhenKnownClaimsAreValid(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000)],
            claimRegisterEvidenceComplete: false,
        ));

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('claim_register_evidence_incomplete', $result->issues);
    }

    public function testMaintenanceArrearsWithoutCurrentWeightFailsClosed(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('arrears', ClaimCategory::MaintenanceArrears, 1_000_000),
        ]);

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('claim:arrears:maintenance_weight_missing', $result->issues);
    }

    public function testEarlierDeliveryDateConsumesFirstPoolBeforeLaterClaim(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'later',
                ClaimCategory::NonPriority,
                1_000_000,
                '2026-01-02',
                orderIssuedOn: '2021-12-31',
            ),
            $this->statutoryClaim(
                'earlier',
                ClaimCategory::NonPriority,
                500_000,
                '2026-01-01',
                orderIssuedOn: '2021-12-31',
            ),
        ]);

        self::assertSame(500_000, $result->allocationFor('earlier')?->firstPoolMinorUnits);
        self::assertSame(363_200, $result->allocationFor('later')?->firstPoolMinorUnits);
    }

    public function testNewSnapshotReevaluatesAllocationsAfterOlderOrderArrives(): void
    {
        $middle = $this->statutoryClaim(
            'middle',
            ClaimCategory::NonPriority,
            500_000,
            '2026-02-10',
            orderIssuedOn: '2021-12-31',
        );
        $latest = $this->statutoryClaim(
            'latest',
            ClaimCategory::NonPriority,
            500_000,
            '2026-03-10',
            orderIssuedOn: '2021-12-31',
        );

        $firstSnapshot = $this->calculate(4_000_000, [$middle, $latest]);
        self::assertSame(500_000, $firstSnapshot->allocationFor('middle')?->firstPoolMinorUnits);
        self::assertSame(363_200, $firstSnapshot->allocationFor('latest')?->firstPoolMinorUnits);

        $secondSnapshot = $this->calculate(4_000_000, [
            $middle,
            $latest,
            $this->statutoryClaim(
                'oldest',
                ClaimCategory::NonPriority,
                500_000,
                '2026-01-10',
                orderIssuedOn: '2021-12-31',
            ),
        ]);
        self::assertSame(500_000, $secondSnapshot->allocationFor('oldest')?->firstPoolMinorUnits);
        self::assertSame(363_200, $secondSnapshot->allocationFor('middle')?->firstPoolMinorUnits);
        self::assertNull($secondSnapshot->allocationFor('latest'));
    }

    public function testUnsupportedInsolvencyInstructionFailsClosed(): void
    {
        $result = $this->calculate(
            4_000_000,
            [],
            insolvency: new InsolvencyInstruction(
                InsolvencyMode::CourtDeterminedAmount,
                decisionVerified: true,
                recipientVerified: true,
                courtDeterminedAmountMinorUnits: 1_000_000,
            ),
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('court_determined_insolvency_amount_requires_manual_review', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    public function testRulesetSnapshotIsStableAndUsesOnlyOfficialSources(): void
    {
        $policy = EnforcementDeductionPolicy2026::shipped();

        self::assertSame(
            CzechPayrollRulesets2026::ENFORCEMENT_DEDUCTIONS_HASH,
            $policy->ruleset->contentHash,
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $policy->rulesetHash());
        self::assertSame(
            $policy->money('protected_amount.calculation_base.monthly'),
            $policy->money('life_minimum.monthly')
                + $policy->money('normative_rent.monthly')
                + $policy->money('energy_flat.monthly'),
        );
        self::assertSame(
            $policy->money('protected_amount.debtor_base.monthly'),
            intdiv(
                $policy->money('protected_amount.calculation_base.monthly')
                    * $policy->integer('debtor_share.numerator'),
                $policy->integer('debtor_share.denominator'),
            ),
        );
        self::assertSame(
            $policy->money('fully_attachable.threshold.monthly'),
            intdiv(
                $policy->money('protected_amount.calculation_base.monthly')
                    * $policy->integer('fully_attachable.factor_numerator'),
                $policy->integer('fully_attachable.factor_denominator'),
            ),
        );
        foreach ($policy->ruleset->sources as $source) {
            self::assertContains(parse_url($source->url, PHP_URL_HOST), [
                'exekuce.justice.cz',
                'insolvence.justice.cz',
                'ppropo.mpsv.cz',
                'www.e-sbirka.cz',
            ]);
        }
    }

    public function testFourEnforcementPensionExceptionUsesStrictThreshold(): void
    {
        $claims = [];
        foreach (range(1, 4) as $number) {
            $claims[] = $this->statutoryClaim(
                "claim-{$number}",
                ClaimCategory::NonPriority,
                1_000_000,
            );
        }

        $atLimit = $this->calculate(
            1_736_900,
            $claims,
            pensionEvidence: PensionEvidence::Verified,
        );
        self::assertSame(108_900, $atLimit->thirdMinorUnits);
        self::assertTrue($atLimit->fourEnforcementRuleApplied);
        self::assertSame(217_800, $atLimit->totalWithheldMinorUnits);

        $belowLimit = $this->calculate(
            1_736_899,
            $claims,
            pensionEvidence: PensionEvidence::Verified,
        );
        self::assertSame(108_800, $belowLimit->thirdMinorUnits);
        self::assertFalse($belowLimit->fourEnforcementRuleApplied);
        self::assertSame(108_800, $belowLimit->totalWithheldMinorUnits);
    }

    public function testUnverifiedMultiplePayerDecisionFailsClosedEvenWithAmount(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000)],
            hasMultiplePayers: true,
            protectedAmountOverrideMinorUnits: 1_000_000,
            protectedAmountOverrideVerified: false,
        ));

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains(
            'multiple_payers_protected_amount_decision_not_verified',
            $result->issues,
        );
    }

    public function testOlderPayrollPeriodPaidInRulesetYearIsSupported(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000)],
            period: '2025-12',
            paymentDate: '2026-01-15',
        ));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
    }

    public function testRulesetUsesPaymentDateInsteadOfPayrollPeriod(): void
    {
        $result = (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 1_000_000)],
            period: '2026-12',
            paymentDate: '2027-01-15',
        ));

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('payment_date_outside_ruleset_2026', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    public function testEverySupportedResultBalancesToGarnishableIncome(): void
    {
        foreach ([0, 1_410_100, 1_410_200, 1_700_000, 4_000_000, 6_000_000] as $income) {
            foreach ([ClaimCategory::NonPriority, ClaimCategory::OtherPriority] as $category) {
                $result = $this->calculate($income, [
                    $this->statutoryClaim('claim-1', $category, 10_000_000),
                ]);
                $recipientTotal = $result->employerFlatFeeMinorUnits;
                foreach ($result->allocations as $allocation) {
                    $recipientTotal += $allocation->totalMinorUnits;
                    self::assertLessThanOrEqual(10_000_000, $allocation->totalMinorUnits);
                }

                self::assertSame(GarnishmentStatus::Supported, $result->status);
                self::assertSame(
                    $income,
                    $result->employeePaymentMinorUnits + $recipientTotal,
                );
                self::assertLessThanOrEqual($income, $result->totalWithheldMinorUnits);
            }
        }
    }

    public function testBackPayForDifferentMonthsIsCalculatedSeparately(): void
    {
        $calculator = new GarnishmentBatchCalculator(
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
        );
        $results = $calculator->calculate([
            $this->input(2_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
            ], period: '2026-01'),
            $this->input(2_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
            ], period: '2026-02'),
        ]);

        self::assertSame(['2026-01', '2026-02'], array_keys($results));
        self::assertSame(196_600, $results['2026-01']->totalWithheldMinorUnits);
        self::assertSame(196_600, $results['2026-02']->totalWithheldMinorUnits);
    }

    /** @param list<DeductionClaim> $claims */
    private function calculate(
        int $netMinorUnits,
        array $claims,
        int $eligibleDependants = 0,
        bool $eligibleSpouse = false,
        PensionEvidence $pensionEvidence = PensionEvidence::None,
        ?InsolvencyInstruction $insolvency = null,
        SpousePensionEvidence $spousePensionEvidence =
            SpousePensionEvidence::NotDocumented,
    ): \MyInvoice\Service\Payroll\Garnishment\GarnishmentResult {
        return (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate(
            $this->input(
                $netMinorUnits,
                $claims,
                eligibleDependants: $eligibleDependants,
                eligibleSpouse: $eligibleSpouse,
                pensionEvidence: $pensionEvidence,
                insolvency: $insolvency,
                spousePensionEvidence: $spousePensionEvidence,
            ),
        );
    }

    /**
     * @param list<DeductionClaim> $claims
     */
    private function input(
        int $netMinorUnits,
        array $claims,
        int $eligibleDependants = 0,
        bool $eligibleSpouse = false,
        PensionEvidence $pensionEvidence = PensionEvidence::None,
        ?InsolvencyInstruction $insolvency = null,
        SpousePensionEvidence $spousePensionEvidence =
            SpousePensionEvidence::NotDocumented,
        bool $hasMultiplePayers = false,
        ?int $protectedAmountOverrideMinorUnits = null,
        string $period = '2026-06',
        string $paymentDate = '2026-07-15',
        bool $claimRegisterEvidenceComplete = true,
        ?bool $protectedAmountOverrideVerified = null,
        bool $dependantsEvidenceComplete = true,
        bool $spouseEvidenceComplete = true,
    ): GarnishmentInput {
        $income = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('net-wage', GarnishableIncomeKind::Wage, $netMinorUnits, 'payer-main'),
        ], evidenceComplete: true);

        return new GarnishmentInput(
            $period,
            $paymentDate,
            $income,
            $claims,
            $eligibleDependants,
            $dependantsEvidenceComplete,
            $eligibleSpouse,
            $spouseEvidenceComplete,
            $pensionEvidence,
            $hasMultiplePayers,
            $protectedAmountOverrideMinorUnits,
            $insolvency ?? InsolvencyInstruction::none(),
            $protectedAmountOverrideVerified
                ?? ($hasMultiplePayers && $protectedAmountOverrideMinorUnits !== null),
            $claimRegisterEvidenceComplete,
            $spousePensionEvidence,
        );
    }

    private function statutoryClaim(
        string $id,
        ClaimCategory $category,
        int $outstandingMinorUnits,
        string $priorityDate = '2026-01-01',
        ?int $maintenanceWeightMinorUnits = null,
        string $orderIssuedOn = '2022-01-01',
        ?string $enforcementOrderId = null,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::Statutory,
            $category,
            $outstandingMinorUnits,
            $priorityDate,
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: $orderIssuedOn,
            priorityClassificationVerified: true,
            maintenanceWeightMinorUnits: $maintenanceWeightMinorUnits,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: $enforcementOrderId ?? $id,
        );
    }
}
