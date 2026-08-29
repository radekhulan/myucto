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
 * Odměna jednatele projde mzdovým během až do konce.
 *
 * Sestavovač zákonných vstupů posílal u odměny jednatele, u DPČ a u společníka
 * konajícího práci pro s. r. o. natvrdo zařazení `automatic`, které výpočet daně
 * u těchhle vztahů neumí použít — chybí mu odpověď na otázku, jestli sjednaná
 * odměna zakládá účast na nemocenském pojištění (§ 6 odst. 4 písm. b) ZDP).
 * Každý takový vztah proto skončil na `other-withholding-eligibility-unverified`,
 * celý zákonný balík spadl do ručního posouzení a běh dostal blokující validaci
 * `statutory_calculation_manual_review`. Přebít ji nešlo: override pracuje nad
 * validacemi řádků, tohle byl issue zákonného balíku.
 *
 * Tenhle test jede celou cestu (uzamčení vstupů → výpočet → uložení validací)
 * proti databázi a hlídá obojí: že běh s vyplněným prohlášením plátce doběhne,
 * i že bez něj dál poctivě padá do ručního posouzení.
 */
#[Group('integration')]
final class PayrollStatutoryBodyWithholdingRunTest extends TestCase
{
    use IsolatedSupplierTrait;

    /**
     * 4 500 Kč je pro rok 2026 sama rozhodná částka pro účast na nemocenském
     * pojištění. Test § 6 odst. 4 ZDP je ostrý („nedosáhne"), takže na téhle
     * částce se daní zálohou — právě ten scénář srpnový běh neuměl spočítat.
     */
    private const DIRECTOR_REMUNERATION_MINOR = 450_000;

    private Connection $db;
    private PayrollRunCommandService $runs;
    private PayrollRunRepository $repository;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
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
            'source_reference' => 'synthetic:statutory-body-policy',
        ], $this->actorId);

        $this->seedDirector();
        $this->seedStatutoryEvidence();
        $this->seedZeroOpenings();
        $this->seedRemuneration();
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

    public function testDirectorRemunerationAtDecisiveAmountCompletesTheRun(): void
    {
        $this->statePayerStatement('eligible');

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['statutory']['status'], sprintf(
            "Zákonný výpočet nedoběhl. Celý balík:\n%s",
            CanonicalJson::encode($statutory['result']['statutory']),
        ));
        self::assertNotContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );
        // Odměna přesně na rozhodné částce se daní zálohou, ne srážkou —
        // test § 6 odst. 4 ZDP je ostrý.
        self::assertSame(
            'advance',
            $statutory['result']['statutory']['people'][0]['income_tax']
                ['relationships'][0]['regime'],
        );
    }

    /**
     * Druhá větev prohlášení musí projít taky — sjednaná odměna účast na
     * nemocenském pojištění zakládá, takže se daní zálohou vždy.
     */
    public function testDirectorParticipatingInSicknessInsuranceCompletesTheRunToo(): void
    {
        $this->statePayerStatement('ineligible');

        $statutory = $this->calculateRun();

        self::assertSame('calculated', $statutory['result']['statutory']['status']);
        self::assertNotContains(
            'statutory_calculation_manual_review',
            $statutory['validation_codes'],
        );
    }

    /**
     * Bez prohlášení plátce se nic neuhodne. Kdyby tahle půlka chyběla, prošel
     * by i kód, který zařazení tiše dopočítá z výše odměny — a plátce daně by
     * se o rozhodnutí, za které ručí, nedozvěděl.
     */
    public function testDirectorWithoutPayerStatementStillBlocksTheRun(): void
    {
        $this->statePayerStatement('unverified');

        $statutory = $this->calculateRun();

        self::assertSame('manual_review', $statutory['result']['statutory']['status']);
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
            'lock-statutory-body-run',
            $this->actorId,
        );
        $calculated = $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-statutory-body-run',
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

    private function statePayerStatement(string $eligibility): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET other_withholding_eligibility = ?
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$eligibility, $this->supplierId, $this->employmentId]);
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
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, "SYN-JEDNATEL", "statutory_body", "active",
                     "2026-01-01", "2026-01-01", ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::DIRECTOR_REMUNERATION_MINOR,
        ]);
        $this->employmentId = (int) $pdo->lastInsertId();
        // Prohlášení k dani jednatel nepodepsal — bez toho by se § 6 odst. 4
        // ZDP vůbec neuplatnil a scénář by měřil něco jiného.
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
        // Doplatek do minimálního vyměřovacího základu zdravotního pojištění
        // jde k tíži zaměstnance; bez téhle evidence nemá výpočet co použít
        // a celý balík skončí ručním posouzením.
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
            'statutory-body-social-opening',
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
            'statutory-body-tax-opening',
            actorUserId: $this->actorId,
        );
    }

    private function seedRemuneration(): void
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
             VALUES (?, "ODMENA_JEDNATELE", "Odměna jednatele", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => 'ODMENA_JEDNATELE',
            'name' => 'Odměna jednatele',
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
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            self::DIRECTOR_REMUNERATION_MINOR,
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
            'statutory-body-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
