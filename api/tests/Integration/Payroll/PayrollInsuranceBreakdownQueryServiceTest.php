<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerLiabilityResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationDecision;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthResult;
use MyInvoice\Service\Payroll\HealthInsurance\HealthRelationshipResult;
use MyInvoice\Service\Payroll\IncomeTax\AnnualTaxAccumulatorResult;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentIncomeTaxPolicy2026;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\RelationshipTaxResult;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceStepReconstructor;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollNetResult;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryResultPersister;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerCategoryResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationDecision;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthResult;
use MyInvoice\Service\Payroll\SocialInsurance\SocialRelationshipResult;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MZ-10-W07 / MZ-11-W07 — rozklad pojistného musí dát tutéž částku jako uložený
 * zákonný výsledek. Kdyby dal jinou, není to vysvětlení, ale druhý tichý výpočet,
 * a účetní by se o něj opřel při kontrole.
 */
#[Group('integration')]
final class PayrollInsuranceBreakdownQueryServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const SOCIAL_RULESET_ID = 'synthetic-social-2026';
    private const HEALTH_RULESET_ID = 'synthetic-health-2026';
    private const TAX_RULESET_ID = 'synthetic-tax-2026';
    private const SOCIAL_RULESET_HASH =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const HEALTH_RULESET_HASH =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TAX_RULESET_HASH =
        'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const SOCIAL_EMPLOYEE_RATE = '0.071';
    private const SOCIAL_EMPLOYER_RATE = '0.248';
    /** Sazby § 7 odst. 1 písm. a) až c) pro rok 2026. */
    private const EMPLOYER_RATES = [
        'ordinary' => '0.248',
        'rescue_and_company_fire_service' => '0.298',
        'risk_employment' => '0.278',
    ];
    private const HEALTH_RATE = '0.135';

    private Connection $db;
    private PayrollRulesetRegistry $rulesets;
    private string $healthRulesetId = self::HEALTH_RULESET_ID;
    private string $healthRulesetHash = self::HEALTH_RULESET_HASH;
    private PayrollRunStatutoryResultPersister $persister;
    private PayrollInsuranceBreakdownQueryService $service;
    private int $supplierId;
    /** @var list<int> */
    private array $employeeIds = [];
    /** @var array<int,int> */
    private array $employmentByEmployee = [];
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        foreach ([
            'payroll_statutory_results',
            'payroll_statutory_person_results',
            'payroll_statutory_relationship_results',
        ] as $table) {
            if (!$db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1255 neproběhla.');
            }
        }
        $this->db = $db;
        $repository = new PayrollStatutoryResultRepository($db);
        $this->persister = new PayrollRunStatutoryResultPersister($repository, $db);
        $this->rulesets = new PayrollRulesetRegistry(new PayrollRulesetRepository($db));
        $this->service = new PayrollInsuranceBreakdownQueryService(
            new PayrollRunRepository($db),
            $repository,
            new PayrollInsuranceStepReconstructor($this->rulesets),
        );

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstSupplierId($pdo);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        foreach ([0, 1] as $_index) {
            $employeeId = $this->createEmployee($pdo, $this->supplierId);
            $this->employeeIds[] = $employeeId;
            $this->employmentByEmployee[$employeeId] = $this->createEmployment(
                $pdo,
                $this->supplierId,
                $employeeId,
            );
        }
        $this->revisionId = $this->createRevisionGraph($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testBreakdownReconcilesWithTheStoredStatutoryResultToTheHaler(): void
    {
        $this->persistResults();
        $employeeId = $this->employeeIds[0];

        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $employeeId,
        );

        $social = $breakdown['social'];
        self::assertTrue($social['available']);
        self::assertSame('calculated', $social['status']);
        // 30 000 Kč × 7,1 % = 2 130 Kč, sazba i zaokrouhlení z uloženého kroku.
        self::assertSame(3_000_000, $social['assessment_base']['participating_minor']);
        self::assertSame(self::SOCIAL_EMPLOYEE_RATE, $social['employee']['contribution_step']['rate']['decimal']);
        self::assertSame(
            $social['employee']['before_discount_minor'] - $social['employee']['working_pensioner_discount_minor'],
            $social['employee']['contribution_minor'],
        );
        self::assertSame(213_000, $social['employee']['contribution_minor']);
        self::assertSame('company_month', $social['employer']['scope']);
        self::assertSame(self::SOCIAL_EMPLOYER_RATE, $social['employer']['contribution_step']['rate']['decimal']);

        $health = $breakdown['health'];
        self::assertTrue($health['available']);
        self::assertSame('persisted', $health['contribution']['rate_source']);
        self::assertSame(self::HEALTH_RATE, $health['contribution']['standard_step']['rate']['decimal']);
        self::assertSame(
            $health['contribution']['employee_standard_minor']
                + $health['contribution']['employer_standard_minor'],
            $health['contribution']['standard_minor'],
        );
        self::assertSame(
            $health['contribution']['employee_minor'] + $health['contribution']['employer_minor'],
            $health['contribution']['total_minor'],
        );
        self::assertSame(405_000, $health['contribution']['total_minor']);

        // Součet mezikroků musí dát přesně tu částku, kterou nese uložený výsledek.
        $stored = $this->storedPersonSnapshot('health_insurance', $employeeId);
        self::assertSame(
            $stored['employee_contribution_minor_units'],
            $health['contribution']['employee_minor'],
        );
        self::assertSame(
            $stored['employer_contribution_minor_units'],
            $health['contribution']['employer_minor'],
        );
        $storedSocial = $this->storedPersonSnapshot('social_insurance', $employeeId);
        self::assertSame(
            $storedSocial['employee_contribution_minor_units'],
            $social['employee']['contribution_minor'],
        );
    }

    public function testAnnualMaximumShowsUpInTheSocialBreakdown(): void
    {
        $this->persistResults(cappedBaseMinorUnits: 1_200_000, yearToDateMinorUnits: 217_753_400);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        $base = $breakdown['social']['assessment_base'];
        self::assertTrue($base['annual_maximum_applied']);
        self::assertSame(3_000_000, $base['participating_minor']);
        self::assertSame(1_200_000, $base['capped_minor']);
        self::assertSame(1_800_000, $base['annual_maximum_reduction_minor']);
        self::assertSame(217_753_400, $base['year_to_date_before_month_minor']);
        // Pojistné se počítá z omezeného základu, ne z původního.
        self::assertSame(
            1_200_000,
            $breakdown['social']['employee']['contribution_step']['input_minor_units'],
        );
    }

    public function testMinimumAssessmentBaseTopUpShowsUpInTheHealthBreakdown(): void
    {
        $this->persistResults(healthBaseMinorUnits: 1_000_000, healthMinimumMinorUnits: 2_130_000);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        $health = $breakdown['health'];
        self::assertTrue($health['minimum']['top_up_applied']);
        self::assertSame(2_130_000, $health['minimum']['effective_minor']);
        self::assertSame(1_130_000, $health['minimum']['top_up_base_minor']);
        self::assertSame('employee', $health['minimum']['top_up_responsibility']);
        $topUp = $health['contribution']['employee_top_up_minor']
            + $health['contribution']['employer_top_up_minor'];
        self::assertGreaterThan(0, $topUp);
        self::assertSame(
            $health['contribution']['standard_minor'] + $topUp,
            $health['contribution']['total_minor'],
        );
    }

    public function testHealthLiabilitySplitsAcrossInsurersAndMarksThePersonsOwn(): void
    {
        $this->persistResults();

        $first = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );
        $second = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[1],
        );

        self::assertCount(2, $first['health']['insurer_liabilities']);
        self::assertSame(
            ['111', '201'],
            array_column($first['health']['insurer_liabilities'], 'insurer_code'),
        );
        self::assertSame(
            [true, false],
            array_column($first['health']['insurer_liabilities'], 'is_person_insurer'),
        );
        self::assertSame(
            [false, true],
            array_column($second['health']['insurer_liabilities'], 'is_person_insurer'),
        );
        // Osoba patří v měsíci právě jedné pojišťovně — model víc neumožňuje.
        self::assertSame('111', $first['health']['insurer']['code']);
        self::assertSame('201', $second['health']['insurer']['code']);
        // Rozpad podle pojišťoven musí sedět na součet za měsíc.
        $total = array_sum(array_column($first['health']['insurer_liabilities'], 'total_minor'));
        self::assertSame(810_000, $total);
    }

    public function testRevisionWithoutStoredResultsSaysSoInsteadOfShowingNothing(): void
    {
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertFalse($breakdown['social']['available']);
        self::assertSame('result_set_missing', $breakdown['social']['unavailable_reason']);
        self::assertFalse($breakdown['health']['available']);
        self::assertSame('result_set_missing', $breakdown['health']['unavailable_reason']);
        // Žádné nuly vydávané za výpočet.
        self::assertArrayNotHasKey('contribution', $breakdown['health']);
        self::assertArrayNotHasKey('employee', $breakdown['social']);
    }

    public function testRevisionWithoutStoredHealthStepReportsTheRateAsNotRecorded(): void
    {
        $this->persistResults(recordHealthSteps: false);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertTrue($breakdown['health']['available']);
        self::assertSame('not_recorded', $breakdown['health']['contribution']['rate_source']);
        self::assertNull($breakdown['health']['contribution']['standard_step']);
        // Částky uložené jsou, jen se k nim nedá doložit sazba — a to se řekne.
        self::assertSame(405_000, $breakdown['health']['contribution']['total_minor']);
    }

    /**
     * Revize bez uložených kroků nesmí dopočet do minima ZATAJIT — nese ho
     * v částkách. Základ dopočtu se ale nedá vymyslet, takže zůstane null.
     */
    public function testTopUpIsStillReportedWhenTheRevisionDidNotStoreItsStep(): void
    {
        $this->persistResults(
            healthBaseMinorUnits: 1_000_000,
            healthMinimumMinorUnits: 2_130_000,
            recordHealthSteps: false,
        );
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertTrue($breakdown['health']['minimum']['top_up_applied']);
        self::assertNull($breakdown['health']['minimum']['top_up_base_minor']);
        self::assertGreaterThan(0, $breakdown['health']['contribution']['employee_top_up_minor']);
        self::assertSame('not_recorded', $breakdown['health']['contribution']['rate_source']);
    }

    /**
     * Osoba bez pojistného nemá krok právem — hlásit u ní „sazba se neuložila"
     * by byl planý poplach, po kterém účetní varování přestane číst.
     */
    public function testZeroContributionIsReportedAsNotApplicableNotAsMissingRate(): void
    {
        $this->persistResults(healthBaseMinorUnits: 0, recordHealthSteps: false);
        $breakdown = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        );

        self::assertSame('not_applicable', $breakdown['health']['contribution']['rate_source']);
        self::assertSame(0, $breakdown['health']['contribution']['total_minor']);
    }

    /**
     * MZ-11 — revize bez uloženého mezikroku se rozkladu dočká, ale JEN proti
     * důkazu: sazba se vezme ze sady pravidel zmrazené v té revizi (shoda otisku)
     * a musí po zaokrouhlení dát tutéž uloženou částku. Původ se hlásí jako
     * `reconstructed`, nikdy jako `persisted` — jinak by se důkaz vydával za
     * uložený záznam.
     */
    public function testMissingHealthStepIsReconstructedFromTheRulesetFrozenInTheRevision(): void
    {
        $this->useFrozenHealthRuleset();
        $this->persistResults(recordHealthSteps: false);

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame('reconstructed', $health['contribution']['rate_source']);
        self::assertNotNull($health['contribution']['standard_step']);
        self::assertSame(self::HEALTH_RATE, $health['contribution']['standard_step']['rate']['decimal']);
        // Krok musí vycházet z uloženého základu a dát uloženou částku na haléř.
        self::assertSame(3_000_000, $health['contribution']['standard_step']['input_minor_units']);
        self::assertSame(405_000, $health['contribution']['standard_minor']);
        self::assertSame(405_000, $health['contribution']['standard_step']['output_minor_units']);

        $evidence = $health['contribution']['rate_reconstruction'];
        self::assertSame($this->healthRulesetId, $evidence['ruleset_id']);
        self::assertSame($this->healthRulesetHash, $evidence['ruleset_hash']);
        self::assertSame('ruleset_hash_and_amount_match', $evidence['proof']);
        self::assertTrue($evidence['standard_reconstructed']);
        self::assertFalse($evidence['top_up_reconstructed']);
    }

    /**
     * Dopočet do minima se rekonstruuje stejným důkazem, takže i jeho základ
     * přestane být neznámý.
     */
    public function testMissingTopUpStepIsReconstructedTogetherWithItsBase(): void
    {
        $this->useFrozenHealthRuleset();
        $this->persistResults(
            healthBaseMinorUnits: 450_000,
            healthMinimumMinorUnits: 2_240_000,
            recordHealthSteps: false,
        );

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame('reconstructed', $health['contribution']['rate_source']);
        self::assertSame(1_790_000, $health['minimum']['top_up_base_minor']);
        self::assertSame(241_600, $health['contribution']['employee_top_up_minor']);
        self::assertTrue($health['contribution']['rate_reconstruction']['top_up_reconstructed']);
        self::assertSame(
            'rounded_total_difference',
            $health['contribution']['rate_reconstruction']['top_up_rounding_method'],
        );
    }

    public function testMissingTopUpStepCanBeReconstructedForZeroAssessmentBase(): void
    {
        $this->useFrozenHealthRuleset();
        $this->persistResults(
            healthBaseMinorUnits: 0,
            healthMinimumMinorUnits: 2_240_000,
            recordHealthSteps: false,
        );

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame(302_400, $health['contribution']['employee_top_up_minor']);
        self::assertTrue($health['contribution']['rate_reconstruction']['top_up_reconstructed']);
        self::assertSame(
            'rounded_total_difference',
            $health['contribution']['rate_reconstruction']['top_up_rounding_method'],
        );
    }

    public function testPersistedTopUpRoundsTheMinimumTotalOnlyOnce(): void
    {
        $this->persistResults(
            healthBaseMinorUnits: 10_100,
            healthMinimumMinorUnits: 2_240_000,
            recordHealthSteps: true,
        );

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame(301_000, $health['contribution']['employee_top_up_minor']);
        self::assertSame(302_400, $health['contribution']['total_minor']);
        self::assertSame(
            'monthly-health-insurance-minimum-total',
            $health['contribution']['minimum_total_step']['label'],
        );
    }

    /**
     * Rekonstrukce, která nedá uloženou částku, se ZAHODÍ. Sada pravidel sedí
     * otiskem, ale výpočet, který částku vydal, byl jiný — a popsat ho dnešní
     * sazbou by byl odhad vydávaný za doklad.
     */
    public function testReconstructionThatDoesNotReproduceTheStoredAmountIsRejected(): void
    {
        $this->useFrozenHealthRuleset();
        // Uložené částky vznikly jinou sazbou, než jakou nese zmrazená sada.
        $this->persistResults(recordHealthSteps: false, healthRate: '0.12');

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame('not_recorded', $health['contribution']['rate_source']);
        self::assertNull($health['contribution']['rate_reconstruction']);
        self::assertNull($health['contribution']['standard_step']);
        // Uložená částka platí dál — jen se k ní nedá doložit sazba.
        self::assertSame(360_000, $health['contribution']['standard_minor']);
    }

    /**
     * MZ-11 — pojistné zaměstnavatele na sociální není osobní veličina (§ 5a
     * odst. 1 z. č. 589/1992 Sb.), takže osobní číslo je ROZDĚLENÍ. Rozdělení
     * musí být beze zbytku: součet podílů se rovná firemní částce na korunu
     * i tehdy, když poměr základů nevychází.
     */
    public function testEmployerSocialAllocationAddsUpToTheCompanyAmount(): void
    {
        // Ošklivý poměr 1 000 000 : 2 000 100 haléřů, aby zbytek po dělení vznikl.
        $this->persistResults(cappedBasesByIndex: [0 => 1_000_000, 1 => 2_000_100]);

        $allocated = 0;
        $companyTotal = null;
        foreach ($this->employeeIds as $employeeId) {
            $employer = $this->service->breakdown(
                $this->supplierId,
                $this->revisionId,
                $employeeId,
            )['social']['employer'];
            $allocation = $employer['allocation'];
            self::assertSame('capped_assessment_base_share', $allocation['method']);
            self::assertSame('largest_remainder', $allocation['residual_rule']);
            self::assertFalse($allocation['is_statutory_personal_amount']);
            self::assertNull($allocation['not_allocatable_reason']);
            self::assertSame(3_000_100, $allocation['company_assessment_base_minor']);
            self::assertSame($employer['contribution_minor'], $allocation['company_contribution_minor']);
            $companyTotal = $employer['contribution_minor'];
            $allocated += $allocation['person_minor'];
        }

        self::assertSame(744_100, $companyTotal);
        self::assertSame($companyTotal, $allocated);
    }

    /**
     * Firma se dvěma sazbovými kategoriemi § 5a odst. 1 současně.
     *
     * Rozdělit firemní součet poměrem VŠECH základů by osobě s běžnou sazbou
     * přisoudilo kus pojistného, které vzniklo sazbou pro rizikové práce.
     * Podíl proto vzniká uvnitř kategorie a rovná se přesně jejímu pojistnému,
     * protože v každé je právě jeden člověk.
     */
    public function testEmployerAllocationStaysInsideItsRateCategory(): void
    {
        $this->persistResults(
            cappedBasesByIndex: [0 => 4_000_000, 1 => 6_000_000],
            rateCategoriesByIndex: [1 => SocialEmployerRateCategory::RiskEmployment],
        );

        $first = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['social']['employer'];
        $second = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[1],
        )['social']['employer'];

        // 24,8 % ze 40 000 Kč = 9 920 Kč; 27,8 % ze 60 000 Kč = 16 680 Kč.
        self::assertSame(2_660_000, $first['contribution_minor']);
        self::assertSame(992_000, $first['allocation']['person_minor']);
        self::assertSame(1_668_000, $second['allocation']['person_minor']);
        // Poměr všech základů 40:60 by dal 1 064 000 a 1 596 000.
        self::assertNotSame(1_064_000, $first['allocation']['person_minor']);

        self::assertSame(
            [
                ['ordinary', 'a', 4_000_000, 992_000],
                ['risk_employment', 'c', 6_000_000, 1_668_000],
            ],
            array_map(
                static fn (array $category): array => [
                    $category['category'],
                    $category['paragraph5a_letter'],
                    $category['assessment_base_minor'],
                    $category['contribution_minor'],
                ],
                $first['categories'],
            ),
        );
        // Jeden krok pro dvě sazby neexistuje; vysvětlení nese rozpad.
        self::assertNull($first['contribution_step']);
    }

    /**
     * Výsledek uložený dřív, než rozpad § 5a vůbec existoval, klíč
     * `employer_categories` nemá. Rozklad ho musí přečíst tak, jak vznikl —
     * odmítnout ho jako neúplný by účetní vzalo vysvětlení k uzavřeným
     * měsícům, které přitom byly spočítané správně (tehdy uměl modul jedinou
     * kategorii, takže se poměr rozdělení nemění).
     */
    public function testResultStoredBeforeRateCategoriesExistedIsStillReadable(): void
    {
        $this->persistResults(
            cappedBasesByIndex: [0 => 1_000_000, 1 => 2_000_100],
            withoutRateCategories: true,
        );

        $allocated = 0;
        $companyTotal = null;
        foreach ($this->employeeIds as $employeeId) {
            $employer = $this->service->breakdown(
                $this->supplierId,
                $this->revisionId,
                $employeeId,
            )['social']['employer'];
            self::assertSame([], $employer['categories']);
            self::assertNull($employer['allocation']['not_allocatable_reason']);
            self::assertSame(
                'capped_assessment_base_share',
                $employer['allocation']['method'],
            );
            $companyTotal = $employer['contribution_minor'];
            $allocated += $employer['allocation']['person_minor'];
        }

        self::assertSame(744_100, $companyTotal);
        self::assertSame($companyTotal, $allocated);
    }

    /**
     * Osoba s nulovým vyměřovacím základem nesmí dostat nic — ani zbytek po
     * dělení. Celá částka patří tomu, kdo základ má.
     */
    public function testPersonWithoutAnAssessmentBaseGetsNothingFromTheAllocation(): void
    {
        $this->persistResults(cappedBasesByIndex: [0 => 0, 1 => 3_000_000]);

        $first = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['social']['employer'];
        $second = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[1],
        )['social']['employer'];

        self::assertSame(0, $first['allocation']['person_minor']);
        self::assertSame(0, $first['allocation']['person_assessment_base_minor']);
        self::assertSame($second['contribution_minor'], $second['allocation']['person_minor']);
    }

    /**
     * Sada pravidel, kterou nejde dohledat, rekonstrukci NEUMOŽNÍ — otisk se
     * s ničím neshoduje a rozklad zůstane u přiznání, že sazba chybí.
     */
    public function testUnknownRulesetLeavesTheRateUnrecorded(): void
    {
        $this->persistResults(recordHealthSteps: false);

        $health = $this->service->breakdown(
            $this->supplierId,
            $this->revisionId,
            $this->employeeIds[0],
        )['health'];

        self::assertSame('not_recorded', $health['contribution']['rate_source']);
        self::assertNull($health['contribution']['rate_reconstruction']);
    }

    /**
     * Přepne revizi na sadu pravidel, kterou registry ZNÁ, aby šlo ověřit
     * rekonstrukci. Bere se efektivní verze i s jejím otiskem — přesně to, co
     * by v revizi zmrazil reálný běh.
     */
    private function useFrozenHealthRuleset(): void
    {
        $entry = $this->rulesets->entry('cz-payroll-2026.health-insurance.v1');
        if ($entry === null) {
            $this->markTestSkipped('Sada pravidel zdravotního pojištění není v registru.');
        }
        $version = $entry['version'];
        $this->healthRulesetId = $version->id;
        $this->healthRulesetHash = $version->canonicalHash;
        $snapshotJson = CanonicalJson::encode($this->snapshot());
        $manifestJson = CanonicalJson::encode([
            'rulesets' => $this->snapshot()['ruleset_manifest'],
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET input_snapshot_json = ?, input_snapshot_hash = ?, ruleset_manifest_hash = ?
              WHERE id = ?',
        )->execute([
            $snapshotJson,
            hash('sha256', $snapshotJson),
            hash('sha256', $manifestJson),
            $this->revisionId,
        ]);
    }

    public function testForeignPersonIsNotPartOfTheRevision(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->breakdown($this->supplierId, $this->revisionId, 987_654_321);
    }

    /** @param array<int,int> $cappedBasesByIndex */
    private function persistResults(
        int $cappedBaseMinorUnits = 3_000_000,
        int $yearToDateMinorUnits = 0,
        int $healthBaseMinorUnits = 3_000_000,
        int $healthMinimumMinorUnits = 0,
        bool $recordHealthSteps = true,
        array $cappedBasesByIndex = [],
        string $healthRate = self::HEALTH_RATE,
        array $rateCategoriesByIndex = [],
        bool $withoutRateCategories = false,
    ): void {
        $this->persister->persist(
            $this->supplierId,
            $this->revisionId,
            null,
            $this->snapshot(),
            $this->socialResult(
                $cappedBaseMinorUnits,
                $yearToDateMinorUnits,
                $cappedBasesByIndex,
                $rateCategoriesByIndex,
                $withoutRateCategories,
            ),
            $this->healthResult(
                $healthBaseMinorUnits,
                $healthMinimumMinorUnits,
                $recordHealthSteps,
                $healthRate,
            ),
            $this->taxResults(),
            $this->netResults(),
        );
    }

    /**
     * @param array<int,int> $cappedBasesByIndex
     * @param array<int,SocialEmployerRateCategory> $rateCategoriesByIndex
     */
    private function socialResult(
        int $cappedBase,
        int $yearToDate,
        array $cappedBasesByIndex = [],
        array $rateCategoriesByIndex = [],
        bool $withoutRateCategories = false,
    ): SocialInsuranceMonthResult {
        $rate = DecimalRate::fromString(self::SOCIAL_EMPLOYEE_RATE);
        $people = [];
        $employeeTotal = 0;
        $companyBase = 0;
        $participatingTotal = 0;
        foreach ($this->employeeIds as $index => $employeeId) {
            $personBase = $cappedBasesByIndex[$index] ?? $cappedBase;
            $companyBase += $personBase;
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $participating = max(3_000_000, $personBase);
            $participatingTotal += $participating;
            $step = CalculationStep::calculate(
                'monthly-employee-social-insurance',
                $personBase,
                $rate,
                RoundingMode::Ceil,
            );
            $contribution = PayrollRounding::ceilToCzk($step->outputMinorUnits);
            $employeeTotal += $contribution;
            $people[] = new SocialPersonMonthResult(
                "employee:{$employeeId}",
                SocialCalculationStatus::Calculated,
                SocialJurisdictionEvidence::CzechRegimeVerified,
                'evidence:social-a1',
                null,
                $yearToDate,
                $participating,
                $personBase,
                $contribution,
                0,
                $contribution,
                $step,
                null,
                [new SocialRelationshipResult(
                    $relationshipReference,
                    SocialEmploymentKind::Employment,
                    new SocialParticipationDecision(
                        $relationshipReference,
                        SocialParticipationStatus::Participates,
                        3_000_000,
                        3_000_000,
                        null,
                        ['regular-employment'],
                    ),
                    $participating,
                    $personBase,
                    ['BASE'],
                    [],
                    ['BASE'],
                    ['MEAL_ALLOWANCE'],
                    SocialDiscountEvidence::NotClaimed,
                    $rateCategoriesByIndex[$index] ?? SocialEmployerRateCategory::Ordinary,
                    1,
                    null,
                )],
                [],
            );
        }
        $categoryBases = [];
        foreach (array_keys($this->employeeIds) as $index) {
            $category = ($rateCategoriesByIndex[$index] ?? SocialEmployerRateCategory::Ordinary)
                ->value;
            $categoryBases[$category] = ($categoryBases[$category] ?? 0)
                + ($cappedBasesByIndex[$index] ?? $cappedBase);
        }
        $categories = [];
        $employer = 0;
        foreach (SocialEmployerRateCategory::statutoryOrder() as $category) {
            if (!array_key_exists($category->value, $categoryBases)) {
                continue;
            }
            $step = CalculationStep::calculate(
                "monthly-employer-social-insurance-{$category->value}",
                $categoryBases[$category->value],
                DecimalRate::fromString(self::EMPLOYER_RATES[$category->value]),
                RoundingMode::Ceil,
            );
            $amount = PayrollRounding::ceilToCzk($step->outputMinorUnits);
            $employer += $amount;
            $categories[] = new SocialEmployerCategoryResult(
                $category,
                $categoryBases[$category->value],
                $amount,
                $step,
            );
        }
        /*
         * Tvar výsledku uloženého dřív, než rozpad § 5a existoval: prázdný
         * seznam kategorií a jediný krok zaměstnavatele. Prázdný seznam se
         * čte stejně jako úplně chybějící klíč, takže jím jde nasimulovat
         * revize z doby před migrací, aniž by se sahalo na neměnný záznam.
         */
        if ($withoutRateCategories) {
            $legacyStep = CalculationStep::calculate(
                'monthly-employer-social-insurance',
                $companyBase,
                DecimalRate::fromString(self::SOCIAL_EMPLOYER_RATE),
                RoundingMode::Ceil,
            );

            return new SocialInsuranceMonthResult(
                '2026-06-30',
                SocialCalculationStatus::Calculated,
                $participatingTotal,
                $companyBase,
                $employeeTotal,
                PayrollRounding::ceilToCzk($legacyStep->outputMinorUnits),
                0,
                0,
                PayrollRounding::ceilToCzk($legacyStep->outputMinorUnits),
                $legacyStep,
                null,
                [],
                $people,
                [],
                self::SOCIAL_RULESET_ID,
                self::SOCIAL_RULESET_HASH,
            );
        }

        return new SocialInsuranceMonthResult(
            '2026-06-30',
            SocialCalculationStatus::Calculated,
            $participatingTotal,
            $companyBase,
            $employeeTotal,
            $employer,
            0,
            0,
            $employer,
            count($categories) === 1 ? $categories[0]->contributionStep : null,
            null,
            $categories,
            $people,
            [],
            self::SOCIAL_RULESET_ID,
            self::SOCIAL_RULESET_HASH,
        );
    }

    private function healthResult(
        int $assessmentBase,
        int $minimumBase,
        bool $recordSteps,
        string $rateDecimal = self::HEALTH_RATE,
    ): HealthInsuranceMonthResult {
        $rate = DecimalRate::fromString($rateDecimal);
        $insurerCodes = ['111', '201'];
        $people = [];
        $liabilities = [];
        $employeeTotal = 0;
        $employerTotal = 0;
        foreach ($this->employeeIds as $index => $employeeId) {
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $standardStep = CalculationStep::calculate(
                'monthly-health-insurance-standard',
                $assessmentBase,
                $rate,
                RoundingMode::Ceil,
            );
            $standard = PayrollRounding::ceilToCzk($standardStep->outputMinorUnits);
            $employeeStandard = PayrollRounding::ceilToCzk(
                RoundingMode::Ceil->roundFraction($standard, 3),
            );
            $employerStandard = $standard - $employeeStandard;
            $minimumStep = null;
            $topUpStep = null;
            $topUp = 0;
            if ($minimumBase > $assessmentBase) {
                $topUpStep = CalculationStep::calculate(
                    'monthly-health-insurance-minimum-top-up',
                    $minimumBase - $assessmentBase,
                    $rate,
                    RoundingMode::Ceil,
                );
                $minimumStep = CalculationStep::calculate(
                    'monthly-health-insurance-minimum-total',
                    $minimumBase,
                    $rate,
                    RoundingMode::Ceil,
                );
                $topUp = PayrollRounding::healthMinimumTopUp(
                    $standard,
                    PayrollRounding::ceilToCzk($minimumStep->outputMinorUnits),
                );
            }
            $employee = $employeeStandard + $topUp;
            $employeeTotal += $employee;
            $employerTotal += $employerStandard;
            $people[] = new HealthPersonMonthResult(
                personId: "employee:{$employeeId}",
                status: HealthCalculationStatus::Calculated,
                jurisdiction: HealthJurisdictionEvidence::CzechRegimeVerified,
                jurisdictionEvidenceReference: 'evidence:health-jurisdiction',
                insurerStatus: HealthInsurerSnapshotStatus::Verified,
                insurerCode: $insurerCodes[$index],
                insurerEvidenceReference: 'evidence:health-insurer',
                assessmentBaseMinorUnits: $assessmentBase,
                otherEmployerAssessmentBaseMinorUnits: 0,
                combinedAssessmentBaseMinorUnits: $assessmentBase,
                employmentCalendarDays: 30,
                minimumExcludedCalendarDays: 0,
                minimumApplicableCalendarDays: 30,
                statutoryMonthlyMinimumMinorUnits: $minimumBase,
                effectiveMinimumMinorUnits: $minimumBase,
                topUpResponsibility: HealthMinimumTopUpResponsibility::Employee,
                topUpResponsibilityEvidenceReference: null,
                selectedTopUpEmployerEvidenceReference: null,
                standardContributionMinorUnits: $standard,
                employeeStandardContributionMinorUnits: $employeeStandard,
                employerStandardContributionMinorUnits: $employerStandard,
                employeeMinimumTopUpMinorUnits: $topUp,
                employerMinimumTopUpMinorUnits: 0,
                employeeContributionMinorUnits: $employee,
                employerContributionMinorUnits: $employerStandard,
                totalContributionMinorUnits: $standard + $topUp,
                relationships: [new HealthRelationshipResult(
                    $relationshipReference,
                    HealthEmploymentKind::Employment,
                    new HealthParticipationDecision(
                        $relationshipReference,
                        HealthParticipationStatus::Participates,
                        $assessmentBase,
                        $assessmentBase,
                        null,
                        ['regular-employment'],
                    ),
                    $assessmentBase,
                    $assessmentBase,
                    ['BASE'],
                    [],
                    ['BASE'],
                    [],
                )],
                minimumReductionEvidence: [],
                otherEmployerEvidence: [],
                issues: [],
                standardContributionStep: $recordSteps ? $standardStep : null,
                minimumTopUpStep: $recordSteps ? $topUpStep : null,
                minimumContributionStep: $recordSteps ? $minimumStep : null,
            );
            $liabilities[] = new HealthInsurerLiabilityResult(
                $insurerCodes[$index],
                1,
                $assessmentBase,
                $employee,
                $employerStandard,
                $employee + $employerStandard,
            );
        }

        return new HealthInsuranceMonthResult(
            '2026-06-30',
            HealthCalculationStatus::Calculated,
            $assessmentBase * count($this->employeeIds),
            $employeeTotal,
            $employerTotal,
            $employeeTotal + $employerTotal,
            $people,
            $liabilities,
            [],
            $this->healthRulesetId,
            $this->healthRulesetHash,
        );
    }

    /** @return array<int,MonthlyEmploymentIncomeTaxResult> */
    private function taxResults(): array
    {
        $results = [];
        foreach ($this->employeeIds as $employeeId) {
            $relationshipReference = "employment:{$this->employmentByEmployee[$employeeId]}";
            $results[$employeeId] = new MonthlyEmploymentIncomeTaxResult(
                TaxCalculationStatus::Calculated,
                '2026-06-30',
                "employee:{$employeeId}",
                "supplier:{$this->supplierId}",
                [new RelationshipTaxResult(
                    $relationshipReference,
                    EmploymentRelationshipKind::Employment,
                    3_000_000,
                    TaxRegime::Advance,
                    null,
                )],
                new MonthlyAdvanceTaxResult(
                    3_000_000,
                    3_000_000,
                    3_000_000,
                    0,
                    [],
                    450_000,
                    0,
                    0,
                    false,
                    450_000,
                    0,
                    self::TAX_RULESET_ID,
                    self::TAX_RULESET_HASH,
                ),
                [],
                0,
                0,
                0,
                0,
                [],
                0,
                0,
                new AnnualTaxAccumulatorResult(
                    2026,
                    5,
                    3_000_000,
                    0,
                    450_000,
                    0,
                    0,
                    0,
                    0,
                    3_000_000,
                    false,
                    [],
                    true,
                    false,
                ),
                [],
                EmploymentIncomeTaxPolicy2026::ID,
                EmploymentIncomeTaxPolicy2026::contractHash(),
                self::TAX_RULESET_ID,
                self::TAX_RULESET_HASH,
            );
        }
        ksort($results, SORT_NUMERIC);

        return $results;
    }

    /** @return array<int,PayrollNetResult> */
    private function netResults(): array
    {
        $results = [];
        foreach ($this->employeeIds as $employeeId) {
            $results[$employeeId] = new PayrollNetResult(
                "employee:{$employeeId}",
                [new NetRelationshipIncome(
                    "employment:{$this->employmentByEmployee[$employeeId]}",
                    3_000_000,
                    0,
                )],
                3_000_000,
                0,
                213_000,
                135_000,
                450_000,
                0,
                0,
                0,
                2_202_000,
                0,
                2_202_000,
                [],
            );
        }
        ksort($results, SORT_NUMERIC);

        return $results;
    }

    /** @return array<string,mixed> */
    private function storedPersonSnapshot(string $kind, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT result_snapshot_json
               FROM payroll_statutory_person_results
              WHERE supplier_id = ? AND revision_id = ?
                AND calculation_kind = ? AND employee_id = ?',
        );
        $stmt->execute([$this->supplierId, $this->revisionId, $kind, $employeeId]);
        $json = $stmt->fetchColumn();
        self::assertIsString($json);

        return (array) json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $people = [];
        foreach ($this->employeeIds as $employeeId) {
            $employmentId = $this->employmentByEmployee[$employeeId];
            $people[] = [
                'employee' => [
                    'id' => $employeeId,
                    'full_name' => "Syntetická osoba {$employeeId}",
                    'profile_status' => 'complete',
                    'is_active' => true,
                ],
                'statutory_evidence' => ['schema_version' => 'synthetic.v1'],
                'enforcement_evidence' => null,
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'code' => 'synthetic',
                        'relation_type' => 'employment',
                    ],
                    'term' => ['id' => 1, 'effective_from' => '2026-01-01'],
                    'inputs' => [],
                    'absences' => [],
                    'time_month' => null,
                ]],
            ];
        }

        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-10',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-10',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'office_id' => null,
            'ruleset_manifest' => [
                ['id' => self::SOCIAL_RULESET_ID, 'sha256' => self::SOCIAL_RULESET_HASH],
                ['id' => $this->healthRulesetId, 'sha256' => $this->healthRulesetHash],
                ['id' => self::TAX_RULESET_ID, 'sha256' => self::TAX_RULESET_HASH],
            ],
            'people' => $people,
        ];
    }

    private function createRevisionGraph(PDO $pdo): int
    {
        $snapshotJson = CanonicalJson::encode($this->snapshot());
        $manifestJson = CanonicalJson::encode([
            'rulesets' => $this->snapshot()['ruleset_manifest'],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-07-10")',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v2", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            hash('sha256', $manifestJson),
            $snapshotJson,
            hash('sha256', $snapshotJson),
            hash('sha256', 'synthetic-insurance-breakdown-' . $this->supplierId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personInsert = $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        );
        $employmentInsert = $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, status)
             VALUES (?, ?, ?, ?, "{}", ?, "calculated")',
        );
        foreach ($this->employeeIds as $employeeId) {
            $personInsert->execute([$this->supplierId, $revisionId, $employeeId]);
            $employmentInsert->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                $this->employmentByEmployee[$employeeId],
                str_repeat('3', 64),
            ]);
        }

        return $revisionId;
    }

    private function createEmployee(PDO $pdo, int $supplierId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 30000, 0, 1)',
        )->execute([$supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployment(PDO $pdo, int $supplierId, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01")',
        )->execute([$supplierId, $employeeId, "synthetic-{$employeeId}"]);

        return (int) $pdo->lastInsertId();
    }

    private function firstSupplierId(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
