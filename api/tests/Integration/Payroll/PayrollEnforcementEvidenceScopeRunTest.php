<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozsah měsíční exekuční evidence osoby v celém mzdovém běhu.
 *
 * `GarnishmentCalculator` vyžadoval tři měsíční potvrzení — rejstřík pohledávek,
 * vyživované osoby, sleva na manžela — u KAŽDÉ osoby a KAŽDÝ měsíc. Chybějící
 * potvrzení zvedlo issue, výsledek spadl do `manual_review` a `PayrollRunRepository`
 * z něj udělal validaci `enforcement_manual_review` se `severity = blocker`
 * a `requires_override = 0`. Firma o tisíci lidech tak měla ročně 12 000 zápisů,
 * které u člověka bez jediné exekuce nedokládaly nic — a bez nich neschválila
 * jediný běh, protože blokátor nešel ani přebít.
 *
 * Test jede celou cestu proti databázi (uzamčení vstupů → výpočet → validace)
 * a hlídá obě strany zúžení:
 *  • osoba bez pohledávky a bez jediného řádku evidence doběhne bez issue
 *    a bez validace `enforcement_manual_review`,
 *  • osoba S aktivní pohledávkou a nedoloženým rejstříkem dál neprojde,
 *  • srážka doložené osoby se nezměnila ani o korunu.
 */
