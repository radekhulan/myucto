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
                '2021-12-31',
            ),
            $this->statutoryClaim(
                'claim-b',
                ClaimCategory::NonPriority,
                2_000_000,
                '2021-12-31',
            ),
        ]);

        self::assertSame(287_733, $result->allocationFor('claim-a')?->totalMinorUnits);
        self::assertSame(575_467, $result->allocationFor('claim-b')?->totalMinorUnits);

        $reverse = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-b',
                ClaimCategory::NonPriority,
                2_000_000,
                '2021-12-31',
            ),
            $this->statutoryClaim(
                'claim-a',
                ClaimCategory::NonPriority,
                1_000_000,
                '2021-12-31',
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

    /**
     * § 280 odst. 2 o. s. ř. uspokojuje z druhé třetiny bez zřetele na pořadí
     * nejprve výživné, poté úplatu za postupované pohledávky výživného, poté
     * postoupené výživné, poté náhradní výživné a teprve pak ostatní přednostní
     * pohledávky. Do 8/2026 obě postupované skupiny číselník neznal a spadly do
     * `other_priority`, tedy až za náhradní výživné (nález E-07).
     */
    public function testAssignedMaintenancePrecedesSubstituteMaintenanceInSecondPool(): void
    {
        $result = $this->calculate(4_200_000, [
            $this->statutoryClaim('other', ClaimCategory::OtherPriority, 300_000, '2026-01-01'),
            $this->statutoryClaim(
                'substitute',
                ClaimCategory::SubstituteMaintenance,
                300_000,
                '2026-01-02',
                300_000,
            ),
            $this->statutoryClaim(
                'assigned',
                ClaimCategory::AssignedMaintenance,
                300_000,
                '2026-01-03',
                300_000,
            ),
            $this->statutoryClaim(
                'consideration',
                ClaimCategory::AssignedMaintenanceConsideration,
                300_000,
                '2026-01-04',
                300_000,
            ),
            $this->statutoryClaim(
                'current',
                ClaimCategory::CurrentMaintenance,
                300_000,
                '2026-01-05',
                300_000,
            ),
        ]);

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        // Druhá třetina je 929 900: první tři skupiny se uspokojí celé
        // a na náhradní výživné zbyde jen zbytek. Ostatní přednostní pohledávka
        // je z druhé třetiny až za nimi a nedostane nic.
        self::assertSame(300_000, $result->allocationFor('current')?->secondPoolMinorUnits);
        self::assertSame(300_000, $result->allocationFor('consideration')?->secondPoolMinorUnits);
        self::assertSame(300_000, $result->allocationFor('assigned')?->secondPoolMinorUnits);
        self::assertSame(
            $result->thirdMinorUnits - 900_000,
            $result->allocationFor('substitute')?->secondPoolMinorUnits,
        );
        self::assertSame(0, $result->allocationFor('other')?->secondPoolMinorUnits ?? 0);
    }

    /**
     * O nároku na paušál rozhoduje den DORUČENÍ příkazu plátci mzdy, ne den
     * jeho vydání (§ 282 odst. 1 a 3 o. s. ř.) — nález E-11. Příkaz vydaný
     * ještě v roce 2021, ale doručený až po 1. 1. 2022, tedy nárok zakládá.
     */
    public function testEmployerFeeFollowsTheDeliveryDateNotTheIssueDate(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
        ]);

        self::assertSame(5_000, $result->employerFlatFeeMinorUnits);
        self::assertSame(858_200, $result->allocationFor('claim-1')?->firstPoolMinorUnits);
        self::assertSame(863_200, $result->totalWithheldMinorUnits);

        $issuedBeforeButDeliveredAfter = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-late-delivery',
                ClaimCategory::NonPriority,
                10_000_000,
                '2022-01-03',
                orderIssuedOn: '2021-12-31',
            ),
        ]);
        self::assertSame(5_000, $issuedBeforeButDeliveredAfter->employerFlatFeeMinorUnits);

        $oldOrder = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'claim-old',
                ClaimCategory::NonPriority,
                10_000_000,
                '2021-12-31',
                orderIssuedOn: '2021-12-31',
            ),
        ]);
        self::assertSame(0, $oldOrder->employerFlatFeeMinorUnits);
        self::assertSame(863_200, $oldOrder->totalWithheldMinorUnits);
        self::assertSame(863_200, $oldOrder->allocationFor('claim-old')?->firstPoolMinorUnits);
    }

    /**
     * § 270 odst. 3 o. s. ř.: paušál si plátce mzdy odečte ZE SRAŽENÝCH ČÁSTEK
     * mířících oprávněnému. Na doběhu exekuce se proto zaměstnanci srazí přesně
     * zbývající dluh — dřív se mu k němu paušál přičetl (nález E-02).
     */
    public function testEmployerFeeIsPaidByCreditorNotByDebtorOnTheFinalInstalment(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 30_000),
        ]);

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        // Kapacita první třetiny je 863 200; sráží se jen zbývající dluh 300 Kč.
        self::assertSame(30_000, $result->totalWithheldMinorUnits);
        self::assertSame(5_000, $result->employerFlatFeeMinorUnits);
        self::assertSame(25_000, $result->allocationFor('claim-1')?->totalMinorUnits);
        self::assertSame(3_970_000, $result->employeePaymentMinorUnits);
    }

    /**
     * Zaokrouhlená třetina sražené částky je strop náhrady (§ 3 odst. 3 nař.
     * vlády č. 595/2006 Sb.). Při dluhu 100 Kč tedy plátci mzdy nenáleží
     * 50 Kč, ale jen 34 Kč — a sráží se pořád jen těch 100 Kč.
     */
    public function testEmployerFeeIsCappedByOneThirdOfTheAmountActuallyWithheld(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000),
        ]);

        self::assertSame(10_000, $result->totalWithheldMinorUnits);
        self::assertSame(3_400, $result->employerFlatFeeMinorUnits);
        self::assertSame(6_600, $result->allocationFor('claim-1')?->totalMinorUnits);
    }

    /**
     * „Provádí-li plátce mzdy zároveň srážky k vydobytí několika pohledávek
     * vůči témuž povinnému, náleží mu náhrada nákladů pouze jednou."
     * Strop je 50 Kč na povinného, ne na exekuci.
     */
    public function testEmployerFeeIsDueOncePerDebtorRegardlessOfClaimCount(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 400_000, '2026-01-01'),
            $this->statutoryClaim('claim-2', ClaimCategory::NonPriority, 400_000, '2026-02-01'),
            $this->statutoryClaim('claim-3', ClaimCategory::NonPriority, 400_000, '2026-03-01'),
        ]);

        // Tři exekuční příkazy, náhrada pořád jen jedna — strop je 50 Kč na
        // zaměstnance, ne na exekuci.
        self::assertSame(5_000, $result->employerFlatFeeMinorUnits);
        self::assertSame(863_200, $result->totalWithheldMinorUnits);

        // Paušál se uspokojuje před ostatními pohledávkami z první třetiny,
        // takže o něj přijde ten poslední v pořadí, ne ten první.
        self::assertSame(400_000, $result->allocationFor('claim-1')?->totalMinorUnits);
        self::assertSame(400_000, $result->allocationFor('claim-2')?->totalMinorUnits);
        self::assertSame(58_200, $result->allocationFor('claim-3')?->totalMinorUnits);

        $allocated = 0;
        foreach ($result->allocations as $allocation) {
            $allocated += $allocation->totalMinorUnits;
        }
        self::assertSame(
            $result->totalWithheldMinorUnits,
            $allocated + $result->employerFlatFeeMinorUnits,
        );
    }

    /**
     * Měsíc, kdy celou srážku spolkne výživné z druhé třetiny a na první
     * třetinu vůbec nedojde. Paušál za takový měsíc NENÁLEŽÍ.
     *
     * § 279 odst. 1 věta čtvrtá o. s. ř. ho uspokojuje „před všemi ostatními
     * pohledávkami z první třetiny zbytku čisté mzdy" a § 280 odst. 2 určuje
     * taxativně, co se hradí z druhé třetiny — náhrada nákladů plátce mezi tím
     * není. Ukrojit ji z výživného by znamenalo platit náklady plátce z peněz,
     * na které má přednost oprávněný, a otevřelo by to žalobu podle § 292.
     *
     * Zaměstnance to nestojí nic navíc: sráží se pořád stejných 2 000 Kč,
     * jen z nich nic nezůstane plátci.
     */
    public function testEmployerFeeLapsesWhenTheFirstThirdStaysUnused(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'maintenance',
                ClaimCategory::CurrentMaintenance,
                200_000,
                maintenanceWeightMinorUnits: 200_000,
            ),
        ]);

        self::assertSame(200_000, $result->totalWithheldMinorUnits);
        self::assertSame(0, $result->employerFlatFeeMinorUnits);
        self::assertSame(200_000, $result->allocationFor('maintenance')?->secondPoolMinorUnits);
        self::assertSame(0, $result->allocationFor('maintenance')?->firstPoolMinorUnits);
        self::assertSame(3_800_000, $result->employeePaymentMinorUnits);
    }

    /**
     * Doplněk k předchozímu: jakmile na první třetinu dojde, paušál se z ní
     * bere PŘED výživným — tam přednost má. Nárok tedy nepropadá vždycky,
     * když je ve hře výživné, jen když je první třetina prázdná.
     */
    public function testEmployerFeeOutranksMaintenanceInsideTheFirstThird(): void
    {
        $result = $this->calculate(4_000_000, [
            $this->statutoryClaim(
                'maintenance',
                ClaimCategory::CurrentMaintenance,
                2_000_000,
                maintenanceWeightMinorUnits: 2_000_000,
            ),
        ]);

        $allocation = $result->allocationFor('maintenance');
        self::assertNotNull($allocation);
        // Druhá třetina je vyčerpaná, výživné sahá i do první — a tam ho
        // paušál předchází.
        self::assertGreaterThan(0, $allocation->firstPoolMinorUnits);
        self::assertSame(5_000, $result->employerFlatFeeMinorUnits);
        self::assertSame(
            $result->totalWithheldMinorUnits,
            $allocation->totalMinorUnits + $result->employerFlatFeeMinorUnits,
        );
    }

    /**
     * Jádro nálezu E-02 jako vlastnost, ne jako jeden příklad: srážka
     * s paušálem musí být na korunu stejná jako srážka bez něj. Paušál smí
     * měnit jen to, komu z ní co dojde.
     */
    public function testEmployerFeeNeverChangesWhatTheEmployeeLoses(): void
    {
        foreach ([1, 100, 3_400, 10_000, 30_000, 200_000, 863_100, 863_200, 5_000_000] as $debt) {
            foreach ([ClaimCategory::NonPriority, ClaimCategory::OtherPriority] as $category) {
                $withFee = $this->calculate(4_000_000, [
                    $this->statutoryClaim('claim-1', $category, $debt),
                ]);
                $withoutFee = $this->calculate(4_000_000, [
                    $this->statutoryClaim(
                        'claim-1',
                        $category,
                        $debt,
                        orderIssuedOn: '2021-12-31',
                    ),
                ]);

                self::assertSame(
                    $withoutFee->totalWithheldMinorUnits,
                    $withFee->totalWithheldMinorUnits,
                    "dluh {$debt}, kategorie {$category->value}",
                );
                self::assertSame(
                    $withoutFee->employeePaymentMinorUnits,
                    $withFee->employeePaymentMinorUnits,
                );
                self::assertLessThanOrEqual(
                    $withFee->totalWithheldMinorUnits,
                    $withFee->employerFlatFeeMinorUnits,
                );
                self::assertSame(
                    $withFee->totalWithheldMinorUnits - $withFee->employerFlatFeeMinorUnits,
                    $withFee->allocationFor('claim-1')?->totalMinorUnits ?? 0,
                );
            }
        }
    }

    public function testNoEmployerFeeInMonthWithoutAnyWithholding(): void
    {
        $result = $this->calculate(1_410_200, [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000),
        ]);

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertSame(0, $result->totalWithheldMinorUnits);
        self::assertSame(0, $result->employerFlatFeeMinorUnits);
        self::assertSame(1_410_200, $result->employeePaymentMinorUnits);
    }

    /**
     * Paušál je jen pro exekuční a soudem nařízené srážky, ne pro oddlužení —
     * insolvenční správce dostane celou zabavitelnou částku.
     */
    public function testApprovedInsolvencyDoesNotChargeTheEmployerFee(): void
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

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        self::assertTrue($result->insolvencyApplied);
        self::assertSame(0, $result->employerFlatFeeMinorUnits);
        self::assertSame(1_726_400, $result->totalWithheldMinorUnits);
        self::assertSame(
            1_726_400,
            $result->allocationFor('insolvency-administrator')?->totalMinorUnits,
        );
    }

    public function testEmployerFeeCannotExceedTheSingleCrownThatWasWithheld(): void
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
        // 363 200 minus 50 Kč paušálu (§ 270 odst. 3 o. s. ř.), který se
        // krájí od konce pořadí uspokojování.
        self::assertSame(358_200, $result->allocationFor('later')?->firstPoolMinorUnits);
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
        self::assertSame(358_200, $firstSnapshot->allocationFor('latest')?->firstPoolMinorUnits);

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
        self::assertSame(358_200, $secondSnapshot->allocationFor('middle')?->firstPoolMinorUnits);
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

    /**
     * § 276 o. s. ř.: srážky lze provádět jen do výše vymáhané pohledávky.
     * Doplatek rozpuštěný do tří měsíců proto NESMÍ téže pohledávce přidělit
     * třikrát celý zůstatek — zůstatky se mezi obdobími snižují (nález E-04).
     */
    public function testBatchCarriesClaimBalancesBetweenPeriods(): void
    {
        $calculator = new GarnishmentBatchCalculator(
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
        );
        $outstanding = 300_000;
        $results = $calculator->calculate([
            // Záměrně v obráceném pořadí — přenos se řídí obdobím, ne vstupem.
            $this->input(4_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, $outstanding),
            ], period: '2026-03'),
            $this->input(4_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, $outstanding),
            ], period: '2026-01'),
            $this->input(4_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, $outstanding),
            ], period: '2026-02'),
        ]);

        self::assertSame(['2026-01', '2026-02', '2026-03'], array_keys($results));

        // Kapacita první třetiny je 863 200 v KAŽDÉM období. Dřív dostala
        // pohledávka v každém z nich celý zůstatek 3 000 Kč, dohromady 9 000 Kč
        // na dluh 3 000 Kč. Teď se zůstatek přenáší, takže se v prvním období
        // srazí celý dluh a v dalších už jen to, co v něm ukrojil paušál.
        $paid = [];
        foreach ($results as $period => $result) {
            self::assertSame(GarnishmentStatus::Supported, $result->status);
            $paid[$period] = $result->allocationFor('claim-1')?->totalMinorUnits ?? 0;
        }

        self::assertSame($outstanding, $results['2026-01']->totalWithheldMinorUnits);
        self::assertSame(295_000, $paid['2026-01']);
        self::assertSame(5_000, $results['2026-02']->totalWithheldMinorUnits);
        self::assertSame(3_300, $paid['2026-02']);
        self::assertSame(1_700, $results['2026-03']->totalWithheldMinorUnits);
        self::assertSame(1_100, $paid['2026-03']);
        self::assertLessThan($outstanding, array_sum($paid));
    }

    /**
     * Přenos snižuje zůstatek o částku, která oprávněnému SKUTEČNĚ došla —
     * tedy po ukrojení paušální náhrady nákladů plátce mzdy (§ 270 odst. 3
     * o. s. ř.). Paušál dluh neumořuje, patří zaměstnavateli.
     */
    public function testBatchDoesNotCreditTheEmployerFeeAgainstTheDebt(): void
    {
        $calculator = new GarnishmentBatchCalculator(
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
        );
        $results = $calculator->calculate([
            $this->input(2_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 300_000),
            ], period: '2026-01'),
            $this->input(2_000_000, [
                $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 300_000),
            ], period: '2026-02'),
        ]);

        $first = $results['2026-01'];
        self::assertSame(5_000, $first->employerFlatFeeMinorUnits);
        self::assertSame(196_600, $first->totalWithheldMinorUnits);
        $paidToCreditor = $first->allocationFor('claim-1')?->totalMinorUnits ?? 0;
        self::assertSame(191_600, $paidToCreditor);

        // Do dalšího období se přenese dluh snížený jen o to, co došlo
        // oprávněnému — paušál si nechal zaměstnavatel a dluh neumořil.
        self::assertSame(
            300_000 - $paidToCreditor,
            $results['2026-02']->totalWithheldMinorUnits,
        );
    }

    /**
     * § 4 nař. vlády č. 595/2006 Sb. žádá hodnoty ve výši platné k 1. lednu
     * roku, do něhož připadá den výplaty. Sada s vnitroroční účinností tuhle
     * podmínku splnit nemůže, takže se z ní nepočítá — měsíc jde na ruční
     * posouzení místo tichého použití zakázaných hodnot (nález E-06).
     */
    public function testMidYearRulesetIsRefusedInsteadOfBeingUsed(): void
    {
        $shipped = EnforcementDeductionPolicy2026::shipped()->ruleset;
        $version = new \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion(
            $shipped->id,
            $shipped->version,
            $shipped->domain,
            '2026-01-01',
            '2026-06-30',
            $shipped->lifecycle,
            $shipped->capability,
            $shipped->sources,
            $shipped->parameters,
            $shipped->approval,
            $shipped->technicalReview,
        );

        $result = (new GarnishmentCalculator(
            new \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider([$version]),
        ))->calculate($this->input(
            4_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            paymentDate: '2026-08-15',
        ));

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertSame(0, $result->totalWithheldMinorUnits);
        self::assertContains(
            'enforcement_ruleset_not_effective_for_whole_year',
            $result->issues,
        );
    }

    /**
     * § 279 odst. 4 o. s. ř. mluví o NAŘÍZENÝCH výkonech rozhodnutí, ne
     * o pohledávkách se zbytkem. Měsíc, kdy na jednu z pěti exekucí zrovna nic
     * nezbývá, proto pravidlo čtyř exekucí nevypne (nález E-15).
     */
    public function testFourEnforcementRuleCountsOrderedExecutionsNotOutstandingOnes(): void
    {
        $claims = [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000, '2026-01-01'),
            $this->statutoryClaim('claim-2', ClaimCategory::NonPriority, 10_000_000, '2026-01-02'),
            $this->statutoryClaim('claim-3', ClaimCategory::NonPriority, 10_000_000, '2026-01-03'),
            // Nařízená a doručená exekuce, na kterou v tomhle měsíci nic nezbývá.
            $this->statutoryClaim('claim-4', ClaimCategory::NonPriority, 0, '2026-01-04'),
        ];

        $result = $this->calculate(4_000_000, $claims);

        self::assertTrue($result->fourEnforcementRuleApplied);
        self::assertSame(1_726_400, $result->totalWithheldMinorUnits);
    }

    /**
     * Zastavená (neaktivní) exekuce se do počtu podle § 279 odst. 4 nepočítá —
     * nařízená už není.
     */
    public function testStoppedEnforcementDoesNotCountTowardsTheFourEnforcementRule(): void
    {
        $claims = [
            $this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000, '2026-01-01'),
            $this->statutoryClaim('claim-2', ClaimCategory::NonPriority, 10_000_000, '2026-01-02'),
            $this->statutoryClaim('claim-3', ClaimCategory::NonPriority, 10_000_000, '2026-01-03'),
        ];
        $stopped = new DeductionClaim(
            'claim-stopped',
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            10_000_000,
            '2026-01-04',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2022-01-01',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            active: false,
            enforcementOrderId: 'claim-stopped',
        );

        $result = $this->calculate(4_000_000, [...$claims, $stopped]);

        self::assertFalse($result->fourEnforcementRuleApplied);
    }

    /**
     * § 281 o. s. ř. — přes dvě třetiny zbytku a plně zabavitelnou část se
     * srazit nesmí nic, ani vlastní chybou. Invariant hlídá výsledek sám,
     * protože vzniká na několika cestách (nález E-16).
     */
    public function testResultRefusesWithholdingAboveTheStatutoryCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \MyInvoice\Service\Payroll\Garnishment\GarnishmentResult(
            '2026-06',
            GarnishmentStatus::Supported,
            4_000_000,
            1_410_200,
            863_200,
            0,
            0,
            // 2 × 863 200 + 0 = 1 726 400 je strop; o korunu víc je § 281.
            1_726_500,
            4_000_000 - 1_726_500,
            true,
            false,
            [new \MyInvoice\Service\Payroll\Garnishment\GarnishmentAllocation(
                'claim-1',
                1_726_500,
                0,
            )],
            [],
            [],
            EnforcementDeductionPolicy2026::shipped()->rulesetId(),
            EnforcementDeductionPolicy2026::shipped()->rulesetHash(),
        );
    }

    /**
     * ZAFIXOVANÉ VÝKLADOVÉ ROZHODNUTÍ, nikoli běžný regresní test.
     *
     * § 1 nař. vlády č. 595/2006 Sb. skládá nezabavitelnou částku z částky na
     * povinného a čtvrtin na vyživované osoby; § 3 pak zaokrouhluje NAHORU
     * „základní částku, která nesmí být povinnému sražena z měsíční mzdy",
     * tedy až výsledný SOUČET. Kód to tak dělá — a rozchází se proto o 2 Kč
     * s tabulkami, které zaokrouhlují každou čtvrtinu zvlášť (nález E-10).
     *
     * Tři vyživované osoby: 14 101,50 + 3 × 3 525,375 = 24 677,625 → 24 678 Kč.
     * Zaokrouhlením po osobě by vyšlo 14 102 + 3 × 3 526 = 24 680 Kč.
     *
     * Reklamace na „chybějící dvě koruny" je proto očekávaná a odpověď na ni
     * je tenhle test, ne oprava výpočtu.
     */
    public function testProtectedAmountRoundsUpOnlyOnceFromTheSumNotPerPerson(): void
    {
        $result = $this->calculate(
            6_000_000,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
            eligibleDependants: 3,
        );

        self::assertSame(2_467_800, $result->protectedAmountMinorUnits);
        self::assertNotSame(2_468_000, $result->protectedAmountMinorUnits);
    }

    /**
     * ZAFIXOVANÉ VÝKLADOVÉ ROZHODNUTÍ (nález E-09): plně zabavitelný zbytek se
     * počítá z NEZAOKROUHLENÉHO zbytku čisté mzdy. Zdůvodnění je v komentáři
     * u výpočtu v {@see \MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator}.
     *
     * Zbytek 31 523 Kč (příjem 45 625 − nezabavitelná částka 14 102) je o 2 Kč
     * nad hranicí 31 521 Kč. Doslovný výklad § 279 odst. 3 by zbytek nejdřív
     * zaokrouhlil dolů na dělitelný třemi (31 521) a plně zabavitelná část by
     * byla nula; tady jsou to 2 Kč.
     */
    public function testFullyAttachableExcessIsMeasuredFromTheUnroundedRemainder(): void
    {
        $result = $this->calculate(
            4_562_500,
            [$this->statutoryClaim('claim-1', ClaimCategory::NonPriority, 10_000_000)],
        );

        self::assertSame(1_410_200, $result->protectedAmountMinorUnits);
        self::assertSame(200, $result->fullyAttachableExcessMinorUnits);
        self::assertSame(1_050_700, $result->thirdMinorUnits);
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
