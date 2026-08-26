<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStateLockedException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollModuleActivationService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollRiskySavingsLiabilityMaterializer;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingPreview;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandOutcome;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandResult;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentPreparationService;
use MyInvoice\Service\Payroll\Run\PayrollRunPaymentSettlementService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use MyInvoice\Service\Payroll\Settings\PayrollSetupCheckService;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeatures;
use MyInvoice\Service\Payroll\Settings\PayrollSetupFeaturesResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * MZ-09-W08 — mzdový běh dojede z `approved` až do `closed`.
 *
 * Testy jedou nad izolovaným dodavatelem a syntetickými daty; žádné reálné
 * identifikátory ani čísla účtů se tu nevyskytují.
 */
#[Group('integration')]
final class PayrollRunPaymentCommandsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRunRepository $runs;
    private PayrollModuleStateRepository $moduleState;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $employerPolicyId;
    private int $accountId;
    /** @var list<int> */
    private array $actors;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->container = $container;
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_posting_batches',
            'payroll_payment_liabilities',
            'payroll_payment_allocations',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }
        $runs = $container->get(PayrollRunRepository::class);
        $moduleState = $container->get(PayrollModuleStateRepository::class);
        self::assertInstanceOf(PayrollRunRepository::class, $runs);
        self::assertInstanceOf(
            PayrollModuleStateRepository::class,
            $moduleState,
        );
        $this->runs = $runs;
        $this->moduleState = $moduleState;

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo
            ->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->actors = [
            $this->createActor('calculator'),
            $this->createActor('reviewer'),
            $this->createActor('approver'),
        ];
        // Modul schválně zůstává v `setup` — jedním z předmětů testu je, že se
        // do `active` překlopí sám a testy si ho tam nemusí vkládat SQL.
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actors[0]]);

        $accountingModes = $this->container->get(AccountingModeRepository::class);
        self::assertInstanceOf(AccountingModeRepository::class, $accountingModes);
        // Výchozí režim testovací firmy je daňová evidence — účetní můstek se
        // tedy nepoužije a `post` to musí říct nahlas.
        $accountingModes->record($this->supplierId, '2026-01-01', 'tax_evidence');

        $policies = $this->container->get(PayrollEmployerPolicyRepository::class);
        self::assertInstanceOf(
            PayrollEmployerPolicyRepository::class,
            $policies,
        );
        $policy = $policies->create(
            $this->supplierId,
            $this->employerPolicyInput(),
            $this->actors[0],
        );
        $this->employerPolicyId = (int) $policy['id'];
        [$this->employeeId, $this->employmentId] = $this->employment();
        foreach (['2026-06-01', '2026-07-01'] as $periodStart) {
            $pdo->prepare(
                'INSERT INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete,
                     dependants_evidence_complete, spouse_evidence_complete,
                     pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)'
            )->execute([
                $this->supplierId,
                $this->employeeId,
                $periodStart,
                $this->actors[0],
            ]);
        }
        $this->approvedInput(120_000);
        $this->accountId = $this->verifiedAccount();
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

    public function testRunReachesClosedThroughPostingPaymentsAndSettlement(): void
    {
        $this->payoutRule('bank', "account:{$this->accountId}");
        $service = $this->commandService();
        $run = $this->approve($service);
        $runId = (int) $run->run['id'];
        $revisionId = (int) $run->revision['id'];

        $posted = $service->post(
            $this->supplierId,
            $runId,
            (int) $run->run['row_version'],
            'synthetic-post-happy-path',
            $this->actors[2],
        );
        self::assertSame('posted', $posted->run['status']);
        // Daňová evidence: běh postoupil, ale účetní zápis nevznikl a odpověď
        // to říká nahlas.
        self::assertSame(
            PayrollRunCommandOutcome::POSTING_NOT_APPLICABLE,
            $posted->outcome?->outcome,
        );
        self::assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_posting_batches
                  WHERE supplier_id = ? AND revision_id = ?',
                [$this->supplierId, $revisionId],
            ),
        );

        $prepared = $service->preparePayments(
            $this->supplierId,
            $runId,
            (int) $posted->run['row_version'],
            'synthetic-prepare-happy-path',
            $this->actors[2],
        );
        self::assertSame('payment_ready', $prepared->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_PREPARED,
            $prepared->outcome?->outcome,
        );
        $liabilities = $this->liabilities($revisionId);
        self::assertNotSame([], $liabilities);

        // Nepokrytý zbytek musí `mark_paid` zablokovat i s vyčíslením.
        $first = $liabilities[0];
        $firstAllocationId = $this->allocate(
            $first['id'],
            $first['amount_minor'] - 1_500,
        );
        $this->settleAllocation(
            $firstAllocationId,
            $first['amount_minor'] - 1_500,
        );
        try {
            $service->markPaid(
                $this->supplierId,
                $runId,
                (int) $prepared->run['row_version'],
                'synthetic-mark-paid-partial',
                $this->actors[2],
            );
            self::fail('Nepokrytý zbytek nesmí pustit běh do uhrazeno.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'nepokrývají',
                $exception->getMessage(),
            );
            self::assertStringContainsString('15,00', $exception->getMessage());
        }
        self::assertSame(
            'payment_ready',
            (string) $this->runs->find($this->supplierId, $runId)['status'],
        );

        $remainderAllocationId = $this->allocate($first['id'], 1_500);
        $this->settleAllocation($remainderAllocationId, 1_500);
        foreach (array_slice($liabilities, 1) as $liability) {
            $allocationId = $this->allocate(
                $liability['id'],
                $liability['amount_minor'],
            );
            $this->settleAllocation(
                $allocationId,
                $liability['amount_minor'],
            );
        }

        $paid = $service->markPaid(
            $this->supplierId,
            $runId,
            (int) $prepared->run['row_version'],
            'synthetic-mark-paid-happy-path',
            $this->actors[2],
        );
        self::assertSame('paid', $paid->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_SETTLED,
            $paid->outcome?->outcome,
        );

        $closed = $service->close(
            $this->supplierId,
            $runId,
            (int) $paid->run['row_version'],
            'synthetic-close-happy-path',
            $this->actors[2],
        );
        self::assertSame('closed', $closed->run['status']);
        self::assertNull($closed->outcome);
    }

    public function testTaxEvidenceRunIsNeverMarkedAsPostedInTheLedger(): void
    {
        $this->payoutRule('bank', "account:{$this->accountId}");
        $service = $this->commandService();
        $run = $this->approve($service);

        $posted = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-tax-evidence',
            $this->actors[2],
        );

        self::assertSame(
            PayrollRunCommandOutcome::POSTING_NOT_APPLICABLE,
            $posted->outcome?->outcome,
        );
        self::assertSame(
            'tax_evidence',
            $posted->outcome?->details['reason'] ?? null,
        );
    }

    public function testDoubleEntryWithDisabledAutomaticPostingPostsManually(): void
    {
        $this->doubleEntry();
        $posting = $this->createMock(PayrollApprovedRevisionPostingService::class);
        // Automatická cesta při vypnuté politice nezaúčtuje nic…
        $posting->method('post')->willReturn(null);
        // …ruční ano, a přesně jednou.
        $posting->expects(self::once())
            ->method('postManually')
            ->willReturn($this->postingResult(4242));
        $service = $this->commandService($posting);
        $run = $this->approve($service);

        $posted = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-manual',
            $this->actors[2],
        );

        self::assertSame('posted', $posted->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::POSTED,
            $posted->outcome?->outcome,
        );
        self::assertSame(4242, $posted->outcome?->details['batch_id'] ?? null);
    }

    public function testRepeatedPostWithSameKeyDoesNotPostTwice(): void
    {
        $this->doubleEntry();
        $posting = $this->createMock(PayrollApprovedRevisionPostingService::class);
        $posting->method('post')->willReturn(null);
        $posting->expects(self::once())
            ->method('postManually')
            ->willReturn($this->postingResult(77));
        $service = $this->commandService($posting);
        $run = $this->approve($service);

        $first = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-idempotent',
            $this->actors[2],
        );
        $replay = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-idempotent',
            $this->actors[2],
        );

        self::assertFalse($first->idempotentReplay);
        self::assertTrue($replay->idempotentReplay);
        self::assertSame('posted', $replay->run['status']);
        // Replay vrací i to, co se stalo poprvé — jinak by se opakovaný příkaz
        // tvářil jinak než původní.
        self::assertSame(
            PayrollRunCommandOutcome::POSTED,
            $replay->outcome?->outcome,
        );
        self::assertSame(77, $replay->outcome?->details['batch_id'] ?? null);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_run_commands
                  WHERE supplier_id = ? AND run_id = ? AND command_name = "post"',
                [$this->supplierId, (int) $run->run['id']],
            ),
        );
    }

    public function testExistingPostingBatchOnlyMovesTheRunForward(): void
    {
        $this->doubleEntry();
        $posting = $this->createMock(PayrollApprovedRevisionPostingService::class);
        $posting->method('post')->willReturn(null);
        $posting->expects(self::never())->method('postManually');
        $service = $this->commandService($posting);
        $run = $this->approve($service);
        $batchId = $this->postingBatch(
            (int) $run->run['id'],
            (int) $run->revision['id'],
        );

        $posted = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-existing-batch',
            $this->actors[2],
        );

        self::assertSame('posted', $posted->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::ALREADY_POSTED,
            $posted->outcome?->outcome,
        );
        self::assertSame($batchId, $posted->outcome?->details['batch_id'] ?? null);
    }

    public function testPreparePaymentsNamesEmployeeWithoutPayoutRule(): void
    {
        $service = $this->commandService();
        $run = $this->approve($service);
        $posted = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-missing-rule',
            $this->actors[2],
        );

        try {
            $service->preparePayments(
                $this->supplierId,
                (int) $run->run['id'],
                (int) $posted->run['row_version'],
                'synthetic-prepare-missing-rule',
                $this->actors[2],
            );
            self::fail('Chybějící výplatní pravidlo musí přípravu zastavit.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'Syntetická mzdová osoba',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'výplatní pravidlo',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'alokační pravidla',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'posted',
            (string) $this->runs->find(
                $this->supplierId,
                (int) $run->run['id'],
            )['status'],
        );
        self::assertSame([], $this->liabilities((int) $run->revision['id']));
    }

    public function testBlockedInstitutionalLiabilityLeavesRunPostedWithoutPartialWrites(): void
    {
        $this->payoutRule('bank', "account:{$this->accountId}");
        $health = $this->createStub(
            PayrollHealthInsuranceLiabilityMaterializer::class,
        );
        $health->method('materialize')->willThrowException(
            new \DomainException('Revize nemá neměnný výsledek zdravotního pojištění.'),
        );
        $service = $this->commandService(paymentPreparation: $this->preparation(
            healthInsurance: $health,
        ));
        $run = $this->approve($service);
        $posted = $service->post(
            $this->supplierId,
            (int) $run->run['id'],
            (int) $run->run['row_version'],
            'synthetic-post-blocked-institution',
            $this->actors[2],
        );

        try {
            $service->preparePayments(
                $this->supplierId,
                (int) $run->run['id'],
                (int) $posted->run['row_version'],
                'synthetic-prepare-blocked-institution',
                $this->actors[2],
            );
            self::fail('Zablokovaný druh závazku nesmí pustit běh dál.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'zdravotního pojištění',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'posted',
            (string) $this->runs->find(
                $this->supplierId,
                (int) $run->run['id'],
            )['status'],
        );
        // Fail-closed znamená i uklizeno: závazky čisté mzdy vytvořené před
        // pádem se musí odrolovat, jinak by je další pokus zdvojil.
        self::assertSame([], $this->liabilities((int) $run->revision['id']));
    }

    public function testPartnerSettlementRunReachesPaidWithoutAnyPayment(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET relation_type = "statutory_body"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->employmentId]);
        $this->payoutRule('partner_settlement', '365.100');
        $service = $this->commandService();
        $run = $this->approve($service);
        $runId = (int) $run->run['id'];

        $posted = $service->post(
            $this->supplierId,
            $runId,
            (int) $run->run['row_version'],
            'synthetic-post-settlement',
            $this->actors[2],
        );
        $prepared = $service->preparePayments(
            $this->supplierId,
            $runId,
            (int) $posted->run['row_version'],
            'synthetic-prepare-settlement',
            $this->actors[2],
        );

        // Zápočet na účet společníka není platba — nevzniká žádný závazek
        // a přesto se běh musí dostat až do uzavření.
        self::assertSame([], $this->liabilities((int) $run->revision['id']));
        self::assertSame('payment_ready', $prepared->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_NOT_APPLICABLE,
            $prepared->outcome?->outcome,
        );

        $paid = $service->markPaid(
            $this->supplierId,
            $runId,
            (int) $prepared->run['row_version'],
            'synthetic-mark-paid-settlement',
            $this->actors[2],
        );
        self::assertSame('paid', $paid->run['status']);
        self::assertSame(
            PayrollRunCommandOutcome::PAYMENTS_NOT_APPLICABLE,
            $paid->outcome?->outcome,
        );
        self::assertSame(
            'nothing_to_pay',
            $paid->outcome?->details['reason'] ?? null,
        );
    }

    public function testFirstApprovedRunActivatesModuleAndSecondApprovalDoesNot(): void
    {
        self::assertSame('setup', $this->moduleState->get($this->supplierId)['status']);
        $service = $this->commandService();
        $this->approve($service);

        $state = $this->moduleState->get($this->supplierId);
        self::assertSame('active', $state['status']);
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );
        self::assertStringContainsString(
            'first_approved_run',
            (string) $this->scalar(
                'SELECT payload FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );

        // Druhý běh stav ani auditní stopu nemění.
        $this->approve($service, '2026-07-01', '2026-08-15', 'second');
        self::assertSame(
            $state['row_version'],
            $this->moduleState->get($this->supplierId)['row_version'],
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );
    }

    public function testCompletedSetupCheckActivatesModuleOnceAndIrreversibly(): void
    {
        $activation = $this->activationService(false);
        self::assertNull(
            $activation->activateWhenSetupComplete(
                $this->supplierId,
                $this->actors[0],
            ),
        );
        self::assertSame(
            'setup',
            $this->moduleState->get($this->supplierId)['status'],
        );

        $ready = $this->activationService(true);
        $state = $ready->activateWhenSetupComplete(
            $this->supplierId,
            $this->actors[0],
        );
        self::assertSame('active', $state['status'] ?? null);
        self::assertStringContainsString(
            'setup_complete',
            (string) $this->scalar(
                'SELECT payload FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );

        // Idempotence: druhé vyhodnocení nic nemění.
        self::assertNull(
            $ready->activateWhenSetupComplete(
                $this->supplierId,
                $this->actors[0],
            ),
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );

        // Jednosměrnost: pozdější blokátor stav zpátky do `setup` nevrátí.
        $this->activationService(false)->activateWhenSetupComplete(
            $this->supplierId,
            $this->actors[0],
        );
        self::assertSame(
            'active',
            $this->moduleState->get($this->supplierId)['status'],
        );
    }

    /**
     * Spouště jsou dvě a vyhrává ta dřívější — druhá pak nesmí stav ani
     * auditní stopu měnit, jinak by se firma v přehledu aktivit „aktivovala"
     * dvakrát.
     */
    public function testApprovalAfterSetupCompleteChangesNothing(): void
    {
        $this->activationService(true)->activateWhenSetupComplete(
            $this->supplierId,
            $this->actors[0],
        );
        $state = $this->moduleState->get($this->supplierId);
        self::assertSame('active', $state['status']);

        $this->approve($this->commandService());

        self::assertSame(
            $state['row_version'],
            $this->moduleState->get($this->supplierId)['row_version'],
        );
        self::assertSame(
            1,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM activity_log
                  WHERE supplier_id = ? AND action = "payroll.activation.activated"',
                [$this->supplierId],
            ),
        );
    }

    public function testActiveModuleCannotBeDisabledNorDowngradedToSetup(): void
    {
        $this->activationService(true)->activateWhenSetupComplete(
            $this->supplierId,
            $this->actors[0],
        );
        $state = $this->moduleState->get($this->supplierId);
        self::assertSame('active', $state['status']);

        $kept = $this->moduleState->setActivation(
            $this->supplierId,
            true,
            '2026-02-01',
            $state['row_version'],
            $this->actors[0],
        );
        self::assertSame('active', $kept['status']);

        $this->expectException(PayrollStateLockedException::class);
        $this->moduleState->setActivation(
            $this->supplierId,
            false,
            null,
            $kept['row_version'],
            $this->actors[0],
        );
    }

    private function commandService(
        ?PayrollApprovedRevisionPostingService $posting = null,
        ?PayrollRunPaymentPreparationService $paymentPreparation = null,
    ): PayrollRunCommandService {
        if ($posting === null) {
            $realPosting = $this->container->get(
                PayrollApprovedRevisionPostingService::class,
            );
            self::assertInstanceOf(
                PayrollApprovedRevisionPostingService::class,
                $realPosting,
            );
            $posting = $realPosting;
        }

        return new PayrollRunCommandService(
            $this->db,
            $this->runs,
            $this->container->get(PayrollRunSnapshotBuilder::class),
            new PayrollRunCalculationPipeline(
                $this->container->get(PayrollRunCalculator::class),
                $this->container->get(PayrollRunGarnishmentProcessor::class),
            ),
            $this->container->get(PayrollRunWorkflow::class),
            $this->container->get(PayrollPeriodOwnershipService::class),
            $posting,
            null,
            null,
            $paymentPreparation ?? $this->preparation(),
            new PayrollRunPaymentSettlementService($this->db),
            $this->activationService(false),
        );
    }

    private function preparation(
        ?PayrollHealthInsuranceLiabilityMaterializer $healthInsurance = null,
    ): PayrollRunPaymentPreparationService {
        $netWages = $this->container->get(
            PayrollNetWageLiabilityMaterializer::class,
        );
        self::assertInstanceOf(
            PayrollNetWageLiabilityMaterializer::class,
            $netWages,
        );

        // Institucionální závazky mají vlastní integrační testy a vyžadují
        // zákonné výsledky z produkční kalkulační pipeline. Tady jde o
        // orchestraci příkazu, takže se chovají jako „nic k vytvoření".
        return new PayrollRunPaymentPreparationService(
            $netWages,
            $healthInsurance ?? $this->emptyMaterializer(
                PayrollHealthInsuranceLiabilityMaterializer::class,
            ),
            $this->emptyMaterializer(
                PayrollSocialInsuranceLiabilityMaterializer::class,
            ),
            $this->emptyMaterializer(
                PayrollIncomeTaxLiabilityMaterializer::class,
            ),
            $this->emptyMaterializer(
                PayrollEnforcementLiabilityMaterializer::class,
            ),
            $this->container->get(
                PayrollRiskySavingsLiabilityMaterializer::class,
            ),
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function emptyMaterializer(string $class): object
    {
        $stub = $this->createStub($class);
        $stub->method('materialize')->willReturn([
            'liability_ids' => [],
            'created_count' => 0,
        ]);

        return $stub;
    }

    private function activationService(
        bool $setupReady,
    ): PayrollModuleActivationService {
        $features = $this->createStub(PayrollSetupFeaturesResolver::class);
        $features->method('resolve')->willReturn(
            new PayrollSetupFeatures(
                homeOffice: false,
                travelExpenses: false,
                fourEyes: false,
                automaticCalculation: false,
                automaticPosting: false,
                automaticPayments: false,
                secureDelivery: false,
                jmhz: false,
                activeApproverCount: 0,
                jmhzRegistryReady: false,
                jmhzCertificateReady: false,
                sourceBlockers: [],
            ),
        );
        $setupCheck = $this->createStub(PayrollSetupCheckService::class);
        $setupCheck->method('check')->willReturn([
            'ready' => $setupReady,
            'effective_on' => '2026-06-01',
            'policy_id' => $this->employerPolicyId,
            'checks' => [],
            'blockers' => $setupReady
                ? []
                : ['employer_settings'],
        ]);
        $logger = $this->container->get(ActivityLogger::class);
        self::assertInstanceOf(ActivityLogger::class, $logger);

        return new PayrollModuleActivationService(
            $this->moduleState,
            $logger,
            $features,
            $setupCheck,
        );
    }

    private function approve(
        PayrollRunCommandService $service,
        string $periodStart = '2026-06-01',
        string $paymentDate = '2026-07-15',
        string $keySuffix = 'first',
    ): PayrollRunCommandResult {
        $run = $service->createRun(
            $this->supplierId,
            $periodStart,
            $paymentDate,
            null,
            $this->actors[0],
        );
        $locked = $service->lockInputs(
            $this->supplierId,
            (int) $run['id'],
            (int) $run['row_version'],
            "synthetic-lock-{$keySuffix}",
            $this->actors[0],
        );
        $calculated = $service->calculate(
            $this->supplierId,
            (int) $run['id'],
            (int) $locked->run['row_version'],
            "synthetic-calculate-{$keySuffix}",
            $this->actors[0],
        );
        $reviewed = $service->review(
            $this->supplierId,
            (int) $run['id'],
            (int) $calculated->run['row_version'],
            "synthetic-review-{$keySuffix}",
            $this->actors[1],
        );

        return $service->approve(
            $this->supplierId,
            (int) $run['id'],
            (int) $reviewed->run['row_version'],
            "synthetic-approve-{$keySuffix}",
            $this->actors[2],
        );
    }

    private function doubleEntry(): void
    {
        $accountingModes = $this->container->get(AccountingModeRepository::class);
        self::assertInstanceOf(AccountingModeRepository::class, $accountingModes);
        $accountingModes->record($this->supplierId, '2026-01-01', 'double_entry');
    }

    /** @return array{batch_id:int,journal_entry_id:?int,status:string,idempotent:bool,preview:PayrollPostingPreview} */
    private function postingResult(int $batchId): array
    {
        return [
            'batch_id' => $batchId,
            'journal_entry_id' => null,
            'status' => 'no_change',
            'idempotent' => false,
            'preview' => new PayrollPostingPreview(
                [],
                [],
                hash('sha256', 'synthetic-target'),
                hash('sha256', 'synthetic-delta'),
                0,
                0,
            ),
        ];
    }

    private function postingBatch(int $runId, int $revisionId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, entry_date, status,
                 target_hash, delta_hash, created_by, posted_at)
             VALUES (?, ?, ?, "2026-06-30", "no_change", ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            hash('sha256', "synthetic-target:{$revisionId}"),
            hash('sha256', "synthetic-delta:{$revisionId}"),
            $this->actors[2],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Platební dávku, položku a alokaci zakládáme přímo — cílem téhle sady je
     * brána příkazu `mark_paid` nad ledgerem, ne generování platebního souboru
     * (to má vlastní testy).
     */
    private function allocate(int $liabilityId, int $amountMinor): int
    {
        $pdo = $this->db->pdo();
        $reference = 'synthetic-' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 planned_payment_date, payer_reference, declared_total_minor,
                 declared_item_count, snapshot_ciphertext, snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, "bank", "manual", "2026-07-15", "synthetic-payer",
                     ?, 1, ?, ?, UNHEX(?), ?)',
        )->execute([
            $this->supplierId,
            $reference,
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "synthetic-batch:{$reference}"),
            hash('sha256', "synthetic-batch-key:{$reference}"),
            $this->actors[2],
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "synthetic-recipient", ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $batchId,
            $reference,
            $amountMinor,
            'enc:v2:synthetic-item',
            hash('sha256', "synthetic-item:{$reference}"),
            hash('sha256', "synthetic-item-key:{$reference}"),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "synthetic-allocation-key:{$reference}"),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function settleAllocation(int $allocationId, int $amountMinor): void
    {
        $pdo = $this->db->pdo();
        $reference = 'synthetic-settlement-' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code,
                 currency, statement_date, source)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK",
                     "2026-07-31", "gpc")',
        )->execute([
            $this->supplierId,
            "{$reference}.gpc",
            hash('sha256', "synthetic-statement:{$reference}"),
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $amount = sprintf(
            '-%d.%02d',
            intdiv($amountMinor, 100),
            $amountMinor % 100,
        );
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, "2026-07-15", ?, "CZK", ?, ?)',
        )->execute([
            $statementId,
            $amount,
            "Syntetická úhrada {$reference}",
            hash('sha256', "synthetic-transaction:{$reference}"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id,
                 idempotency_key_hash, matched_by)
             VALUES (?, ?, "matched", ?, ?, ?, UNHEX(?), ?)',
        )->execute([
            $this->supplierId,
            $allocationId,
            $amountMinor,
            $statementId,
            $transactionId,
            hash('sha256', "synthetic-match:{$reference}"),
            $this->actors[2],
        ]);
    }

    /** @return list<array{id:int,amount_minor:int}> */
    private function liabilities(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, amount_minor FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'amount_minor' => (int) $row['amount_minor'],
            ];
        }

        return $result;
    }

    private function payoutRule(
        string $destinationKind,
        ?string $destinationReference,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference,
                 destination_kind, destination_reference, allocation_kind,
                 priority_no, is_active)
             VALUES (?, ?, "SYNTHETIC-REMAINDER", ?, ?, "remainder", 100, 1)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $destinationKind,
            $destinationReference,
        ]);
    }

    private function verifiedAccount(): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 row_version, verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account",
                     UNHEX(?), "••••0005", 10000, "2026-01-01", 1, 1,
                     "user_verified", "2026-05-01", ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            hash('sha256', "synthetic-run-account:{$this->supplierId}"),
            $this->actors[0],
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createActor(string $suffix): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, "readonly", "cs", 1)',
        )->execute([
            "mz09w08-{$suffix}-" . bin2hex(random_bytes(4)) . '@invalid.example',
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
             VALUES (?, "Syntetická mzdová osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "W08", "Syntetická účtárna", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, "SYN-W08", "employment", "active",
                     "2026-01-01", "2026-01-01", 1)',
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
                     40, 10000, "automatic", "automatic", "advance", 1, 1)',
        )->execute([$this->supplierId, $employmentId, $officeId]);

        return [$employeeId, $employmentId];
    }

    private function approvedInput(int $amountMinor): void
    {
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
             VALUES (?, "SYNW08", "Syntetická mzda", "base_wage", "monetary",
                     "regular", "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "521", "331", "2026-01-01")',
        )->execute([$this->supplierId]);
        $componentId = (int) $pdo->lastInsertId();
        $snapshot = [
            'code' => 'SYNW08',
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
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ];
        $json = CanonicalJson::encode($snapshot);
        foreach (['2026-06-01', '2026-07-01'] as $periodStart) {
            $pdo->prepare(
                'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, status,
                     component_snapshot_json, component_snapshot_hash,
                     approved_by, approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, "manual", "approved", ?, ?, ?, NOW())',
            )->execute([
                $this->supplierId,
                $this->employeeId,
                $this->employmentId,
                $componentId,
                $periodStart,
                $amountMinor,
                $json,
                hash('sha256', $json, true),
                $this->actors[0],
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function employerPolicyInput(): array
    {
        return [
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
            // Vypnuté automatické zaúčtování je právě ten případ, kdy dosud
            // neexistovala žádná cesta, jak mzdy zaúčtovat.
            'automatic_posting_enabled' => false,
            'automatic_payments_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:payroll-run-w08-policy',
        ];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }
}