#[Group('integration')]
final class PayrollEnforcementEvidenceScopeRunTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const GROSS_MINOR = 3_500_000;

    /**
     * Regresní kotva. Odvozeno ze sady `enforcement-deductions` pro rok 2026
     * a z čisté mzdy 25 690 Kč (35 000 − 7,1 % SP − 4,5 % ZP − 15 % zálohové
     * daně bez slev, protože prohlášení k dani není podepsané):
     *
     *   nezabavitelná částka  ceil(14 101,50 / 100) * 100 = 14 102,00 Kč
     *   zbytek                25 690,00 − 14 102,00      = 11 588,00 Kč
     *   základ třetin         floor(11 588 / 3) * 3      = 11 586,00 Kč
     *   třetina                                             3 862,00 Kč
     *   paušální náhrada zaměstnavatele (strop)                50,00 Kč
     *
     * Nepřednostní pohledávka dostane první třetinu sníženou o náhradu,
     * sraženo je tedy celá třetina. Zúžení evidence se tohoto čísla nesmí
     * dotknout ani o korunu.
     */
    private const EXPECTED_PROTECTED_MINOR = 1_410_200;
    private const EXPECTED_THIRD_MINOR = 386_200;
    private const EXPECTED_EMPLOYER_FEE_MINOR = 5_000;
    private const EXPECTED_WITHHELD_MINOR = 386_200;

    private Connection $db;
    private PayrollRunCommandService $runs;
    private PayrollRunRepository $repository;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $componentId;
    private int $actorId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
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
        if (!$this->db->hasTable('payroll_runs')
            || !$this->db->hasTable('payroll_enforcement_cases')
        ) {
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
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:enforcement-scope-policy',
        ], $this->actorId);

        $this->seedEmployee();
        $this->seedStatutoryEvidence();
        $this->seedZeroOpenings();
        $this->seedWageComponent();
        $this->seedWage();
        // Exekuční evidence ZÁMĚRNĚ prázdná: žádný případ, žádná pohledávka,
        // žádný řádek `payroll_enforcement_person_month_evidence`. Právě tenhle
        // stav dřív shodil celý běh.
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
     * Osoba bez jediné exekuce a bez jediného řádku evidence. Dřív z ní vznikl
     * neprebitelný blokátor `enforcement_manual_review`; teď doběhne.
     */
    public function testPersonWithoutEnforcementNeedsNoMonthlyEvidence(): void
    {
        $run = $this->calculateRun();

        self::assertSame('calculated', $run['statutory']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($run['statutory']),
        ));
        $enforcement = $run['enforcement'];
        self::assertSame([], $enforcement['issues'], sprintf(
            "Bezexekuční osoba dostala výhrady:\n%s",
            CanonicalJson::encode($enforcement),
        ));
        self::assertSame('supported', $enforcement['status']);
        self::assertSame(0, $enforcement['total_withheld_minor_units']);
        self::assertNotContains(
            'enforcement_manual_review',
            $run['validation_codes'],
        );
        // Auditní stopa: ze snímku musí být poznat, že se nekontrolovalo proto,
        // že nebylo co kontrolovat — ne že to někdo odklikl.
        self::assertSame(
            [
                'claim_register' => 'not_applicable',
                'dependants' => 'not_applicable',
                'spouse' => 'not_applicable',
            ],
            $enforcement['evidence_source'],
        );
    }

    /**
     * Uplatněný nárok na vyživovanou osobu zvedá nezabavitelnou částku, a ta se
     * počítá i bez exekuce — odvozuje se z ní strop dobrovolné dohody o srážkách
     * (§ 148 odst. 2 zákoníku práce). Běh proto neblokuje, ale ve snímku je
     * vidět, že se na doklad nikdo neptal jen proto, že se nic nesráželo.
     */
    public function testUnattestedDependantWithoutEnforcementDoesNotBlockTheRun(): void
    {
        $this->seedDependant('dependant');

        $run = $this->calculateRun();

        self::assertSame('supported', $run['enforcement']['status']);
        self::assertSame([], $run['enforcement']['issues']);
        self::assertNotContains(
            'enforcement_manual_review',
            $run['validation_codes'],
        );
        self::assertSame(
            'nothing_withheld',
            $run['enforcement']['evidence_source']['dependants'],
        );
    }

    /**
     * Strážní tvrzení: jakmile je co srážet, doklad se vyžaduje dál. Aktivní
     * pohledávka bez potvrzeného rejstříku musí zůstat blokátorem.
     */
    public function testActiveClaimWithoutRegisterEvidenceStillBlocksTheRun(): void
    {
        $this->seedClaim();

        $run = $this->calculateRun();

        self::assertSame('manual_review', $run['enforcement']['status']);
        self::assertContains(
            'claim_register_evidence_incomplete',
            $run['enforcement']['issues'],
        );
        self::assertSame(
            'missing',
            $run['enforcement']['evidence_source']['claim_register'],
        );
        self::assertContains(
            'enforcement_manual_review',
            $run['validation_codes'],
        );
        self::assertSame(0, $run['enforcement']['total_withheld_minor_units']);
    }

    /**
     * Nedoložený nárok na vyživovanou osobu u osoby s exekucí je pořád důvod
     * k ručnímu posouzení — na téhle částce nárok visí.
     */
    public function testActiveClaimWithUnattestedDependantStillBlocksTheRun(): void
    {
        $this->seedClaim();
        $this->seedMonthEvidence(claimRegister: true, dependants: false, spouse: false);
        $this->seedDependant('dependant');

        $run = $this->calculateRun();

        self::assertSame('manual_review', $run['enforcement']['status']);
        self::assertContains(
            'dependants_evidence_incomplete',
            $run['enforcement']['issues'],
        );
        self::assertSame(
            'missing',
            $run['enforcement']['evidence_source']['dependants'],
        );
    }

    /**
     * Regresní kotva: doložená osoba s exekucí. Zúžení evidence se nesmí dotknout
     * ani jedné položky rozkladu srážky.
     */
    public function testAttestedEnforcementDeductionIsUnchanged(): void
    {
        $this->seedClaim();
        $this->seedMonthEvidence(claimRegister: true, dependants: true, spouse: true);

        $run = $this->calculateRun();
        $enforcement = $run['enforcement'];

        self::assertSame('supported', $enforcement['status'], sprintf(
            "Srážka nedoběhla. Celý výsledek:\n%s",
            CanonicalJson::encode($enforcement),
        ));
        self::assertSame(
            self::EXPECTED_PROTECTED_MINOR,
            $enforcement['protected_amount_minor_units'],
        );
        self::assertSame(
            self::EXPECTED_THIRD_MINOR,
            $enforcement['third_minor_units'],
        );
        self::assertSame(
            self::EXPECTED_EMPLOYER_FEE_MINOR,
            $enforcement['employer_flat_fee_minor_units'],
        );
        self::assertSame(
            self::EXPECTED_WITHHELD_MINOR,
            $enforcement['total_withheld_minor_units'],
        );
        self::assertSame(
            'declared',
            $enforcement['evidence_source']['claim_register'],
        );
        self::assertNotContains(
            'enforcement_manual_review',
            $run['validation_codes'],
        );
    }

    /**
     * @return array{
     *     statutory:array<string,mixed>,
     *     enforcement:array<string,mixed>,
     *     validation_codes:list<string>
     * }
     */
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
            'lock-enforcement-scope-run',
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-enforcement-scope-run',
            $this->actorId,
        );
        $snapshot = $calculated->revision['result_snapshot'];

        return [
            'statutory' => $snapshot['statutory'],
            'enforcement' => $snapshot['people'][0]['enforcement']['result'],
            'validation_codes' => array_column(
                $this->repository->validations(
                    $this->supplierId,
                    (int) $calculated->revision['id'],
                ),
                'code',
            ),
        ];
    }

    private function seedClaim(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified)
             VALUES (?, ?, "case-synthetic", "enforcement", "withhold_and_hold",
                     "2026-01-01", 1, 1)'
        )->execute([$this->supplierId, $this->employeeId]);
        $caseId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units, priority_date, first_payer_delivered_on,
                 order_issued_on, legal_title_verified, order_or_notice_delivered,
                 priority_classification_verified, due_monetary_claim_verified,
                 is_active)
             VALUES (?, ?, "claim-synthetic", "order-synthetic", "statutory",
                     "non_priority", 10000000, "2026-01-15", "2026-01-15", "2022-01-02",
                     1, 1, 1, 1, 1)'
        )->execute([$this->supplierId, $caseId]);
    }

    private function seedMonthEvidence(
        bool $claimRegister,
        bool $dependants,
        bool $spouse,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete, dependants_evidence_complete,
                 spouse_evidence_complete, pension_evidence)
             VALUES (?, ?, "2026-06-01", ?, ?, ?, "none")'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $claimRegister ? 1 : 0,
            $dependants ? 1 : 0,
            $spouse ? 1 : 0,
        ]);
    }

    private function seedDependant(string $kind): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_dependants
                (supplier_id, employee_id, dependant_key, dependant_kind,
                 valid_from, eligibility_verified, excluded_for_maintenance)
             VALUES (?, ?, ?, ?, "2026-01-01", 1, 0)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            "dependant-{$kind}",
            $kind,
        ]);
    }

    private function seedEmployee(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Zaměstnanec", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, "SYN-HPP", "employment", "active",
                     "2026-01-01", "2026-01-01", ?, 1)'
        )->execute([$this->supplierId, $this->employeeId, self::GROSS_MINOR]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 other_withholding_eligibility,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance",
                     "unverified", 0, 1)'
        )->execute([$this->supplierId, $this->employmentId]);
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
            'enforcement-scope-social-opening',
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
            'enforcement-scope-tax-opening',
            actorUserId: $this->actorId,
        );
    }

    private function seedWageComponent(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, "MZDA_MESICNI", "Měsíční mzda", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId]);
        $this->componentId = (int) $pdo->lastInsertId();
    }

    private function seedWage(): void
    {
        $pdo = $this->db->pdo();
        $snapshot = [
            'code' => 'MZDA_MESICNI',
            'name' => 'Měsíční mzda',
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $this->componentId,
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
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->componentId,
            self::GROSS_MINOR,
            $json,
            hash('sha256', $json, true),
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
            'enforcement-scope-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
