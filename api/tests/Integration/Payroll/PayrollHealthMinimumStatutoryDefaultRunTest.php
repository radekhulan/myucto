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
 * Doplatek do minimálního vyměřovacího základu zdravotního pojištění bez ručně
 * zadané měsíční evidence.
 *
 * Sestavovač zákonných vstupů vyžadoval řádek `payroll_person_health_month_evidence`
 * bezpodmínečně — pro každou osobu a každý měsíc. Firma o tisíci lidech tak měla
 * zadat 12 000 řádků ročně, které jen opakují § 3 odst. 10 zákona č. 592/1992 Sb.:
 * doplatek hradí zaměstnanec, zaměstnavatel jen tehdy, je-li nižší základ
 * důsledkem překážek na jeho straně. Chybějící řádek proto znamená ten zákonný
 * výchozí stav, ne blokátor.
 *
 * Test jede celou cestu proti databázi (uzamčení vstupů → výpočet → validace)
 * a hlídá obě strany zjednodušení:
 *  • běžný zaměstnanec bez jediného řádku evidence doběhne do `calculated`,
 *    a to i v měsíci, kdy dopočet skutečně vzniká (pak ho platí zaměstnanec),
 *  • výjimku „hradí zaměstnavatel" lze potvrdit bez textové reference,
 *  • prohlášené `unverified` dál končí ručním posouzením.
 */
#[Group('integration')]
final class PayrollHealthMinimumStatutoryDefaultRunTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Nad minimálním vyměřovacím základem 2026 (22 400 Kč) — dopočet nevzniká. */
    private const GROSS_ABOVE_MINIMUM_MINOR = 3_500_000;

    /** Pod minimem: mezera 12 400 Kč, doplatek 13,5 % = 1 674 Kč. */
    private const GROSS_BELOW_MINIMUM_MINOR = 1_000_000;
    private const EXPECTED_TOP_UP_MINOR = 167_400;

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
            'automatic_posting_enabled' => false,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:health-minimum-policy',
        ], $this->actorId);

        $this->seedEmployee();
        // Zákonná evidence ZÁMĚRNĚ bez řádku měsíční evidence zdravotního
        // minima — právě jeho absenci tenhle test měří.
        $this->seedStatutoryEvidence();
        $this->seedZeroOpenings();
        $this->seedWageComponent();
        $this->seedWage(self::GROSS_ABOVE_MINIMUM_MINOR);
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
     * Běžný zaměstnanec s mzdou nad minimem: dopočet nevzniká, evidence je
     * bezpředmětná a běh musí doběhnout. Tohle je ten scénář, kvůli kterému
     * dřív skončil v `manual_review` s issue `health_minimum_month_evidence_missing`.
     */
    public function testEmployeeWithoutAnyMonthEvidenceCompletesTheRun(): void
    {
        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory['result']),
        ));
        self::assertNotContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );

        $health = $statutory['result']['people'][0]['health_insurance'];
        self::assertSame([], $health['issues']);
        self::assertSame('employee', $health['top_up_responsibility']);
        self::assertSame(
            'statutory_default',
            $health['top_up_responsibility_source'],
        );
        self::assertNull($health['top_up_responsibility_evidence_reference']);
        // Dopočet nevznikl, takže se na nic neptáme ani nic neúčtujeme.
        self::assertSame(0, $health['employee_minimum_top_up_minor_units']);
        self::assertSame(0, $health['employer_minimum_top_up_minor_units']);
    }

    /**
     * A teď měsíc, kdy dopočet SKUTEČNĚ vzniká. Bez evidence se nesmí stát ani
     * to, že běh spadne, ani to, že se doplatek tiše hodí zaměstnavateli:
     * § 3 odst. 10 věta první ho ukládá zaměstnanci.
     */
    public function testTopUpWithoutEvidenceIsChargedToTheEmployee(): void
    {
        $this->seedWage(self::GROSS_BELOW_MINIMUM_MINOR);

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory['result']),
        ));
        $health = $statutory['result']['people'][0]['health_insurance'];
        self::assertSame(
            'statutory_default',
            $health['top_up_responsibility_source'],
        );
        self::assertSame(
            self::EXPECTED_TOP_UP_MINOR,
            $health['employee_minimum_top_up_minor_units'],
        );
        self::assertSame(0, $health['employer_minimum_top_up_minor_units']);
    }

    /**
     * Zapsaný řádek default přebíjí a nese jinou auditní stopu: `declared`.
     * Bez tohohle rozlišení by po letech nešlo říct, jestli plátce doplatku
     * někdo doložil, nebo se odvodil ze zákona.
     */
    public function testDeclaredEmployeeResponsibilityIsMarkedAsDeclared(): void
    {
        $this->seedMonthEvidence('employee', null);

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['status']);
        self::assertSame(
            'declared',
            $statutory['result']['people'][0]['health_insurance']
                ['top_up_responsibility_source'],
        );
    }

    public function testEmployerObstacleDoesNotRequireDocumentReference(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_month_evidence
                (supplier_id, employee_id, period_start, top_up_responsibility,
                 top_up_responsibility_evidence_reference)
             VALUES (?, ?, "2026-06-01", "employer_obstacle_verified", ?)'
        );

        $insert->execute([$this->supplierId, $this->employeeId, null]);
        $this->seedWage(self::GROSS_BELOW_MINIMUM_MINOR);

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['status']);
        $health = $statutory['result']['people'][0]['health_insurance'];
        self::assertSame('declared', $health['top_up_responsibility_source']);
        self::assertNull($health['top_up_responsibility_evidence_reference']);
        self::assertSame(0, $health['employee_minimum_top_up_minor_units']);
        self::assertSame(
            self::EXPECTED_TOP_UP_MINOR,
            $health['employer_minimum_top_up_minor_units'],
        );
    }

    /**
     * Prohlásit „nevíme" je pořád možné a pořád to znamená ruční posouzení.
     * Zjednodušení se týká CHYBĚJÍCÍHO záznamu, ne záznamu, který říká, že
     * odpověď nikdo nezná.
     */
    public function testExplicitlyUnverifiedResponsibilityStillBlocksTheRun(): void
    {
        $this->seedMonthEvidence('unverified', null);

        $statutory = $this->calculateRun();

        self::assertSame('manual_review', $statutory['result']['status']);
        self::assertContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );
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
            'lock-health-minimum-run',
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-health-minimum-run',
            $this->actorId,
        );

        return [
            'result' => $calculated->revision['result_snapshot']['statutory'],
            'validation_codes' => array_column(
                $this->repository->validations(
                    $this->supplierId,
                    (int) $calculated->revision['id'],
                ),
                'code',
            ),
        ];
    }

    private function seedMonthEvidence(
        string $responsibility,
        ?string $evidenceReference,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_month_evidence
                (supplier_id, employee_id, period_start, top_up_responsibility,
                 top_up_responsibility_evidence_reference)
             VALUES (?, ?, "2026-06-01", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $responsibility,
            $evidenceReference,
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
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::GROSS_ABOVE_MINIMUM_MINOR,
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
            // Sleva pracujícího důchodce se neuplatňuje; doklad k ní proto být
            // nesmí (chk_pp_social_discount_evidence).
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
            'health-minimum-social-opening',
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
            'health-minimum-tax-opening',
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

    private function seedWage(int $amountMinor): void
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
            'DELETE FROM payroll_inputs
              WHERE supplier_id = ? AND employee_id = ? AND period_start = "2026-06-01"'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'UPDATE payroll_employments SET monthly_gross_minor = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$amountMinor, $this->supplierId, $this->employmentId]);
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
            $amountMinor,
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
            'health-minimum-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
