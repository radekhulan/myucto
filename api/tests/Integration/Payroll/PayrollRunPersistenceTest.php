<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRunsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSpecPackageFixtureTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRunPersistenceTest extends TestCase
{
    use IsolatedSupplierTrait;
    use JmhzSpecPackageFixtureTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRunsAction $action;
    private PayrollRunCommandService $service;
    private PayrollRunCommandService $productionService;
    private PayrollRunRepository $runs;
    private PayrollStatutoryAccumulatorRepository $statutoryAccumulators;
    private PayrollEmployerPolicyRepository $policies;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employerPolicyId;
    private int $employeeId;
    private int $employmentId;
    private int $inputId;
    /** @var list<int> */
    private array $actors;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollRunsAction::class);
        $runs = $container->get(PayrollRunRepository::class);
        $productionService = $container->get(PayrollRunCommandService::class);
        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $service = new PayrollRunCommandService(
            $db,
            $runs,
            $container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $container->get(PayrollRunCalculator::class),
                $container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $container->get(PayrollRunWorkflow::class),
            $container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $statutoryAccumulators = $container->get(
            PayrollStatutoryAccumulatorRepository::class,
        );
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        if (!$db instanceof Connection
            || !$action instanceof PayrollRunsAction
            || !$service instanceof PayrollRunCommandService
            || !$productionService instanceof PayrollRunCommandService
            || !$runs instanceof PayrollRunRepository
            || !$statutoryAccumulators instanceof PayrollStatutoryAccumulatorRepository
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            throw new \RuntimeException('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->action = $action;
        $this->service = $service;
        $this->productionService = $productionService;
        $this->runs = $runs;
        $this->statutoryAccumulators = $statutoryAccumulators;
        $this->policies = $policies;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_run_commands',
            'payroll_run_events',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace MZ-09 neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $this->actors = [
            $this->createActor('calculator'),
            $this->createActor('reviewer'),
            $this->createActor('approver'),
        ];
        // Firma zrovna dokončila nastavení a jde počítat první mzdu. Do
        // `active` se modul překlopí sám (setup-check / první schválení),
        // takže ho sem ručně vkládat nemusíme — mzdové běhy jedou i v `setup`.
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            $pdo->prepare(
                'INSERT INTO payroll_module_state
                    (supplier_id, status, start_period, activated_by, activated_at)
                 VALUES (?, "setup", "2026-01-01", ?, NOW())'
            )->execute([$supplierId, $this->actors[0]]);
        }
        $policy = $this->policies->create(
            $this->supplierId,
            $this->employerPolicyInput(),
            $this->actors[0],
        );
        $this->employerPolicyId = (int) $policy['id'];
        [$this->employeeId, $this->employmentId] = $this->employment();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_person_month_evidence
                (supplier_id, employee_id, period_start,
                 claim_register_evidence_complete,
                 dependants_evidence_complete, spouse_evidence_complete,
                 pension_evidence, updated_by)
             VALUES (?, ?, "2026-06-01", 1, 1, 1, "none", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
        ]);
        $this->inputId = $this->approvedInput(120_000, 'BASE', 'manual');
    }

    public function testSessionApiCreatesListsAndLocksRunIdempotently(): void
    {
        $role = new EffectiveRole(
            90,
            'Syntetická mzdová účetní',
            'staff',
            true,
            [
                'payroll' => AccessLevel::READ->value,
                'payroll.inputs.write' => AccessLevel::WRITE->value,
                'payroll.calculate' => AccessLevel::WRITE->value,
                'payroll.review' => AccessLevel::WRITE->value,
                'payroll.approve' => AccessLevel::WRITE->value,
                'payroll.reopen' => AccessLevel::WRITE->value,
            ],
        );
        $createdResponse = $this->action->create(
            $this->apiRequest('POST', '/api/payroll/runs', $role)
                ->withParsedBody([
                    'period_start' => '2026-06-01',
                    'payment_date' => '2026-07-15',
                ]),
            new Response(),
        );
        self::assertSame(201, $createdResponse->getStatusCode());
        $created = $this->json($createdResponse)['run'];
        self::assertSame('2026-07-15', $created['payment_date']);
        self::assertSame('draft', $created['status']);

        $listResponse = $this->action->list(
            $this->apiRequest('GET', '/api/payroll/runs?period=2026-06', $role)
                ->withQueryParams(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $listResponse->getStatusCode());
        $runs = $this->json($listResponse)['runs'];
        self::assertCount(1, $runs);
        self::assertSame('2026-07-15', $runs[0]['payment_date']);
        self::assertContains('lock_inputs', $runs[0]['available_commands']);

        $request = $this->apiRequest(
            'POST',
            "/api/payroll/runs/{$created['id']}/commands/lock_inputs",
            $role,
        )->withHeader('Idempotency-Key', 'api-lock-synthetic-run')
            ->withParsedBody(['row_version' => $created['row_version']]);
        $lockedResponse = $this->action->command(
            $request,
            new Response(),
            ['id' => (string) $created['id'], 'command' => 'lock_inputs'],
        );
        self::assertSame(200, $lockedResponse->getStatusCode());
        $locked = $this->json($lockedResponse);
        self::assertSame('inputs_locked', $locked['run']['status']);
        self::assertFalse($locked['idempotent_replay']);

        $replayResponse = $this->action->command(
            $request,
            new Response(),
            ['id' => (string) $created['id'], 'command' => 'lock_inputs'],
        );
        self::assertSame(200, $replayResponse->getStatusCode());
        self::assertTrue($this->json($replayResponse)['idempotent_replay']);

        $bearerResponse = $this->action->list(
            $this->apiRequest(
                'GET',
                '/api/payroll/runs',
                $role,
                'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearerResponse->getStatusCode());
        self::assertSame(
            'session_required',
            $this->json($bearerResponse)['error']['code'],
        );
    }

    public function testCreateReturnsConflictWhenLegacyPayrollOwnsPeriod(): void
    {
        $role = new EffectiveRole(
            93,
            'Syntetická mzdová účetní',
            'staff',
            true,
            ['payroll.inputs.write' => AccessLevel::WRITE->value],
        );
        $this->container->get(PayrollPeriodOwnershipService::class)->claimLegacy(
            $this->supplierId,
            2026,
            6,
            9001,
            $this->actors[0],
        );

        $response = $this->action->create(
            $this->apiRequest('POST', '/api/payroll/runs', $role)
                ->withParsedBody([
                    'period_start' => '2026-06-01',
                    'payment_date' => '2026-07-15',
                ]),
            new Response(),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_period_owned', $this->json($response)['error']['code']);
    }

    public function testSessionHistoryReturnsOnlySafeRevisionSummariesAndDirectDiff(): void
    {
        $role = new EffectiveRole(
            91,
            'Syntetická mzdová účetní',
            'staff',
            true,
            ['payroll' => AccessLevel::READ->value],
        );
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $this->approvedInput(10_000, 'HISTORY_CORRECTION', 'correction');
        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'history-request-correction',
            $this->actors[2],
            'Syntetická oprava pro historii.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'history-reopen-correction',
            $this->actors[1],
            'Syntetická oprava pro historii.',
        );
        $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'history-calculate-correction',
            $this->actors[0],
        );

        $response = $this->action->history(
            $this->apiRequest('GET', "/api/payroll/runs/{$runId}/history", $role),
            new Response(),
            ['id' => (string) $runId],
        );

        self::assertSame(200, $response->getStatusCode());
        $history = $this->json($response)['history'];
        self::assertSame($runId, $history['run_id']);
        self::assertCount(2, $history['revisions']);
        self::assertNotEmpty($history['events']);
        self::assertSame([
            'id',
            'revision_no',
            'previous_revision_id',
            'revision_kind',
            'status',
            'created_at',
            'calculated_at',
            'reviewed_at',
            'approved_at',
            'ruleset_manifest_hash',
            'input_snapshot_hash',
            'result_snapshot_hash',
            'totals',
            'diff_from_previous',
        ], array_keys($history['revisions'][0]));
        self::assertSame([
            'cash_payable_minor',
            'enforcement_withheld_minor',
            'payable_after_enforcement_minor',
        ], array_keys($history['revisions'][0]['totals']));
        self::assertSame('regular', $history['revisions'][0]['revision_kind']);
        self::assertNull($history['revisions'][0]['diff_from_previous']);
        $correctionEvent = array_values(array_filter(
            $history['events'],
            static fn (array $event): bool => $event['reason']
                === 'Syntetická oprava pro historii.',
        ));
        self::assertCount(2, $correctionEvent);
        self::assertSame('Synthetic approver', $correctionEvent[0]['actor_name']);

        $diff = $history['revisions'][1]['diff_from_previous'];
        self::assertTrue($diff['input_changed']);
        self::assertFalse($diff['ruleset_changed']);
        self::assertTrue($diff['result_changed']);
        foreach ([
            'cash_payable_minor',
            'enforcement_withheld_minor',
            'payable_after_enforcement_minor',
        ] as $total) {
            self::assertSame(
                ['before', 'after', 'delta'],
                array_keys($diff['totals'][$total]),
            );
            self::assertSame(
                $diff['totals'][$total]['after'] - $diff['totals'][$total]['before'],
                $diff['totals'][$total]['delta'],
            );
        }

        $encoded = json_encode($history, JSON_THROW_ON_ERROR);
        foreach ([
            'input_snapshot_json',
            'result_snapshot_json',
            'input_snapshot',
            'result_snapshot',
            'metadata_json',
            'idempotency_key_hash',
            'calculated_by',
            'reviewed_by',
            'approved_by',
            'actor_user_id',
        ] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $encoded);
        }
    }

    public function testHistoryReturnsNotFoundForForeignTenant(): void
    {
        $role = new EffectiveRole(
            92,
            'Syntetická mzdová účetní',
            'staff',
            true,
            ['payroll' => AccessLevel::READ->value],
        );
        $run = $this->createRun();
        $runId = (int) $run['id'];

        $response = $this->action->history(
            $this->apiRequest('GET', "/api/payroll/runs/{$runId}/history", $role)
                ->withAttribute(
                    SupplierScopeMiddleware::ATTR_CURRENT_ID,
                    $this->otherSupplierId,
                ),
            new Response(),
            ['id' => (string) $runId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testHistoryRequiresPayrollReadAndSessionAuthentication(): void
    {
        $run = $this->createRun();
        $runId = (int) $run['id'];
        $withoutPayroll = new EffectiveRole(
            93,
            'Bez mezd',
            'staff',
            true,
            [],
        );
        $forbidden = $this->action->history(
            $this->apiRequest(
                'GET',
                "/api/payroll/runs/{$runId}/history",
                $withoutPayroll,
            ),
            new Response(),
            ['id' => (string) $runId],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $payrollRead = new EffectiveRole(
            94,
            'Mzdové čtení',
            'staff',
            true,
            ['payroll' => AccessLevel::READ->value],
        );
        $bearer = $this->action->history(
            $this->apiRequest(
                'GET',
                "/api/payroll/runs/{$runId}/history",
                $payrollRead,
                'bearer',
            ),
            new Response(),
            ['id' => (string) $runId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
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

    public function testPaymentDateIsRequiredAtDatabaseBoundary(): void
    {
        $this->db->pdo()->exec('SET SESSION check_constraint_checks = 1');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2032-01-01", NULL)'
        );

        $this->expectException(PDOException::class);
        $stmt->execute([$this->supplierId]);
    }

    public function testInputSnapshotFreezesStatutoryPeriodAndPersonEvidence(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from,
                 evidence_reference)
             VALUES (?, ?, "signed", "2026-01-01",
                     "document:synthetic-tax-declaration")'
        )->execute([$this->supplierId, $this->employeeId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-statutory-evidence',
            $this->actors[0],
        );
        $snapshot = $locked->revision['input_snapshot'];

        self::assertSame('payroll-run-input.v2', $snapshot['schema_version']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['tax_calculation_date']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['social_calculation_date']);
        self::assertSame('2026-06-30', $snapshot['statutory_period']['health_calculation_date']);
        self::assertSame('2026-07-15', $snapshot['statutory_period']['payment_date']);
        self::assertSame(
            1,
            $snapshot['people'][0]['employments'][0]['term']['row_version'],
        );
        self::assertSame(
            'signed',
            $snapshot['people'][0]['statutory_evidence']['income_tax']['declaration']['status'],
        );

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_declarations
                SET status = "not-signed",
                    evidence_reference = "document:synthetic-revocation"
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([$this->supplierId, $this->employeeId]);

        self::assertSame(
            'signed',
            $this->runs->revision(
                $this->supplierId,
                (int) $locked->revision['id'],
            )['input_snapshot']['people'][0]['statutory_evidence']
                ['income_tax']['declaration']['status'],
        );
    }

    public function testInputSnapshotFreezesVerifiedAnnualAccumulatorStates(): void
    {
        $socialOpeningId = $this->statutoryAccumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:social-opening',
            ['verified_zero' => true],
            'snapshot-social-opening',
            actorUserId: $this->actors[0],
        );
        $this->statutoryAccumulators->appendOpeningBalance(
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
            'snapshot-tax-opening',
            actorUserId: $this->actors[0],
        );

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-statutory-accumulators',
            $this->actors[0],
        );
        $accumulators = $locked->revision['input_snapshot']['people'][0]
            ['statutory_accumulators'];

        self::assertSame(
            'payroll-person-statutory-accumulators.v1',
            $accumulators['schema_version'],
        );
        self::assertSame('verified', $accumulators['social_insurance']['status']);
        self::assertSame(
            0,
            $accumulators['social_insurance']['state']['totals']
                ['assessment_base_minor_units'],
        );
        self::assertSame('verified', $accumulators['income_tax']['status']);
        self::assertSame(
            0,
            $accumulators['income_tax']['state']['totals']['completed_months'],
        );

        $this->statutoryAccumulators->appendOpeningBalance(
            $this->supplierId,
            $this->employeeId,
            2026,
            'social_insurance',
            ['assessment_base_minor_units' => 100],
            'synthetic:social-opening-correction',
            ['verified_zero' => false],
            'snapshot-social-opening-correction',
            $socialOpeningId,
            $this->actors[0],
        );

        self::assertSame(
            0,
            $this->runs->revision(
                $this->supplierId,
                (int) $locked->revision['id'],
            )['input_snapshot']['people'][0]['statutory_accumulators']
                ['social_insurance']['state']['totals']
                ['assessment_base_minor_units'],
        );
    }

    public function testInputSnapshotFreezesDeductionsAndPayoutRules(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_deduction_agreements
                (supplier_id, employee_id, agreement_reference, title,
                 deduction_kind, status, priority_no, requested_minor,
                 total_limit_minor, withheld_total_minor, valid_from,
                 created_by, updated_by)
             VALUES (?, ?, "SYNTHETIC-MEAL", "Syntetická srážka",
                     "meal", "active", 20, 2500, 10000, 3000,
                     "2026-01-01", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $agreementId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account",
                     ?, "••••0005/0100", 10000, "2026-01-01", 1,
                     "user_verified", "2026-05-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            hash('sha256', 'synthetic-account', true),
            $this->actors[0],
        ]);
        $accountId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, destination_reference, allocation_kind,
                 priority_no, is_active)
             VALUES (?, ?, "SYNTHETIC-REMAINDER", "bank",
                     ?, "remainder", 100, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            "account:{$accountId}",
        ]);
        $payoutRuleId = (int) $this->db->pdo()->lastInsertId();

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-deductions-and-payout-rules',
            $this->actors[0],
        );
        $person = $locked->revision['input_snapshot']['people'][0];

        self::assertSame($agreementId, $person['deduction_agreements'][0]['id']);
        self::assertSame(3000, $person['deduction_agreements'][0]['withheld_total_minor']);
        self::assertSame($payoutRuleId, $person['payout_rules'][0]['id']);
        self::assertSame('remainder', $person['payout_rules'][0]['allocation_kind']);
        self::assertSame([
            'allocation_basis_points' => 10000,
            'bank_account_hash' => hash('sha256', 'synthetic-account'),
            'bank_account_masked' => '••••0005/0100',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'id' => $accountId,
            'label' => 'Syntetický účet',
            'row_version' => 1,
            'verification_source' => 'user_verified',
            'verified_by' => $this->actors[0],
            'verified_on' => '2026-05-01',
        ], $person['payout_accounts'][0]);

        $this->db->pdo()->prepare(
            'UPDATE payroll_deduction_agreements
                SET withheld_total_minor = 4000
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $agreementId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_payout_rules SET is_active = 0
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $payoutRuleId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_ciphertext = "enc:v2:changed-account",
                    bank_account_hash = ?,
                    bank_account_masked = "••••1116/0100",
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            hash('sha256', 'changed-account', true),
            $this->supplierId,
            $accountId,
        ]);

        $persisted = $this->runs->revision(
            $this->supplierId,
            (int) $locked->revision['id'],
        )['input_snapshot']['people'][0];
        self::assertSame(
            3000,
            $persisted['deduction_agreements'][0]['withheld_total_minor'],
        );
        self::assertSame($payoutRuleId, $persisted['payout_rules'][0]['id']);
        self::assertSame(
            hash('sha256', 'synthetic-account'),
            $persisted['payout_accounts'][0]['bank_account_hash'],
        );
        self::assertSame(
            '2026-05-01',
            $persisted['payout_accounts'][0]['verified_on'],
        );
    }

    public function testInputSnapshotFreezesEmployerPostingPolicy(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-employer-posting-policy',
            $this->actors[0],
        );

        self::assertSame([
            'automatic_posting_enabled' => true,
            'id' => $this->employerPolicyId,
            'row_version' => 1,
        ], $locked->revision['input_snapshot']['employer_policy']);

        $this->policies->update(
            $this->supplierId,
            $this->employerPolicyId,
            $this->employerPolicyInput(false),
            1,
            $this->actors[0],
        );

        self::assertSame([
            'automatic_posting_enabled' => true,
            'id' => $this->employerPolicyId,
            'row_version' => 1,
        ], $this->runs->revision(
            $this->supplierId,
            (int) $locked->revision['id'],
        )['input_snapshot']['employer_policy']);
    }

    public function testMissingEffectiveEmployerPolicyFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'chybí účinná zaměstnavatelská politika',
        );
        $this->container->get(PayrollRunSnapshotBuilder::class)->build(
            $this->otherSupplierId,
            '2026-06-01',
            '2026-07-15',
        );
    }

    public function testInputSnapshotKeepsEmploymentArchivedAfterPayrollPeriod(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status,
                 to_status, effective_on, created_by)
             VALUES
                (?, ?, "created", NULL, "active", "2026-01-01", ?),
                (?, ?, "status_changed", "active", "archived", "2026-07-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "archived"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build(
                $this->supplierId,
                '2026-06-01',
                '2026-07-15',
            );

        self::assertSame(
            $this->employmentId,
            $snapshot->data['people'][0]['employments'][0]['employment']['id'],
        );
        self::assertSame(
            'active',
            $snapshot->data['people'][0]['employments'][0]['employment']['status'],
        );
    }

    public function testInputSnapshotPinsEffectiveJmhzEmploymentEvidence(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET work_place = "Hlavní město Praha",
                    jmhz_workplace_municipality_code = "554782",
                    jmhz_workplace_country_code = "CZ",
                    jmhz_external_codebook_overlay_key = ?,
                    jmhz_external_codebook_manifest_sha256 = ?,
                    jmhz_apz_contribution_status = "yes",
                    jmhz_apz_instrument_code = "4",
                    jmhz_functional_benefits_status = "no",
                    jmhz_temporary_assignment_status = "unverified",
                    jmhz_orchard_discount_eligible = 0,
                    jmhz_specific_legal_fact_applies = 0,
                    jmhz_ozp_employment_support_applies = 0,
                    jmhz_deep_mining_work_applies = 1
                    , activity_code = "1"
                    , jmhz_relationship_detail_code = "1"
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute([
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
            $this->supplierId,
            $this->employmentId,
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $term = $snapshot->data['people'][0]['employments'][0]['term'];

        self::assertSame('Hlavní město Praha', $term['work_place']);
        self::assertSame('554782', $term['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $term['jmhz_workplace_country_code']);
        self::assertSame(
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
            $term['jmhz_external_codebook_manifest_sha256'],
        );
        self::assertTrue($term['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY,
            $term['jmhz_validation_external_codebook_overlay_key'],
        );
        self::assertSame('yes', $term['jmhz_apz_contribution_status']);
        self::assertSame('4', $term['jmhz_apz_instrument_code']);
        self::assertSame('no', $term['jmhz_functional_benefits_status']);
        self::assertSame('unverified', $term['jmhz_temporary_assignment_status']);
        self::assertSame('1', $term['activity_code']);
        self::assertSame('1', $term['jmhz_relationship_detail_code']);
        self::assertSame([
            'source_term_id' => $term['id'],
            'source_term_row_version' => $term['row_version'],
            'orchard_discount_eligible' => false,
            'specific_legal_fact_applies' => false,
            'ozp_employment_support_applies' => false,
            'deep_mining_work_applies' => true,
        ], $snapshot->data['people'][0]['employments'][0]['ordinary_evidence_profile']);

        $augustPaidInSeptemberSnapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-08-01', '2026-09-15');
        $augustPaidInSeptemberTerm =
            $augustPaidInSeptemberSnapshot->data['people'][0]['employments'][0]['term'];
        self::assertTrue($augustPaidInSeptemberTerm['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY,
            $augustPaidInSeptemberTerm['jmhz_validation_external_codebook_overlay_key'],
        );

        $septemberSnapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-09-01', '2026-09-30');
        $septemberTerm = $septemberSnapshot->data['people'][0]['employments'][0]['term'];
        self::assertTrue($septemberTerm['jmhz_external_codebooks_verified_for_period']);
        self::assertSame(
            JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY,
            $septemberTerm['jmhz_validation_external_codebook_overlay_key'],
        );
    }

    public function testInputSnapshotPinsEffectivePrimaryEmploymentAndApprovedAverageEarning(): void
    {
        $inputTrace = CanonicalJson::encode(['synthetic' => true]);
        $inputHash = hash('sha256', $inputTrace);
        $rulesetHash = str_repeat('a', 64);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year,
                 applicable_quarter, revision_no, source_kind,
                 decisive_from, decisive_to, gross_earnings_minor,
                 longer_period_allocated_minor, worked_minutes, worked_days,
                 average_hourly_minor, rationale, support_status, status,
                 ruleset_id, ruleset_hash, input_hash, input_trace,
                 created_by, approved_by, approved_at)
             VALUES (?, ?, 2026, 2, 1, "probable",
                     "2026-01-01", "2026-03-31", 0, 0, 0, 0,
                     27550, "Syntetický pravděpodobný výdělek", "supported",
                     "approved", "synthetic-average-v1", ?, UNHEX(?), ?,
                     ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $rulesetHash,
            $inputHash,
            $inputTrace,
            $this->actors[0],
            $this->actors[0],
        ]);
        $averageId = (int) $this->db->pdo()->lastInsertId();

        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET effective_to = "2026-08-31"
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from = "2026-01-01"'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, "2026-09-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 0)'
        )->execute([$this->supplierId, $this->employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET is_primary = 0
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $entry = $snapshot->data['people'][0]['employments'][0];

        self::assertTrue($entry['employment']['is_primary']);
        self::assertSame([
            'id' => $averageId,
            'row_version' => 1,
            'applicable_year' => 2026,
            'applicable_quarter' => 2,
            'revision_no' => 1,
            'source_kind' => 'probable',
            'average_hourly_minor' => 27550,
            'support_status' => 'supported',
            'status' => 'approved',
            'ruleset_id' => 'synthetic-average-v1',
            'ruleset_hash' => $rulesetHash,
            'input_hash' => $inputHash,
        ], $entry['average_earning']);

        $future = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-09-01', '2026-10-15');
        self::assertFalse(
            $future->data['people'][0]['employments'][0]['employment']['is_primary'],
        );
    }

    public function testInputSnapshotPinsImmutableJmhzWorkMonthCoreRevision(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 2, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $timeMonthId = (int) $this->db->pdo()->lastInsertId();
        $sourceJson = CanonicalJson::encode([
            'schema_version' => 'jmhz-work-month-core.v1',
            'synthetic_source' => true,
        ]);
        $sourceHash = hash('sha256', $sourceJson);
        $specification = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
        ];
        $specPackageId = $this->installDefaultJmhzSpecPackage($this->db);
        $values = [
            'standard_fund_millihours' => 168000,
            'agreed_fund_millihours' => 168000,
            'weekly_work_centihours' => 4000,
            'evidence_days' => 30,
            'worked_millihours' => 160000,
        ];
        $provenance = [
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
        ];
        $confirmationNote = 'Potvrzeno syntetickým integračním testem.';
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month-core.v1',
            'specification' => $specification,
            'source_snapshot_sha256' => $sourceHash,
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $confirmationNote,
        ];
        $summaryHash = hash('sha256', CanonicalJson::encode($summaryPayload));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no,
                 period_start, spec_package_id, spec_manifest_sha256,
                 scenario_catalog_key, scenario_manifest_sha256,
                 derivation_version, source_snapshot_json,
                 source_snapshot_sha256, standard_fund_millihours,
                 agreed_fund_millihours, weekly_work_centihours, evidence_days,
                 worked_millihours, confirmation_note, provenance_json,
                 summary_sha256, approved_by, approved_at)
             VALUES (?, ?, ?, 1, "2026-06-01", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $timeMonthId,
            $specPackageId,
            $specification['spec_manifest_sha256'],
            $specification['scenario_catalog_key'],
            $specification['scenario_manifest_sha256'],
            'jmhz-work-month-core.v1',
            $sourceJson,
            $sourceHash,
            ...array_values($values),
            $confirmationNote,
            CanonicalJson::encode($provenance),
            $summaryHash,
            $this->actors[0],
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $timeMonth = $snapshot->data['people'][0]['employments'][0]['time_month'];

        self::assertSame('frozen_core', $timeMonth['jmhz_work_summary_status']);
        self::assertSame($summaryHash, $timeMonth['jmhz_work_summary']['summary_sha256']);
        self::assertSame($sourceHash, $timeMonth['jmhz_work_summary']['source_snapshot_sha256']);
        self::assertSame($values, $timeMonth['jmhz_work_summary']['values']);
    }

    public function testInputSnapshotPinsConditionalJmhzWorkMonthRevision(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 2, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $timeMonthId = (int) $this->db->pdo()->lastInsertId();
        $sourceJson = CanonicalJson::encode([
            'schema_version' => 'jmhz-work-month.v2',
            'synthetic_source' => true,
        ]);
        $sourceHash = hash('sha256', $sourceJson);
        $specification = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
        $specPackageId = $this->installDefaultJmhzSpecPackage($this->db);
        $values = [
            'standard_fund_millihours' => 168000,
            'agreed_fund_millihours' => 168000,
            'weekly_work_centihours' => 4000,
            'evidence_days' => 30,
            'worked_millihours' => 80000,
            'unworked_total_millihours' => 80000,
            'unworked_paid_millihours' => 0,
            'dpn_without_employer_compensation_millihours' => null,
            'dpn_with_employer_compensation_millihours' => 80000,
            'vacation_millihours' => null,
            'care_millihours' => null,
            'employee_obstacle_paid_millihours' => 80000,
            'employer_obstacle_millihours' => null,
        ];
        $interactions = ['IN07' => true, 'IN08' => true];
        $provenance = [
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
            'validated_controls' => [23, 144, 145, 286],
        ];
        $confirmationNote = 'Potvrzeno syntetickým integračním testem.';
        $summaryPayload = [
            'derivation_version' => 'jmhz-work-month.v2',
            'specification' => $specification,
            'source_snapshot_sha256' => $sourceHash,
            'conditional_blocks_confirmed' => true,
            'interactions' => $interactions,
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $confirmationNote,
        ];
        $summaryHash = hash('sha256', CanonicalJson::encode($summaryPayload));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_work_month_revisions
                (supplier_id, employment_id, time_month_id, time_month_revision_no,
                 period_start, spec_package_id, spec_manifest_sha256,
                 scenario_catalog_key, scenario_manifest_sha256,
                 control_catalog_key, control_manifest_sha256,
                 derivation_version, source_snapshot_json, source_snapshot_sha256,
                 standard_fund_millihours, agreed_fund_millihours,
                 weekly_work_centihours, evidence_days, worked_millihours,
                 conditional_blocks_confirmed, unworked_hours_occurred,
                 work_obstacles_occurred, unworked_total_millihours,
                 unworked_paid_millihours,
                 dpn_without_employer_compensation_millihours,
                 dpn_with_employer_compensation_millihours, vacation_millihours,
                 care_millihours, employee_obstacle_paid_millihours,
                 employer_obstacle_millihours, confirmation_note, provenance_json,
                 summary_sha256, approved_by, approved_at)
             VALUES (?, ?, ?, 1, "2026-06-01", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     1, 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $timeMonthId,
            $specPackageId,
            $specification['spec_manifest_sha256'],
            $specification['scenario_catalog_key'],
            $specification['scenario_manifest_sha256'],
            $specification['control_catalog_key'],
            $specification['control_manifest_sha256'],
            'jmhz-work-month.v2',
            $sourceJson,
            $sourceHash,
            ...array_values(array_slice($values, 0, 5, true)),
            ...array_values(array_slice($values, 5, null, true)),
            $confirmationNote,
            CanonicalJson::encode($provenance),
            $summaryHash,
            $this->actors[0],
        ]);

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, '2026-06-01', '2026-07-15');
        $timeMonth = $snapshot->data['people'][0]['employments'][0]['time_month'];

        self::assertSame('frozen_work_summary', $timeMonth['jmhz_work_summary_status']);
        self::assertSame($summaryHash, $timeMonth['jmhz_work_summary']['summary_sha256']);
        self::assertSame($interactions, $timeMonth['jmhz_work_summary']['interactions']);
        self::assertSame($values, $timeMonth['jmhz_work_summary']['values']);
        self::assertSame(
            JmhzControlSourceCatalog::MANIFEST_SHA256,
            $timeMonth['jmhz_work_summary']['specification']['control_manifest_sha256'],
        );
    }

    public function testPostTerminationInputKeepsEndedRelationshipInSnapshot(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "ended", end_date = "2026-05-31"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-post-termination-income',
            $this->actors[0],
        );
        $employment = $locked->revision['input_snapshot']['people'][0]
            ['employments'][0]['employment'];

        self::assertSame($this->employmentId, $employment['id']);
        self::assertSame('2026-05-31', $employment['end_date']);
        self::assertSame(
            $this->inputId,
            $locked->revision['input_snapshot']['people'][0]
                ['employments'][0]['inputs'][0]['id'],
        );
    }

    public function testSnapshotRemainsStableAndFourEyeWorkflowIsAudited(): void
    {
        $approvedPosting = $this->createMock(
            PayrollApprovedRevisionPostingService::class,
        );
        $this->service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('inputs_locked', $locked->run['status']);
        self::assertSame('snapshot', $locked->revision['status']);
        self::assertSame(
            'locked',
            $this->scalar(
                'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $this->inputId],
            ),
        );
        $inputHash = $locked->revision['input_snapshot_hash'];

        $this->db->pdo()->prepare(
            'UPDATE payroll_employees SET full_name = "Changed after lock"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 999999
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-stable-snapshot',
            $this->actors[0],
        );
        self::assertSame('calculated', $calculated->run['status']);
        self::assertSame(
            120_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        self::assertSame($inputHash, $calculated->revision['input_snapshot_hash']);

        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-four-eyes',
            $this->actors[1],
        );
        $approvedPosting->expects(self::once())
            ->method('post')
            ->with(
                $this->supplierId,
                (int) $reviewed->revision['id'],
                $reviewed->revision['input_snapshot'],
                $reviewed->revision['result_snapshot'],
                $this->actors[2],
            )
            ->willReturn([]);
        $approved = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-four-eyes',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame($this->actors[0], $approved->revision['calculated_by']);
        self::assertSame($this->actors[1], $approved->revision['reviewed_by']);
        self::assertSame($this->actors[2], $approved->revision['approved_by']);

        $events = $this->runs->events($this->supplierId, (int) $run['id']);
        self::assertSame(
            ['created', 'lock_inputs', 'calculate', 'review', 'approve'],
            array_column($events, 'event_type'),
        );
        self::assertCount(4, array_filter(
            $events,
            static fn (array $event): bool =>
                isset($event['metadata']['idempotency_key_hash']),
        ));
        self::assertStringNotContainsString(
            'approve-four-eyes',
            CanonicalJson::encode(['events' => $events]),
        );

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions SET input_snapshot_hash = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            str_repeat('0', 64),
            $this->supplierId,
            (int) $approved->revision['id'],
        ]);
    }

    public function testApprovalRollsBackWhenAutomaticPostingFails(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-posting-failure',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-posting-failure',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-before-posting-failure',
            $this->actors[1],
        );

        $approvedPosting = $this->createMock(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->expects(self::once())
            ->method('post')
            ->willThrowException(new \RuntimeException(
                'Synthetic posting failure.',
            ));
        $service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );

        try {
            $service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-with-posting-failure',
                $this->actors[2],
            );
            self::fail('Selhání automatického zaúčtování musí zrušit schválení.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic posting failure.', $e->getMessage());
        }

        $persistedRun = $this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        );
        $persistedRevision = $this->runs->revision(
            $this->supplierId,
            (int) $reviewed->revision['id'],
        );
        self::assertSame('reviewed', $persistedRun['status']);
        self::assertSame(
            (int) $reviewed->run['row_version'],
            (int) $persistedRun['row_version'],
        );
        self::assertSame('reviewed', $persistedRevision['status']);
        self::assertNull($persistedRevision['approved_by']);
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ?
                    AND command_name = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ?
                    AND event_type = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    public function testApprovalInOuterTransactionOnlyPersistsDocumentQueueIntent(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-nested-approval',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-nested-approval',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-before-nested-approval',
            $this->actors[1],
        );

        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $approvedPayslips = $this->createMock(
            ApprovedRevisionPayslipBatchService::class,
        );
        $approvedPayslips->expects(self::never())->method('beginStorageScope');
        $approvedPayslips->expects(self::never())->method('generate');
        $approvedPayslips->expects(self::never())
            ->method('commitStorageScope');
        $approvedPayslips->expects(self::never())->method('cleanupStorageScope');
        $service = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
            $approvedPayslips,
            null,
            null,
            null,
            null,
            $this->container->get(
                \MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService::class,
            ),
        );

        $approved = $service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-in-outer-transaction',
            $this->actors[2],
        );

        $persistedRun = $this->runs->find(
            $this->supplierId,
            (int) $run['id'],
        );
        $persistedRevision = $this->runs->revision(
            $this->supplierId,
            (int) $reviewed->revision['id'],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame('approved', $persistedRun['status']);
        self::assertSame('approved', $persistedRevision['status']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_document_batches
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $reviewed->revision['id']],
            ),
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_generated_documents
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $reviewed->revision['id']],
            ),
        );
    }

    public function testProductionPipelineBlocksApprovalUntilRulesetIsActive(): void
    {
        $approvedPosting = $this->createStub(
            PayrollApprovedRevisionPostingService::class,
        );
        $approvedPosting->method('post')->willReturn([]);
        $productionService = new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            $this->container->get(PayrollRunCalculationPipeline::class),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $approvedPosting,
        );
        $run = $productionService->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
        $locked = $productionService->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'production-ruleset-lock',
            $this->actors[0],
        );
        $calculated = $productionService->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'production-ruleset-calculate',
            $this->actors[0],
        );

        self::assertSame(
            'manual_review',
            $calculated->revision['result_snapshot']['statutory']['status'],
        );
        self::assertContains(
            'statutory_calculation_manual_review',
            array_column(
                $this->runs->validations(
                    $this->supplierId,
                    (int) $calculated->revision['id'],
                ),
                'code',
            ),
        );

        $reviewed = $productionService->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'production-ruleset-review',
            $this->actors[1],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('blokující validace');
        $productionService->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'production-ruleset-approve',
            $this->actors[2],
        );
    }

    public function testApprovedRunPersistsFrozenEnforcementAndReducesPayable(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 4000000
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);
        $this->approvedInput(
            1_000_000,
            'CESTOVNI_NAHRADA',
            'manual',
            'excluded',
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified,
                 created_by, updated_by)
             VALUES (?, ?, "synthetic-runtime-case", "enforcement",
                     "withhold_and_hold", "2026-05-01", 1, 1, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $caseId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 priority_date, first_payer_delivered_on, order_issued_on, legal_title_verified,
                 order_or_notice_delivered, priority_classification_verified,
                 agreement_verified, due_monetary_claim_verified)
             VALUES (?, ?, "synthetic-runtime-claim", "synthetic-runtime-order",
                     "statutory", "non_priority", 10000000,
                     "2026-05-01", "2026-05-01", "2026-04-30", 1, 1, 1, 0, 1)'
        )->execute([$this->supplierId, $caseId]);

        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'runtime-enforcement-lock',
            $this->actors[0],
        );
        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_enforcement_claims
                    SET outstanding_minor_units = 0
                  WHERE supplier_id = ? AND case_id = ?'
            )->execute([$this->supplierId, $caseId]);
            self::fail(
                'Pohledávku použitou ve zmrazeném vstupu nesmí jít změnit.',
            );
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'retained footprint',
                $exception->getMessage(),
            );
        }
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'runtime-enforcement-calculate',
            $this->actors[0],
        );
        $person = $calculated->revision['result_snapshot']['people'][0];
        $enforcement = $person['enforcement']['result'];
        $enforcementInput = $person['enforcement']['input'];
        self::assertSame('supported', $enforcement['status']);
        self::assertSame(
            4_000_000,
            $enforcementInput['income']['garnishable_minor_units'],
        );
        self::assertSame(
            1_000_000,
            $enforcementInput['income']['excluded_minor_units'],
        );
        self::assertGreaterThan(0, $enforcement['total_withheld_minor_units']);
        self::assertSame(
            5_000_000 - $enforcement['total_withheld_minor_units'],
            $person['payable_after_enforcement_minor'],
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $calculated->revision['id']],
            ),
        );

        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'runtime-enforcement-review',
            $this->actors[1],
        );
        $approved = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'runtime-enforcement-approve',
            $this->actors[2],
        );
        self::assertSame('approved', $approved->run['status']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $approved->revision['id']],
            ),
        );
        self::assertSame(
            $enforcement['total_withheld_minor_units'],
            (int) $this->scalar(
                'SELECT COALESCE(SUM(amount_minor_units), 0)
                   FROM payroll_enforcement_ledger
                  WHERE supplier_id = ?
                    AND entry_kind IN ("withheld", "employer_fee")',
                [$this->supplierId],
            ),
        );

        $replay = $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'runtime-enforcement-approve',
            $this->actors[2],
        );
        self::assertTrue($replay->idempotentReplay);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_enforcement_month_results
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $approved->revision['id']],
            ),
        );
    }

    public function testStatutoryClaimDeliveredAfterPayDateIsNotSelectedUntilDelivery(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified,
                 created_by, updated_by)
             VALUES (?, ?, "synthetic-future-delivery-case", "enforcement",
                     "withhold_and_hold", "2026-06-01", 1, 1, ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->actors[0],
            $this->actors[0],
        ]);
        $caseId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 priority_date, first_payer_delivered_on, order_issued_on,
                 legal_title_verified, order_or_notice_delivered,
                 priority_classification_verified, agreement_verified,
                 due_monetary_claim_verified)
             VALUES (?, ?, "synthetic-future-delivery-claim", "synthetic-future-delivery-order",
                     "statutory", "non_priority", 100000,
                     "2026-07-20", "2026-07-20", "2026-07-01", 1, 1, 1, 0, 1)',
        )->execute([$this->supplierId, $caseId]);

        $enforcement = $this->container->get(PayrollEnforcementRepository::class);
        self::assertInstanceOf(PayrollEnforcementRepository::class, $enforcement);

        $beforeDelivery = $enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-15',
        );
        self::assertSame([], $beforeDelivery->claims);

        $afterDelivery = $enforcement->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-20',
        );
        self::assertCount(1, $afterDelivery->claims);
        self::assertSame(
            'synthetic-future-delivery-claim',
            $afterDelivery->claims[0]->id,
        );
    }

    public function testIdempotentReplayTenantIsolationAndOptimisticConflict(): void
    {
        $run = $this->createRun();
        $sameRun = $this->createRun();
        self::assertSame($run['id'], $sameRun['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_events
                  WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
                [$this->supplierId, $run['id']],
            ),
        );
        $first = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
        $replay = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );

        self::assertFalse($first->idempotentReplay);
        self::assertTrue($replay->idempotentReplay);
        self::assertSame($first->revision['id'], $replay->revision['id']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_revisions
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ?',
                [$this->supplierId, $run['id']],
            ),
        );
        self::assertNull($this->runs->find($this->otherSupplierId, (int) $run['id']));

        try {
            $this->service->calculate(
                $this->otherSupplierId,
                (int) $run['id'],
                (int) $first->run['row_version'],
                'foreign-tenant-command',
                $this->actors[0],
            );
            self::fail('Cizí tenant nesmí ovládat běh.');
        } catch (\OutOfBoundsException) {
            self::addToAssertionCount(1);
        }

        try {
            $this->service->calculate(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'stale-row-version',
                $this->actors[0],
            );
            self::fail('Stará row_version musí skončit konfliktem.');
        } catch (PayrollRunConflictException $e) {
            self::assertSame((int) $first->run['row_version'], $e->currentVersion);
        }

        $this->expectException(PayrollRunIdempotencyException::class);
        $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $first->run['row_version'],
            'same-command-retry',
            $this->actors[0],
        );
    }

    public function testCommandsStopWhenPayrollModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        try {
            $this->service->lockInputs(
                $this->supplierId,
                (int) $run['id'],
                (int) $run['row_version'],
                'disabled-module-command',
                $this->actors[0],
            );
            self::fail('Vypnutý mzdový modul nesmí přijímat stavové příkazy.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('vedení mezd zapnuté', $e->getMessage());
        }

        self::assertSame(
            'draft',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
    }

    public function testSuccessfulCommandCanReplayAfterModuleIsDisabled(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        $replayed = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'replay-after-module-disabled',
            $this->actors[0],
        );

        self::assertTrue($replayed->idempotentReplay);
        self::assertSame($locked->revision['id'], $replayed->revision['id']);
    }

    public function testCorrectionCreatesNewRevisionAndPreservesApprovedHistory(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $originalRevisionId = (int) $approved->revision['id'];
        $originalHash = (string) $approved->revision['result_snapshot_hash'];

        $this->approvedInput(10_000, 'CORRECTION', 'correction');
        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'request-correction',
            $this->actors[2],
            'Doplatek syntetické prémie.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'reopen-correction',
            $this->actors[1],
            'Doplatek syntetické prémie.',
        );

        self::assertSame(2, $reopened->revision['revision_no']);
        self::assertSame('correction', $reopened->revision['revision_kind']);
        self::assertSame(
            $originalRevisionId,
            $reopened->revision['previous_revision_id'],
        );
        $revisions = $this->runs->revisions($this->supplierId, $runId);
        self::assertCount(2, $revisions);
        self::assertSame('approved', $revisions[0]['status']);
        self::assertSame($originalHash, $revisions[0]['result_snapshot_hash']);

        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'calculate-correction',
            $this->actors[0],
        );
        self::assertSame(
            130_000,
            $calculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
        $events = $this->runs->events($this->supplierId, $runId);
        $correctionEvent = array_values(array_filter(
            $events,
            static fn (array $event): bool =>
                $event['event_type'] === 'request_correction',
        ))[0];
        self::assertSame('Doplatek syntetické prémie.', $correctionEvent['reason']);
    }

    /**
     * W13/P-12. Po schválení opravné revize NESMÍ zůstat dvě revize ve stavu
     * `approved`: generátor dokumentů si mohl vybrat kteroukoli a zaměstnanec
     * dostal předkorekční výplatní pásku, přestože účetnictví i JMHZ už jely
     * z nové revize. Původní revize se proto odsune (`superseded`) a nová
     * dokumentová dávka se z ní už nezaloží — už vydané dokumenty na ní ale
     * dál visí a platí jako historie.
     */
    public function testApprovedCorrectionSupersedesThePreviousApprovedRevision(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $originalRevisionId = (int) $approved->revision['id'];

        $this->approvedInput(10_000, 'SUPERSEDE', 'correction');
        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'supersede-request-correction',
            $this->actors[2],
            'Doplatek syntetické prémie.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'supersede-reopen-correction',
            $this->actors[1],
            'Doplatek syntetické prémie.',
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $reopened->run['row_version'],
            'supersede-calculate-correction',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'supersede-review-correction',
            $this->actors[1],
        );

        // Dokud běží oprava, platná je pořád ta PŮVODNÍ schválená revize.
        $documents = $this->container->get(
            \MyInvoice\Repository\Payroll\PayrollDocumentRepository::class,
        );
        self::assertIsArray($documents->approvedRevision(
            $this->supplierId,
            $runId,
            $originalRevisionId,
        ));

        $correction = $this->service->approve(
            $this->supplierId,
            $runId,
            (int) $reviewed->run['row_version'],
            'supersede-approve-correction',
            $this->actors[2],
        );
        $correctionRevisionId = (int) $correction->revision['id'];
        self::assertNotSame($originalRevisionId, $correctionRevisionId);

        $original = $this->runs->revision($this->supplierId, $originalRevisionId);
        self::assertSame('superseded', $original['status']);
        self::assertNotNull($original['superseded_at']);
        self::assertSame(
            $correctionRevisionId,
            (int) $original['superseded_by_revision_id'],
        );
        self::assertSame(
            'approved',
            $this->runs->revision($this->supplierId, $correctionRevisionId)['status'],
        );

        // Zdrojem NOVÝCH dokumentů je od téhle chvíle jen opravná revize.
        self::assertNull($documents->approvedRevision(
            $this->supplierId,
            $runId,
            $originalRevisionId,
        ));
        self::assertIsArray($documents->approvedRevision(
            $this->supplierId,
            $runId,
            $correctionRevisionId,
        ));

        // Odsunutá revize je konečná: přepsat ji nejde ani po řádcích.
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions SET status = "approved" WHERE id = ?'
        )->execute([$originalRevisionId]);
    }

    public function testRevisionSummariesOmitSnapshotsButKeepMetadataAndHashes(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $revisionId = (int) $approved->revision['id'];

        $summaries = $this->runs->revisions($this->supplierId, $runId);

        self::assertCount(1, $summaries);
        self::assertSame($revisionId, $summaries[0]['id']);
        self::assertSame(1, $summaries[0]['revision_no']);
        self::assertSame('approved', $summaries[0]['status']);
        self::assertSame(
            $approved->revision['input_snapshot_hash'],
            $summaries[0]['input_snapshot_hash'],
        );
        self::assertSame(
            $approved->revision['result_snapshot_hash'],
            $summaries[0]['result_snapshot_hash'],
        );
        self::assertTrue($summaries[0]['has_input_snapshot']);
        self::assertTrue($summaries[0]['has_result_snapshot']);
        self::assertArrayNotHasKey('input_snapshot_json', $summaries[0]);
        self::assertArrayNotHasKey('result_snapshot_json', $summaries[0]);
        self::assertArrayNotHasKey('input_snapshot', $summaries[0]);
        self::assertArrayNotHasKey('result_snapshot', $summaries[0]);

        $fullRevision = $this->runs->revision($this->supplierId, $revisionId);
        self::assertIsArray($fullRevision['input_snapshot']);
        self::assertIsArray($fullRevision['result_snapshot']);

        $currentRevision = $this->runs->currentRevision($this->supplierId, $runId);
        self::assertIsArray($currentRevision['input_snapshot']);
        self::assertIsArray($currentRevision['result_snapshot']);

        $latestApproved = $this->runs->latestApprovedRevision(
            $this->supplierId,
            $runId,
        );
        self::assertIsArray($latestApproved['input_snapshot']);
        self::assertIsArray($latestApproved['result_snapshot']);
    }

    public function testCancelledUnapprovedRunReopensFromCurrentInputsAsRegularRevision(): void
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-before-cancelled-reopen',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-before-cancelled-reopen',
            $this->actors[0],
        );
        $cancelled = $this->service->cancel(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'cancel-before-fresh-reopen',
            $this->actors[0],
            'Po uzamčení byly opraveny mzdové vstupy.',
        );
        $reopened = $this->service->reopen(
            $this->supplierId,
            (int) $run['id'],
            (int) $cancelled->run['row_version'],
            'fresh-reopen-after-cancel',
            $this->actors[1],
            'Zakládám nový snapshot z opravených vstupů.',
        );

        self::assertSame('reopened', $reopened->run['status']);
        self::assertSame(2, $reopened->revision['revision_no']);
        self::assertSame('regular', $reopened->revision['revision_kind']);

        $recalculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $reopened->run['row_version'],
            'calculate-fresh-reopen',
            $this->actors[0],
        );
        self::assertSame(
            120_000,
            $recalculated->revision['result_snapshot']['totals']['source_amount_minor'],
        );
    }

    public function testCancelledCorrectionReopensAgainstApprovedBaselineAsCorrection(): void
    {
        $approved = $this->approveInitialRun();
        $runId = (int) $approved->run['id'];
        $approvedRevisionId = (int) $approved->revision['id'];
        $this->approvedInput(10_000, 'CORRECTION_RETRY', 'correction');

        $requested = $this->service->requestCorrection(
            $this->supplierId,
            $runId,
            (int) $approved->run['row_version'],
            'request-correction-before-cancel',
            $this->actors[2],
            'Oprava podkladů.',
        );
        $firstAttempt = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $requested->run['row_version'],
            'first-correction-before-cancel',
            $this->actors[1],
            'První pokus opravy.',
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            $runId,
            (int) $firstAttempt->run['row_version'],
            'calculate-correction-before-cancel',
            $this->actors[0],
        );
        $cancelled = $this->service->cancel(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'cancel-correction-attempt',
            $this->actors[0],
            'Podklady korekce je nutné znovu upravit.',
        );

        $retried = $this->service->reopen(
            $this->supplierId,
            $runId,
            (int) $cancelled->run['row_version'],
            'retry-correction-after-cancel',
            $this->actors[1],
            'Opakovaný pokus opravy.',
        );

        self::assertSame('correction', $retried->revision['revision_kind']);
        self::assertSame($approvedRevisionId, $retried->revision['previous_revision_id']);
        self::assertSame(3, $retried->revision['revision_no']);
    }

    public function testSnapshotValidationBlocksApprovalWithoutChangingReviewedRun(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET status = "draft",
                    component_snapshot_json = NULL,
                    component_snapshot_hash = NULL,
                    approved_by = NULL,
                    approved_at = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->inputId]);
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-with-blocker',
            $this->actors[0],
        );
        $validations = $this->runs->validations(
            $this->supplierId,
            (int) $locked->revision['id'],
        );
        self::assertContains('draft_inputs_present', array_column($validations, 'code'));
        self::assertContains('employment_without_inputs', array_column($validations, 'code'));

        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-with-blocker',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-with-blocker',
            $this->actors[1],
        );
        try {
            $this->service->approve(
                $this->supplierId,
                (int) $run['id'],
                (int) $reviewed->run['row_version'],
                'approve-with-blocker',
                $this->actors[2],
            );
            self::fail('Blokující validace nesmí dovolit schválení.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('blokující validace', $e->getMessage());
        }
        self::assertSame(
            'reviewed',
            $this->runs->find($this->supplierId, (int) $run['id'])['status'],
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ? AND command_name = "approve"',
                [$this->supplierId, $run['id']],
            ),
        );
    }

    public function testAuditEventsAreAppendOnlyAtDatabaseBoundary(): void
    {
        $run = $this->createRun();
        $eventId = (int) $this->scalar(
            'SELECT id FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ? AND event_type = "created"',
            [$this->supplierId, $run['id']],
        );
        self::assertGreaterThan(0, $eventId);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_events SET reason = "tamper"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $eventId]);
    }

    /**
     * C-10 — datum výplaty nemělo horní mez, takže překlep „2027" prošel a
     * navěsil na sebe celý řetěz odvozených zákonných termínů. § 141 odst. 1
     * zákoníku práce: mzda je splatná nejpozději v měsíci NÁSLEDUJÍCÍM po
     * měsíci, ve kterém vzniklo právo na mzdu.
     */
    public function testRejectsPaymentDateBeyondTheFollowingMonth(): void
    {
        // Poslední přípustný den pro období 06/2026 je 31. 7. 2026.
        $run = $this->service->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-31',
            null,
            $this->actors[0],
        );
        self::assertSame('2026-07-31', (string) $run['payment_date']);

        foreach (['2026-08-01', '2027-07-15'] as $tooLate) {
            try {
                $this->service->createRun(
                    $this->supplierId,
                    '2026-06-01',
                    $tooLate,
                    null,
                    $this->actors[0],
                );
                self::fail(
                    "Datum výplaty {$tooLate} mimo § 141 mělo být odmítnuto.",
                );
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString(
                    '§ 141 odst. 1',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    '31. 7. 2026',
                    $exception->getMessage(),
                );
            }
        }
    }

    private function createRun(): array
    {
        return $this->service->createRun(
            $this->supplierId,
            '2026-06-01',
            '2026-07-15',
            null,
            $this->actors[0],
        );
    }

    private function approveInitialRun(): \MyInvoice\Service\Payroll\Run\PayrollRunCommandResult
    {
        $run = $this->createRun();
        $locked = $this->service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            'lock-for-correction',
            $this->actors[0],
        );
        $calculated = $this->service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            'calculate-for-correction',
            $this->actors[0],
        );
        $reviewed = $this->service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            'review-for-correction',
            $this->actors[1],
        );
        return $this->service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            'approve-for-correction',
            $this->actors[2],
        );
    }

    private function createActor(string $suffix): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)'
        );
        $stmt->execute([
            "mz09-{$suffix}-" . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
            "Synthetic {$suffix}",
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{int,int} */
    private function employment(): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Synthetic Payroll Run Person", "employee", 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "MZ09", "Syntetická účtárna", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from,
                 social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:run-persistence")'
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, "SYN-MZ09", "employment", "active",
                     "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId, $officeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 planned_start_on,
                 actual_start_on, weekly_hours, workload_basis_points,
                 social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01",
                     40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId, $officeId]);
        return [$employeeId, $employmentId];
    }

    private function approvedInput(
        int $amountMinor,
        string $code,
        string $sourceKind,
        string $enforcementTreatment = 'included',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included",
                     "included", ?, "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([
            $this->supplierId,
            $code,
            "Synthetic {$code}",
            $enforcementTreatment,
        ]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => $code,
            'name' => "Synthetic {$code}",
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => $enforcementTreatment,
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
             VALUES (?, ?, ?, ?, "2026-06-01", ?, ?, "approved", ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->employmentId,
            $componentId,
            $amountMinor,
            $sourceKind,
            $json,
            hash('sha256', $json, true),
            $this->actors[0],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function employerPolicyInput(
        bool $automaticPostingEnabled = true,
    ): array {
        return [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => $automaticPostingEnabled,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:payroll-run-policy',
        ];
    }

    private function apiRequest(
        string $method,
        string $uri,
        EffectiveRole $role,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->actors[0],
                'role' => 'readonly',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', $role);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
