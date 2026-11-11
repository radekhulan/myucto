<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Osoba bez zákonné evidence nezablokuje výpočet ostatním — ale běh jako celek
 * dál schválit ani vykázat nejde.
 *
 * Běh 6/2026 s 225 lidmi bez evidence ukázal 900 zákonných a 225 exekučních
 * blokátorů a nikomu nic nespočítal. Tenhle test jede celou cestu (uzamčení
 * vstupů → výpočet → validace → schválení → příprava JMHZ) proti databázi.
 */
#[Group('integration')]
final class PayrollRunPartialStatutoryBlockTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const WAGE_MINOR = 4_500_000;

    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private PayrollRunCommandService $runs;
    private PayrollRunRepository $repository;
    private PayrollStatutoryAccumulatorRepository $accumulators;
    private JmhzPreparationSnapshotService $jmhz;
    private int $supplierId;
    private int $actorId;
    private int $componentId;
    private int $completeEmployeeId;
    private int $missingEmployeeId;
    private int $missingEmploymentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $runs = $container->get(PayrollRunCommandService::class);
        $repository = $container->get(PayrollRunRepository::class);
        $accumulators = $container->get(PayrollStatutoryAccumulatorRepository::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        $jmhz = $container->get(JmhzPreparationSnapshotService::class);
        if (!$db instanceof Connection
            || !$runs instanceof PayrollRunCommandService
            || !$repository instanceof PayrollRunRepository
            || !$accumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$policies instanceof PayrollEmployerPolicyRepository
            || !$jmhz instanceof JmhzPreparationSnapshotService
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->container = $container;
        $this->db = $db;
        $this->runs = $runs;
        $this->repository = $repository;
        $this->accumulators = $accumulators;
        $this->jmhz = $jmhz;
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
            'source_reference' => 'synthetic:partial-block-policy',
        ], $this->actorId);

        $this->componentId = $this->seedComponent();
        [$this->completeEmployeeId, $completeEmploymentId] =
            $this->seedEmployee('SYN-UPLNY', 'Syntetický Úplný');
        [$this->missingEmployeeId, $this->missingEmploymentId] =
            $this->seedEmployee('SYN-BEZ-EVIDENCE', 'Syntetický Bez Evidence');
        $this->seedStatutoryEvidence($this->completeEmployeeId);
        foreach ([
            [$this->completeEmployeeId, $completeEmploymentId],
            [$this->missingEmployeeId, $this->missingEmploymentId],
        ] as [$employeeId, $employmentId]) {
            $this->seedZeroOpenings($employeeId);
            $this->seedWage($employeeId, $employmentId);
        }
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

    public function testOthersAreCalculatedButTheRunCannotBeApprovedNorReported(): void
    {
        $calculated = $this->calculateNewRun('partial-block');
        $revisionId = (int) $calculated->revision['id'];
        $result = $calculated->revision['result_snapshot'];
        $statutory = $result['statutory'];

        self::assertSame('manual_review', $statutory['status']);
        self::assertSame([], $statutory['result_set_ids']);
        self::assertNull($statutory['employer_social_minor_units']);

        $people = [];
        foreach ($result['people'] as $person) {
            $people[$person['employee_id']] = $person;
        }
        $complete = $people[$this->completeEmployeeId];
        self::assertSame('calculated', $complete['statutory']['status'], sprintf(
            "Úplná osoba se nespočítala. Zákonný výsledek:\n%s",
            CanonicalJson::encode($complete['statutory']),
        ));
        self::assertIsInt($complete['statutory']['net_payable_minor_units']);
        self::assertIsInt($complete['payable_after_enforcement_minor']);

        $missing = $people[$this->missingEmployeeId]['statutory'];
        self::assertSame('manual_review', $missing['status']);
        self::assertNull($missing['net_payable_minor_units']);
        $reference = "employee:{$this->missingEmployeeId}";
        // Chybějící záznam o prohlášení je zákonný stav „nepodepsal" a u
        // nepodepsaného výpočet rezidenci nepotřebuje; daň proto osobu nezastaví.
        self::assertSame(
            [
                "health_insurance:health_coverage_evidence_missing:{$reference}",
                "social_insurance:social_jurisdiction_evidence_missing:{$reference}",
            ],
            $missing['net_pay']['issues'],
        );

        // Jeden blokátor na skutečný problém osoby bez evidence, žádný
        // exekuční — nikdo z nich exekuci nemá.
        $validations = $this->repository->validations($this->supplierId, $revisionId);
        $statutoryRows = array_values(array_filter(
            $validations,
            static fn (array $row): bool =>
                $row['code'] === 'statutory_calculation_manual_review',
        ));
        self::assertCount(2, $statutoryRows);
        self::assertSame(
            [$this->missingEmployeeId],
            array_values(array_unique(array_column($statutoryRows, 'entity_id'))),
        );
        self::assertSame([], array_values(array_filter(
            $validations,
            static fn (array $row): bool => $row['code'] === 'enforcement_manual_review',
        )));

        try {
            $this->runs->approve(
                $this->supplierId,
                (int) $calculated->run['id'],
                (int) $calculated->run['row_version'],
                'approve-partial-block',
                $this->actorId,
            );
            self::fail('Běh s vyřazenou osobou se nesmí dát schválit.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('blokující validace', $exception->getMessage());
        }

        try {
            $this->jmhz->freeze(
                $this->supplierId,
                $revisionId,
                'test',
                'jmhz-partial-block',
                $this->actorId,
            );
            self::fail('Neschválená revize nesmí jít do přípravy JMHZ.');
        } catch (JmhzPreparationSnapshotException $exception) {
            self::assertSame(
                'jmhz_revision_not_current_approved',
                $exception->validationCode,
            );
        }
    }

    /**
     * Výsledek spočítané osoby je bajtově týž, ať je vedle ní osoba bez
     * evidence, nebo ne. Druhá revize vzniká zrušením a znovuotevřením běhu
     * s novým snímkem, ve kterém osoba bez evidence už není.
     */
    public function testCalculatedPersonIsByteIdenticalWithAndWithoutTheBlockedOne(): void
    {
        $withBlocked = $this->calculateNewRun('invariant');
        self::assertSame(
            'manual_review',
            $withBlocked->revision['result_snapshot']['statutory']['status'],
        );

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "archived"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->missingEmploymentId]);
        $runId = (int) $withBlocked->run['id'];
        $cancelled = $this->runs->cancel(
            $this->supplierId,
            $runId,
            (int) $withBlocked->run['row_version'],
            'cancel-invariant',
            $this->actorId,
            'Syntetické zrušení pro porovnání výsledků.',
        );
        $reopened = $this->runs->reopen(
            $this->supplierId,
            $runId,
            (int) $cancelled->run['row_version'],
            'reopen-invariant',
            $this->actorId,
            'Syntetické znovuotevření bez osoby bez evidence.',
        );
        $withoutBlocked = $this->runs->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'calculate-invariant-without',
            $this->actorId,
        );
        $alone = $withoutBlocked->revision['result_snapshot'];
        self::assertSame(
            [$this->completeEmployeeId],
            array_column($alone['people'], 'employee_id'),
        );
        self::assertSame('calculated', $alone['statutory']['status']);

        self::assertSame(
            CanonicalJson::encode($this->statutoryOf(
                $alone,
                $this->completeEmployeeId,
            )),
            CanonicalJson::encode($this->statutoryOf(
                $withBlocked->revision['result_snapshot'],
                $this->completeEmployeeId,
            )),
        );
    }

    /**
     * Agentura s DPP: pojišťovna zapsaná až s pozdějším vztahem (od září)
     * nepokrývá červen. Kontrola před během i validace výpočtu řeknou, od kdy
     * je zapsaná, a hromadný dialog ji nabídne předvyplněnou.
     */
    public function testLaterHealthCoverageIsNamedInFindingsAndOfferedInBulkDialog(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "211",
                     "document:health-insurer-later", "2026-09-01")'
        )->execute([$this->supplierId, $this->missingEmployeeId]);
        $hint = 'Zdravotní pojišťovna 211 je u osoby zapsaná až od 09/2026';

        $readiness = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class);
        self::assertInstanceOf(\MyInvoice\Service\Payroll\Run\PayrollRunReadinessService::class, $readiness);
        $finding = null;
        foreach ($readiness->inspect($this->supplierId, '2026-06-01', '2026-07-15', null)['findings'] as $candidate) {
            if ($candidate['code'] === 'statutory_calculation_manual_review') {
                $finding = $candidate;
            }
        }
        self::assertIsArray($finding);
        $messages = implode("\n", array_column($finding['entities'], 'message'));
        self::assertStringContainsString($hint, $messages);

        $calculated = $this->calculateNewRun('later-coverage');
        $rows = array_values(array_filter(
            $this->repository->validations($this->supplierId, (int) $calculated->revision['id']),
            static fn (array $row): bool => $row['code'] === 'statutory_calculation_manual_review'
                && str_contains((string) $row['message'], 'zdravotní pojištění'),
        ));
        self::assertCount(1, $rows);
        self::assertStringContainsString($hint, (string) $rows[0]['message']);

        $bulk = $this->container->get(\MyInvoice\Service\Payroll\PayrollHealthInsurerBulkAssignment::class);
        self::assertInstanceOf(\MyInvoice\Service\Payroll\PayrollHealthInsurerBulkAssignment::class, $bulk);
        $preview = $bulk->preview($this->supplierId, '2026-06-01');
        $people = array_column($preview['people'], null, 'employee_id');
        self::assertSame('211', $people[$this->missingEmployeeId]['known_insurer_code']);
        self::assertSame('2026-09-01', $people[$this->missingEmployeeId]['known_from']);
    }

    private function calculateNewRun(string $key): \MyInvoice\Service\Payroll\Run\PayrollRunCommandResult
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
            "lock-{$key}",
            $this->actorId,
        );

        return $this->runs->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "calculate-{$key}",
            $this->actorId,
        );
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function statutoryOf(array $result, int $employeeId): array
    {
        foreach ($result['people'] as $person) {
            if ($person['employee_id'] === $employeeId) {
                return $person['statutory'];
            }
        }
        self::fail("Výsledek nemá osobu {$employeeId}.");
    }

    /** @return array{int,int} */
    private function seedEmployee(string $code, string $name): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_primary)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", ?, 1)'
        )->execute([$this->supplierId, $employeeId, $code, self::WAGE_MINOR]);
        $employmentId = (int) $pdo->lastInsertId();
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
        )->execute([$this->supplierId, $employmentId]);

        return [$employeeId, $employmentId];
    }

    private function seedStatutoryEvidence(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not-signed", "2026-01-01", "document:tax-declaration")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_residences
                (supplier_id, employee_id, residence, country_code,
                 effective_from, evidence_reference)
             VALUES (?, ?, "czech-resident", "CZ", "2026-01-01",
                     "document:tax-residence")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "document:health-insurer", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_jurisdictions
                (supplier_id, employee_id, jurisdiction, a1_status, effective_from)
             VALUES (?, ?, "czech_regime_verified", "not_applicable", "2026-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_social_discount_claims
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "not_claimed", "2026-01-01", NULL)'
        )->execute([$this->supplierId, $employeeId]);
    }

    private function seedZeroOpenings(int $employeeId): void
    {
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            "partial-block-social-opening-{$employeeId}",
            actorUserId: $this->actorId,
        );
        $this->accumulators->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
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
            "partial-block-tax-opening-{$employeeId}",
            actorUserId: $this->actorId,
        );
    }

    private function seedComponent(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, "MZDA_SYNTETICKA", "Syntetická mzda", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedWage(int $employeeId, int $employmentId): void
    {
        $json = CanonicalJson::encode([
            'code' => 'MZDA_SYNTETICKA',
            'name' => 'Syntetická mzda',
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
        ]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash,
                 approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-06-01", ?, "manual", "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $employeeId,
            $employmentId,
            $this->componentId,
            self::WAGE_MINOR,
            $json,
            hash('sha256', $json, true),
            $this->actorId,
        ]);
    }

    private function createActor(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic payroll actor", "readonly", "cs", 1)'
        )->execute([
            'partial-block-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
