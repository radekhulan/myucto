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
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vrácení doplatku ze zúčtování v mzdovém běhu — celá cesta proti databázi.
 *
 * Roční zúčtování dřív končilo dokladem a peníze se nikam nepohnuly. Test hlídá
 * tři věci, které dohromady tvoří § 38ch odst. 5 a § 35d odst. 8:
 *  • doplatek se v březnovém běhu skutečně vyplatí,
 *  • NEZVÝŠÍ čistou mzdu před srážkami — není mzda, je to vrácená záloha,
 *    a základ srážek podle § 277 odst. 1 OSŘ z něj proto neroste,
 *  • po březnu už do žádného běhu nevstoupí.
 */
#[Group('integration')]
final class AnnualSettlementPayoutRunTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MONTHLY_GROSS_MINOR = 3_500_000;

    /** Přeplatek 2 500 Kč — jednoznačně nad prahem 50 Kč (§ 38ch odst. 5). */
    private const PAYABLE_MINOR = 250_000;

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
        if (!$this->db->hasTable('payroll_annual_settlement_outcomes')) {
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
            'source_reference' => 'synthetic:annual-settlement-policy',
        ], $this->actorId);

        $this->seedEmployee();
        $this->seedStatutoryEvidence();
        $this->seedZeroOpenings();
        $this->seedWageComponent();
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
     * § 35d odst. 8: „vyplatí plátce daně poplatníkovi nejpozději při zúčtování
     * mzdy za březen po uplynutí zdaňovacího období."
     */
    public function testMarchRunPaysTheSettlementOutsideTheDeductionBase(): void
    {
        $outcomeId = $this->seedSettlementOutcome('2026-02-20');
        $this->seedWage('2026-03-01');

        $calculated = $this->calculateRun('2026-03-01');
        $net = $calculated['net'];

        // K výplatě se doplatek musí dostat i za exekučními srážkami —
        // jinak by se zaměstnanci vrátil jen na papíře.
        self::assertSame(
            $net['net_payable_minor_units'],
            $calculated['person']['payable_after_enforcement_minor'],
        );
        self::assertSame(
            self::PAYABLE_MINOR,
            $net['annual_settlement_minor_units'],
        );
        self::assertSame(
            $net['net_before_deductions_minor_units'] + self::PAYABLE_MINOR,
            $net['net_payable_minor_units'],
        );

        $outcome = $this->outcome($outcomeId);
        self::assertNotNull($outcome['payout_run_id']);
        self::assertNotNull($outcome['payout_revision_id']);
        self::assertSame('2026-03-01', substr(
            (string) $outcome['payout_period_start'],
            0,
            10,
        ));
    }

    /**
     * Po březnu už doplatek do běhu nevstoupí. Pozdější výplata není opožděné
     * plnění téhle povinnosti, ale oprava — a tu modul sám nedělá.
     */
    public function testRunAfterMarchDoesNotPayTheSettlement(): void
    {
        $outcomeId = $this->seedSettlementOutcome('2026-02-20');
        $this->seedWage('2026-04-01');

        $net = $this->calculateRun('2026-04-01')['net'];

        self::assertSame(0, $net['annual_settlement_minor_units']);
        self::assertSame(
            $net['net_before_deductions_minor_units'],
            $net['net_payable_minor_units'],
        );
        self::assertNull($this->outcome($outcomeId)['payout_run_id']);
    }

    /**
     * Zúčtování provedené až po uzavření měsíce se do něj zpětně nedostane —
     * vyplácí se od měsíce provedení dál, ne dřív.
     */
    public function testRunBeforeTheSettlementDoesNotPayIt(): void
    {
        $outcomeId = $this->seedSettlementOutcome('2026-03-15');
        $this->seedWage('2026-02-01');

        $net = $this->calculateRun('2026-02-01')['net'];

        self::assertSame(0, $net['annual_settlement_minor_units']);
        self::assertNull($this->outcome($outcomeId)['payout_run_id']);
    }

    /** @return array{net:array<string,mixed>,person:array<string,mixed>} */
    private function calculateRun(string $periodStart): array
    {
        $paymentDate = (new \DateTimeImmutable($periodStart))
            ->modify('+1 month')
            ->format('Y-m-15');
        $run = $this->runs->createRun(
            $this->supplierId,
            $periodStart,
            $paymentDate,
            null,
            $this->actorId,
        );
        $locked = $this->runs->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            "lock-annual-settlement-{$periodStart}",
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "calculate-annual-settlement-{$periodStart}",
            $this->actorId,
        );
        $statutory = $calculated->revision['result_snapshot']['statutory'];
        self::assertSame('calculated', $statutory['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory),
        ));

        return [
            'net' => $statutory['people'][0]['net_pay'],
            'person' => $calculated->revision['result_snapshot']['people'][0],
        ];
    }

    /** @return array<string,mixed> */
    private function outcome(int $outcomeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT payout_run_id, payout_revision_id, payout_period_start
               FROM payroll_annual_settlement_outcomes
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $outcomeId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function seedSettlementOutcome(string $settledOn): int
    {
        $pdo = $this->db->pdo();
        $manifest = CanonicalJson::encode(['synthetic' => true]);
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_by, approved_at)
             VALUES (?, ?, 2025, "annual_settlement_result", 1,
                     "synthetic", ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            str_repeat('a', 64),
            $manifest,
            hash('sha256', $manifest),
            $this->actorId,
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_annual_settlement_outcomes
                (supplier_id, employee_id, tax_year, annual_revision_id,
                 outcome, tax_difference_minor, bonus_difference_minor,
                 settlement_difference_minor, payable_minor,
                 payout_threshold_minor, settled_on, created_by)
             VALUES (?, ?, 2025, ?, "overpayment", ?, 0, ?, ?, 5000, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $revisionId,
            self::PAYABLE_MINOR,
            self::PAYABLE_MINOR,
            self::PAYABLE_MINOR,
            $settledOn,
            $this->actorId,
        ]);

        return (int) $pdo->lastInsertId();
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
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::MONTHLY_GROSS_MINOR,
        ]);
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
            'annual-settlement-social-opening',
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
            'annual-settlement-tax-opening',
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

    private function seedWage(string $periodStart): void
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
             VALUES (?, ?, ?, ?, ?, ?, "manual", "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $this->componentId,
            $periodStart,
            self::MONTHLY_GROSS_MINOR,
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
            'annual-settlement-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
