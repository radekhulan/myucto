<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Osvobozený příjem musí projít celým mzdovým během.
 *
 * Složka klasifikovaná jako `exempt` skončila v obou branách jako nedoložené
 * tvrzení: sestavovač zákonných vstupů ji bez zmrazeného rozpadu koše vyhodil
 * s `tax_component_exemption_evidence_missing` a výpočet daně navíc trval na
 * `hasVerifiedTreatmentEvidence()`, kterou nikdo v produkčním kódu nevyplňoval.
 * Uzavřít měsíc s cestovní náhradou do zákonného limitu tedy nešlo vůbec
 * a celý roční koš § 6 odst. 9 ZDP byl uzavřený v mrtvé větvi.
 *
 * Test jede přes celý běh (uzamčení vstupů → výpočet → validace) a hlídá obojí:
 * doložené osvobození projde a NEZDANÍ se, nedoložené dál poctivě blokuje.
 */
#[Group('integration')]
final class PayrollExemptIncomeRunTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const REMUNERATION_MINOR = 4_500_000;

    /** Cestovní náhrada do zákonného limitu § 6 odst. 7 písm. a) ZDP. */
    private const TRAVEL_MINOR = 120_000;

    /** § 6 odst. 9 písm. d) bod 2 ZDP — polovina průměrné mzdy 48 967 Kč. */
    private const LEISURE_LIMIT_MINOR = 2_448_350;

    /** § 6 odst. 9 písm. b) ZDP — 70 % ze 185,00 Kč za jednu směnu. */
    private const MEAL_LIMIT_MINOR = 12_950;

    private Connection $db;
    private PayrollRunCommandService $runs;
    private \Psr\Container\ContainerInterface $container;
    private PayrollRunRepository $repository;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $db = $container->get(Connection::class);
        $runs = $container->get(PayrollRunCommandService::class);
        $repository = $container->get(PayrollRunRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        if (!$db instanceof Connection
            || !$runs instanceof PayrollRunCommandService
            || !$repository instanceof PayrollRunRepository
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->runs = $runs;
        $this->repository = $repository;
        $this->accumulators = $accumulators;
        if (!$this->db->hasTable('payroll_runs')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->actorId = $this->createActor();
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actorId]);
        $policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => false,
            'automatic_payments_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:exempt-income-policy',
        ], $this->actorId);

        $this->seedDirector();
        $this->seedStatutoryEvidence();
        $this->seedZeroOpenings();
        $this->seedInput('ODMENA_JEDNATELE', 'Odměna jednatele', 'base_wage', [
            'tax_treatment' => 'included',
            'social_treatment' => 'included',
            'health_treatment' => 'included',
        ], self::REMUNERATION_MINOR);
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

    /**
     * Cestovní náhrada do zákonného limitu. Osvobození plyne přímo z parametru
     * rulesetu, doklad je sama verzovaná klasifikace složky — a měsíc s ní se
     * musí dát uzavřít.
     */
    public function testTravelReimbursementWithinStatutoryLimitCompletesTheRun(): void
    {
        $this->seedInput(
            'CESTOVNI_NAHRADA_LIMIT',
            'Cestovní náhrada do zákonného limitu',
            'travel_reimbursement',
            [
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'not_subject_to_tax',
                'social_treatment' => 'excluded',
                'social_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
            ],
            self::TRAVEL_MINOR,
        );

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['statutory']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory['result']['statutory']),
        ));
        self::assertNotContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );
        $person = $statutory['result']['statutory']['people'][0];
        // Osvobozená náhrada NESMÍ zvýšit základ daně…
        self::assertSame(
            self::REMUNERATION_MINOR,
            $person['income_tax']['relationships'][0]['taxable_base_minor_units'],
        );
        self::assertSame(
            self::REMUNERATION_MINOR,
            $person['income_tax']['advance_tax']['taxable_income_minor_units'],
        );
        // …ani do vyměřovacích základů pojistného.
        self::assertSame(
            self::REMUNERATION_MINOR,
            $person['social_insurance']['relationships'][0]
                ['assessment_base_minor_units'],
        );
        // …ale vyplatit se musí: nezdaněná náhrada je pořád peníze k výplatě.
        self::assertSame(
            self::REMUNERATION_MINOR + self::TRAVEL_MINOR,
            $person['net_pay']['cash_income_minor_units'],
        );
    }

    /**
     * Roční koš § 6 odst. 9 ZDP: podlimitní část je osvobozená, nadlimitní se
     * zdaní jako běžný příjem. Zmrazený rozpad je doklad obojího.
     */
    public function testBenefitBasketSplitIsExemptUpToTheLimitAndTaxedAbove(): void
    {
        $over = 100_000;
        $this->seedInput(
            'KOS_REKREACE_RUN',
            'Rekreace nad rámec koše',
            'benefit_recreation',
            [
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'benefit_basket',
                'exemption_basket' => 'non_cash_leisure',
                'value_kind' => 'non_monetary',
                'social_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
            ],
            self::LEISURE_LIMIT_MINOR + $over,
            [
                'benefit_basket' => 'non_cash_leisure',
                'benefit_exempt_minor' => self::LEISURE_LIMIT_MINOR,
                'benefit_taxable_minor' => $over,
            ],
        );

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['statutory']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory['result']['statutory']),
        ));
        $person = $statutory['result']['statutory']['people'][0];
        self::assertSame(
            self::REMUNERATION_MINOR + $over,
            $person['income_tax']['relationships'][0]['taxable_base_minor_units'],
        );
        self::assertSame(
            self::REMUNERATION_MINOR + $over,
            $person['income_tax']['advance_tax']['taxable_income_minor_units'],
        );
        // Do pojistného vstupuje celé plnění — § 6 odst. 9 ZDP je osvobození od
        // DANĚ, o vyměřovacích základech rozhoduje klasifikace složky zvlášť.
        //
        // Limit koše je půlka průměrné mzdy, tedy 24 483,50 Kč — základ z něj vyjde
        // na padesátník. § 5d zák. č. 589/1992 Sb. ale žádá vyměřovací základ
        // v celých korunách nahoru, takže se zaokrouhlí. Daňová strana výše
        // zaokrouhlená není: § 5d mluví o vyměřovacím základu, ne o základu daně.
        $rawSocialBase = self::REMUNERATION_MINOR + self::LEISURE_LIMIT_MINOR + $over;
        self::assertSame(
            (int) (ceil($rawSocialBase / 100) * 100),
            $person['social_insurance']['relationships'][0]
                ['assessment_base_minor_units'],
        );
    }

    /**
     * Stravenkový paušál musí projít celým během až do SCHVÁLENÉ REVIZE.
     *
     * § 6 odst. 9 písm. b) ZDP osvobozuje příspěvek na stravování do 70 % horní
     * hranice stravného za pracovní cestu 5 až 12 hodin, a to za každou směnu
     * zvlášť. Dokud aplikace limit neuměla rozpadnout, byla složka na
     * `manual_review` a měsíc s ní se nedal uzavřít vůbec. Tady jede přes dvě
     * směny s nárokem (2 × 129,50 Kč) a padesátihaléřový přesah, který se zdaní.
     */
    public function testMealStipendReachesAnApprovedRevision(): void
    {
        $exempt = 2 * self::MEAL_LIMIT_MINOR;
        $over = 50;
        $this->seedInput(
            'PRISPEVEK_STRAVOVANI_RUN',
            'Stravenkový paušál',
            'benefit_meal',
            [
                'tax_treatment' => 'exempt',
                'exemption_basis' => 'periodic_benefit_limit',
                'exemption_basket' => 'meal_per_shift',
                'social_treatment' => 'excluded',
                'social_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
                'jmhz_treatment' => 'excluded',
            ],
            $exempt + $over,
            [
                'benefit_basket' => 'meal_per_shift',
                'benefit_exempt_minor' => $exempt,
                'benefit_taxable_minor' => $over,
            ],
        );

        $approved = $this->runToApprovedRevision();
        $person = $approved['result']['statutory']['people'][0];

        self::assertSame('approved', $approved['run_status']);
        self::assertSame('calculated', $approved['result']['statutory']['status']);
        // Osvobozená část základ daně nezvyšuje, padesátihaléřový přesah ano.
        self::assertSame(
            self::REMUNERATION_MINOR + $over,
            $person['income_tax']['relationships'][0]['taxable_base_minor_units'],
        );
        // Vyplatí se ale celý příspěvek — osvobození je o dani, ne o výplatě.
        self::assertSame(
            self::REMUNERATION_MINOR + $exempt + $over,
            $person['net_pay']['cash_income_minor_units'],
        );
    }

    /**
     * Fail-closed nesmí zmizet: složka označená za osvobozenou bez jakéhokoli
     * podkladu se dál nesmí tiše osvobodit.
     */
    public function testExemptComponentWithoutAnyBasisStillBlocksTheRun(): void
    {
        $this->seedInput(
            'OSVOBOZENO_BEZ_DOKLADU',
            'Osvobozené plnění bez podkladu',
            'bonus',
            [
                'tax_treatment' => 'exempt',
                'social_treatment' => 'excluded',
                'social_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'excluded',
            ],
            50_000,
        );

        $statutory = $this->calculateRun();

        self::assertSame('manual_review', $statutory['result']['statutory']['status']);
        self::assertContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );
        self::assertNotEmpty(array_filter(
            $statutory['result']['statutory']['issues'] ?? [],
            static fn (string $issue): bool => str_contains(
                $issue,
                'tax_component_exemption_evidence_missing',
            ),
        ));
    }

    /**
     * Běh přes všechny stavy až do schválené revize. Tento test používá druhého
     * syntetického uživatele jen jako jednu z dovolených auditních variant;
     * běžný tok může dokončit také jedna oprávněná účetní.
     *
     * @return array{result:array<string,mixed>,run_status:string}
     */
    private function runToApprovedRevision(): array
    {
        // Schválení generuje výplatní pásky do úložiště souborů, a to není
        // transakční — služba proto odmítne běžet ve vnořené transakci, kterou si
        // test drží kvůli izolaci. Pásky nejsou předmětem tohohle testu, takže se
        // schvaluje instancí bez jejich generátoru; zbytek řetězce je tentýž.
        $approver = new PayrollRunCommandService(
            $this->db,
            $this->repository,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            $this->container->get(PayrollRunCalculationPipeline::class),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
        );
        $reviewerId = $this->createActor();
        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-meal-stipend-run',
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-meal-stipend-run',
            $this->actorId,
        );
        $reviewed = $approver->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-meal-stipend-run',
            $reviewerId,
        );
        $approved = $approver->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-meal-stipend-run',
            $reviewerId,
        );

        return [
            'result' => $calculated->revision['result_snapshot'],
            'run_status' => (string) $approved->run['status'],
        ];
    }

    /** @return array{result:array<string,mixed>,validation_codes:list<string>} */
    private function calculateRun(): array
    {
        $run = $this->runs->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-exempt-income-run',
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-exempt-income-run',
            $this->actorId,
        );

        return [
            'result' => $calculated->revision['result_snapshot'],
            'validation_codes' => array_column(
                $this->repository->validations(
                    $this->supplierId,
                    (int) $calculated->revision['id'],
                ),
                'code',
            ),
        ];
    }

    private function seedDirector(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Jednatel", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "OSVOB", "Syntetická účtárna", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, ?, "SYN-OSVOB", "statutory_body", "active",
                     "2026-01-01", "2026-01-01", ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $officeId,
            self::REMUNERATION_MINOR,
        ]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 other_withholding_eligibility,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance",
                     "ineligible", 0, 1)'
        )->execute([$this->supplierId, $this->employmentId, $officeId]);
    }

    private function seedStatutoryEvidence(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not-signed", "2026-01-01", "document:tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "document:tax-residence")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_month_evidence
                (supplier_id, employee_id, period_start, top_up_responsibility)
             VALUES (?, ?, "2026-06-01", "employee")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not_claimed", "2026-01-01", NULL)'
        )->execute([$this->supplierId, $this->employeeId]);
    }

    private function seedZeroOpenings(): void
    {
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            'exempt-income-social-opening',
            actorUserId: $this->actorId,
        );
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:tax-opening',
            ['verified_zero' => true],
            'exempt-income-tax-opening',
            actorUserId: $this->actorId,
        );
    }

    /**
     * @param array<string,string|null> $overrides
     * @param array<string,int|string> $split
     */
    private function seedInput(
        string $code,
        string $name,
        string $kind,
        array $overrides,
        int $amountMinor,
        array $split = [],
    ): void {
        $pdo = $this->db->pdo();
        $classification = [
            'component_kind' => $kind,
            'value_kind' => 'monetary',
            'frequency_kind' => 'one_off',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'exemption_basket' => null,
            'exemption_basis' => null,
            ...$overrides,
        ];
        $columns = array_keys($classification);
        $pdo->prepare(sprintf(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, %s,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, %s, "521", "331", "2026-01-01")',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ))->execute([
            $this->supplierId,
            $code,
            $name,
            ...array_values($classification),
        ]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => $name,
            ...$classification,
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 benefit_basket, benefit_exempt_minor, benefit_taxable_minor,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", "approved", ?, ?,
                     ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $amountMinor,
            $json,
            hash('sha256', $json, true),
            $split['benefit_basket'] ?? null,
            $split['benefit_exempt_minor'] ?? null,
            $split['benefit_taxable_minor'] ?? null,
            $this->actorId,
        ]);
    }

    private function createActor(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic payroll actor", "readonly", "cs", 1)'
        );
        $stmt->execute([
            'exempt-income-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
