<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEnforcementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementDecisionDocumentReference;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentAllocation;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEnforcementApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEnforcementAction $action;
    private PayrollEnforcementRepository $repository;
    private EnforcementCaseLifecycle $lifecycle;
    private PayrollModuleAccess $access;
    private IpMatcher $ipMatcher;
    private DocumentRepository $documents;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollEnforcementAction::class);
        $repository = $container->get(PayrollEnforcementRepository::class);
        $lifecycle = $container->get(EnforcementCaseLifecycle::class);
        $access = $container->get(PayrollModuleAccess::class);
        $ipMatcher = $container->get(IpMatcher::class);
        $documents = $container->get(DocumentRepository::class);
        if (
            !$db instanceof Connection
            || !$action instanceof PayrollEnforcementAction
            || !$repository instanceof PayrollEnforcementRepository
            || !$lifecycle instanceof EnforcementCaseLifecycle
            || !$access instanceof PayrollModuleAccess
            || !$ipMatcher instanceof IpMatcher
            || !$documents instanceof DocumentRepository
        ) {
            throw new \RuntimeException('Služby srážek nejsou dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_enforcement_cases')) {
            $this->markTestSkipped('Migrace 1240 neproběhla.');
        }
        $this->action = $action;
        $this->repository = $repository;
        $this->lifecycle = $lifecycle;
        $this->access = $access;
        $this->ipMatcher = $ipMatcher;
        $this->documents = $documents;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employeeId = $this->employee($this->supplierId, 'Syntetická osoba A');
        $this->otherEmployeeId = $this->employee(
            $this->otherSupplierId,
            'Syntetická osoba B',
        );
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

    public function testTenantIsolationSessionGuardAndLifecycleEvidenceGates(): void
    {
        $bearer = $this->action->list(
            $this->request('GET', '/api/payroll/enforcement/cases', authMethod: 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $own = $this->createCase($this->employeeId);
        $ownId = PayrollTimeValue::int($own['id'] ?? null, 'id');
        $foreign = $this->createCase($this->otherEmployeeId, $this->otherSupplierId);
        $foreignId = PayrollTimeValue::int($foreign['id'] ?? null, 'id');

        $list = $this->action->list(
            $this->request('GET', '/api/payroll/enforcement/cases'),
            new Response(),
        );
        self::assertSame(200, $list->getStatusCode());
        $items = PayrollTimeValue::rows(
            $this->json($list)['cases'] ?? null,
            'cases',
        );
        self::assertCount(1, $items);
        self::assertSame($ownId, $items[0]['id']);

        $foreignDetail = $this->action->detail(
            $this->request('GET', "/api/payroll/enforcement/cases/{$foreignId}"),
            new Response(),
            ['id' => (string) $foreignId],
        );
        self::assertSame(404, $foreignDetail->getStatusCode());

        $prematureEvidence = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => true,
                    'row_version' => 1,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(409, $prematureEvidence->getStatusCode());
        self::assertSame('evidence_incomplete', $this->errorCode($prematureEvidence));

        $claimResponse = $this->action->addClaim(
            $this->request('POST', "/api/payroll/enforcement/cases/{$ownId}/claims")
                ->withParsedBody([
                    'legal_basis' => 'statutory',
                    'category' => 'non_priority',
                    'outstanding_minor_units' => 250_000,
                    'maintenance_weight_minor_units' => null,
                    'priority_date' => '2026-05-20',
                    'order_issued_on' => '2026-05-15',
                    'legal_title_verified' => true,
                    'order_or_notice_delivered' => true,
                    'priority_classification_verified' => true,
                    'agreement_verified' => false,
                    'due_monetary_claim_verified' => true,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(201, $claimResponse->getStatusCode(), (string) $claimResponse->getBody());

        $evidence = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => true,
                    'row_version' => 2,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(200, $evidence->getStatusCode(), (string) $evidence->getBody());
        $case = PayrollTimeValue::row($this->json($evidence)['case'] ?? null, 'case');
        self::assertSame(3, $case['row_version']);

        $stale = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => true,
                    'row_version' => 1,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($stale));

        $activationWithoutDocument = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$ownId}/commands/mark_final",
            )->withParsedBody(['row_version' => 3]),
            new Response(),
            ['id' => (string) $ownId, 'command' => 'mark_final'],
        );
        self::assertSame(422, $activationWithoutDocument->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->errorCode($activationWithoutDocument),
        );

        $initialOrderDocumentId = $this->document(
            $this->supplierId,
            str_repeat('b', 64),
        );
        $activated = $this->transition(
            $ownId,
            'mark_final',
            3,
            $initialOrderDocumentId,
        );
        self::assertSame('withhold_and_hold', $activated['status']);
        $initialDocument = $this->db->pdo()->prepare(
            'SELECT evidence_kind, document_sha256
               FROM payroll_enforcement_case_documents
              WHERE supplier_id = ? AND case_id = ? AND dms_document_id = ?'
        );
        $initialDocument->execute([
            $this->supplierId,
            $ownId,
            $initialOrderDocumentId,
        ]);
        $initialDocumentRow = PayrollTimeValue::row(
            $initialDocument->fetch(PDO::FETCH_ASSOC),
            'initial_case_document',
        );
        self::assertSame('initial_order', $initialDocumentRow['evidence_kind']);
        self::assertSame(str_repeat('b', 64), $initialDocumentRow['document_sha256']);
        $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => 'none',
                'has_multiple_payers' => false,
                'protected_amount_override_minor_units' => null,
                'protected_amount_override_verified' => false,
                'insolvency_mode' => 'none',
                'insolvency_decision_verified' => false,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ],
            $this->userId,
            null,
        );
        self::assertTrue($this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-15',
        )->claimRegisterEvidenceComplete);

        $revokedEvidence = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => false,
                    'recipient_verified' => true,
                    'row_version' => 4,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(200, $revokedEvidence->getStatusCode());
        self::assertFalse($this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-15',
        )->claimRegisterEvidenceComplete);

        $restoredEvidence = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => true,
                    'row_version' => 5,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(200, $restoredEvidence->getStatusCode());

        $decisionDocumentId = $this->document(
            $this->supplierId,
            str_repeat('c', 64),
        );
        $remitting = $this->transition(
            $ownId,
            'authorize_remittance',
            6,
            $decisionDocumentId,
        );
        self::assertSame('remit', $remitting['status']);
        $events = PayrollTimeValue::rows($remitting['events'] ?? null, 'events');
        self::assertSame($decisionDocumentId, $events[0]['decision_document_id']);
        $caseDocument = $this->db->pdo()->prepare(
            'SELECT evidence_kind, document_sha256
               FROM payroll_enforcement_case_documents
              WHERE supplier_id = ? AND case_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $caseDocument->execute([$this->supplierId, $ownId]);
        $caseDocumentRow = PayrollTimeValue::row(
            $caseDocument->fetch(PDO::FETCH_ASSOC),
            'case_document',
        );
        self::assertSame('remittance', $caseDocumentRow['evidence_kind']);
        self::assertSame(str_repeat('c', 64), $caseDocumentRow['document_sha256']);
        $auditStmt = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = ?
              ORDER BY id DESC LIMIT 1'
        );
        $auditStmt->execute([
            $this->supplierId,
            'payroll.enforcement.case.transitioned',
        ]);
        $audit = PayrollTimeValue::row(
            json_decode(
                PayrollTimeValue::string($auditStmt->fetchColumn(), 'payload'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            'audit',
        );
        self::assertSame('authorize_remittance', $audit['command']);
        self::assertSame($decisionDocumentId, $audit['decision_document_id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            PayrollTimeValue::string($audit['snapshot_hash'] ?? null, 'snapshot_hash'),
        );

        $recipientRevocation = $this->action->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$ownId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => false,
                    'row_version' => 7,
                ]),
            new Response(),
            ['id' => (string) $ownId],
        );
        self::assertSame(409, $recipientRevocation->getStatusCode());
        self::assertSame('evidence_incomplete', $this->errorCode($recipientRevocation));

        $paid = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$ownId}/commands/mark_paid",
            )->withParsedBody(['row_version' => 7]),
            new Response(),
            ['id' => (string) $ownId, 'command' => 'mark_paid'],
        );
        self::assertSame(409, $paid->getStatusCode());
        self::assertSame('invalid_case_transition', $this->errorCode($paid));
    }

    public function testZeroProtectedAmountOverrideIsAcceptedForMultiplePayers(): void
    {
        $evidence = $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => 'none',
                'has_multiple_payers' => true,
                'protected_amount_override_minor_units' => 0,
                'protected_amount_override_verified' => true,
                'insolvency_mode' => 'none',
                'insolvency_decision_verified' => false,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ],
            $this->userId,
            null,
        );

        self::assertSame(0, $evidence['protected_amount_override_minor_units']);
    }

    public function testAddingDependantInvalidatesOverlappingMonthEvidence(): void
    {
        $payload = [
            'claim_register_evidence_complete' => true,
            'dependants_evidence_complete' => true,
            'spouse_evidence_complete' => true,
            'pension_evidence' => 'none',
            'has_multiple_payers' => false,
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'insolvency_mode' => 'none',
            'insolvency_decision_verified' => false,
            'insolvency_recipient_verified' => false,
            'court_determined_amount_minor_units' => null,
        ];
        $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            $payload,
            $this->userId,
            null,
        );
        $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-07',
            $payload,
            $this->userId,
            null,
        );

        $this->repository->addDependant($this->supplierId, $this->employeeId, [
            'dependant_kind' => 'dependant',
            'valid_from' => '2026-06-15',
            'valid_to' => '2026-06-30',
            'eligibility_verified' => false,
            'excluded_for_maintenance' => false,
        ]);

        $june = $this->repository->monthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
        );
        $july = $this->repository->monthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-07',
        );
        self::assertFalse($june['dependants_evidence_complete']);
        self::assertTrue($june['spouse_evidence_complete']);
        self::assertSame(2, $june['row_version']);
        self::assertTrue($july['dependants_evidence_complete']);
        self::assertSame(1, $july['row_version']);

        $this->repository->addDependant($this->supplierId, $this->employeeId, [
            'dependant_kind' => 'spouse_partner',
            'valid_from' => '2026-06-01',
            'valid_to' => null,
            'eligibility_verified' => false,
            'excluded_for_maintenance' => false,
        ]);

        $june = $this->repository->monthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
        );
        $july = $this->repository->monthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-07',
        );
        self::assertFalse($june['spouse_evidence_complete']);
        self::assertSame(3, $june['row_version']);
        self::assertFalse($july['spouse_evidence_complete']);
        self::assertSame(2, $july['row_version']);
    }

    public function testAppendOnlyAndIntegrityTriggersAreInstalled(): void
    {
        $expected = [
            'trg_payroll_enforcement_allocation_consistency_insert',
            'trg_payroll_enforcement_allocation_immutable_delete',
            'trg_payroll_enforcement_allocation_immutable_update',
            'trg_payroll_enforcement_case_document_immutable_delete',
            'trg_payroll_enforcement_case_document_immutable_update',
            'trg_payroll_enforcement_case_document_insert',
            'trg_payroll_enforcement_case_immutable_delete',
            'trg_payroll_enforcement_claim_immutable_delete',
            'trg_payroll_enforcement_claim_mutable_update',
            'trg_payroll_enforcement_event_document_insert',
            'trg_payroll_enforcement_event_immutable_delete',
            'trg_payroll_enforcement_event_immutable_update',
            'trg_payroll_enforcement_ledger_consistency_insert',
            'trg_payroll_enforcement_ledger_immutable_delete',
            'trg_payroll_enforcement_ledger_immutable_update',
            'trg_payroll_enforcement_result_revision_insert',
            'trg_payroll_enforcement_result_immutable_delete',
            'trg_payroll_enforcement_result_immutable_update',
        ];
        $stmt = $this->db->pdo()->query(
            "SELECT TRIGGER_NAME
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND TRIGGER_NAME LIKE 'trg_payroll_enforcement_%'
              ORDER BY TRIGGER_NAME"
        );
        self::assertNotFalse($stmt);
        $actual = array_map(
            static fn (mixed $value): string => PayrollTimeValue::string(
                $value,
                'trigger_name',
            ),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );

        foreach ($expected as $trigger) {
            self::assertContains($trigger, $actual);
        }
    }

    public function testDecisionMustReferenceAnExistingTenantDmsDocument(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');

        $forged = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$caseId}/commands/stop",
            )->withParsedBody([
                'row_version' => 1,
                'reason' => 'Syntetické zastavení',
                'decision_evidence_hash' => str_repeat('a', 64),
            ]),
            new Response(),
            ['id' => (string) $caseId, 'command' => 'stop'],
        );
        self::assertSame(422, $forged->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($forged));

        $documentId = $this->document($this->supplierId, str_repeat('b', 64));
        $stopped = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$caseId}/commands/stop",
            )->withParsedBody([
                'row_version' => 1,
                'reason' => 'Syntetické zastavení',
                'decision_document_id' => $documentId,
            ]),
            new Response(),
            ['id' => (string) $caseId, 'command' => 'stop'],
        );
        self::assertSame(200, $stopped->getStatusCode(), (string) $stopped->getBody());
        self::assertSame(
            'stopped',
            PayrollTimeValue::row($this->json($stopped)['case'] ?? null, 'case')['status'],
        );

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $documentId]);
    }

    public function testDecisionDocumentRequiresDocumentPermissionAndIsRedacted(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $documentId = $this->document($this->supplierId, str_repeat('e', 64));
        $payrollOnly = new EffectiveRole(
            99,
            'Syntetická mzdová role',
            'staff',
            true,
            ['payroll.enforcement' => 2],
        );
        $forbiddenRequest = $this->request(
            'POST',
            "/api/payroll/enforcement/cases/{$caseId}/commands/stop",
        )->withAttribute('auth.effective_role', $payrollOnly)
            ->withParsedBody([
                'row_version' => 1,
                'reason' => 'Syntetické zastavení',
                'decision_document_id' => $documentId,
            ]);
        $forbidden = $this->action->transition(
            $forbiddenRequest,
            new Response(),
            ['id' => (string) $caseId, 'command' => 'stop'],
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame(
            1,
            $this->repository->findCase($this->supplierId, $caseId)['row_version'] ?? null,
        );

        $stopped = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$caseId}/commands/stop",
            )->withParsedBody([
                'row_version' => 1,
                'reason' => 'Syntetické zastavení',
                'decision_document_id' => $documentId,
            ]),
            new Response(),
            ['id' => (string) $caseId, 'command' => 'stop'],
        );
        self::assertSame(200, $stopped->getStatusCode(), (string) $stopped->getBody());

        $detail = $this->action->detail(
            $this->request(
                'GET',
                "/api/payroll/enforcement/cases/{$caseId}",
            )->withAttribute('auth.effective_role', $payrollOnly),
            new Response(),
            ['id' => (string) $caseId],
        );
        self::assertSame(200, $detail->getStatusCode());
        $detailCase = PayrollTimeValue::row($this->json($detail)['case'] ?? null, 'case');
        $events = PayrollTimeValue::rows($detailCase['events'] ?? null, 'events');
        self::assertNull($events[0]['decision_document_id'] ?? null);
    }

    public function testDecisionDocumentMustBeVisibleInDmsScope(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $documentId = $this->document(
            $this->supplierId,
            str_repeat('f', 64),
            'user',
            null,
        );
        $scopedRole = new EffectiveRole(
            98,
            'Syntetická mzdová a dokumentová role',
            'staff',
            true,
            ['payroll.enforcement' => 2, 'documents' => 1],
        );
        $response = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$caseId}/commands/stop",
            )->withAttribute('auth.effective_role', $scopedRole)
                ->withParsedBody([
                    'row_version' => 1,
                    'reason' => 'Syntetické zastavení',
                    'decision_document_id' => $documentId,
                ]),
            new Response(),
            ['id' => (string) $caseId, 'command' => 'stop'],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
        self::assertSame(
            1,
            $this->repository->findCase($this->supplierId, $caseId)['row_version'] ?? null,
        );
    }

    public function testMutationRollsBackWhenActivityAuditFails(): void
    {
        $logger = new class($this->db) extends ActivityLogger {
            public function log(
                string $action,
                ?int $userId = null,
                ?string $entityType = null,
                ?int $entityId = null,
                ?array $payload = null,
                ?string $ip = null,
                ?string $userAgent = null,
                ?int $supplierId = null,
            ): void {
                throw new \RuntimeException('Syntetické selhání auditu.');
            }
        };
        $action = new PayrollEnforcementAction(
            $this->repository,
            $this->lifecycle,
            $this->access,
            $logger,
            $this->ipMatcher,
            $this->db,
            $this->documents,
        );
        $before = $this->caseCount();

        try {
            $action->create(
                $this->request('POST', '/api/payroll/enforcement/cases')
                    ->withParsedBody([
                        'employee_id' => $this->employeeId,
                        'case_kind' => 'enforcement',
                        'effective_from' => '2026-06-01',
                    ]),
                new Response(),
            );
            self::fail('Selhání auditu mělo zrušit celou mutaci.');
        } catch (\RuntimeException $e) {
            self::assertSame('Syntetické selhání auditu.', $e->getMessage());
        }

        self::assertSame($before, $this->caseCount());
    }

    public function testMonthEvidenceRejectsStaleRowVersion(): void
    {
        $payload = [
            'claim_register_evidence_complete' => true,
            'dependants_evidence_complete' => true,
            'spouse_evidence_complete' => true,
            'pension_evidence' => 'none',
            'has_multiple_payers' => false,
            'protected_amount_override_minor_units' => null,
            'protected_amount_override_verified' => false,
            'insolvency_mode' => 'none',
            'insolvency_decision_verified' => false,
            'insolvency_recipient_verified' => false,
            'court_determined_amount_minor_units' => null,
        ];
        $created = $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            $payload,
            $this->userId,
            null,
        );
        self::assertSame(1, $created['row_version']);

        $updated = $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            $payload,
            $this->userId,
            1,
        );
        self::assertSame(2, $updated['row_version']);

        try {
            $this->repository->saveMonthEvidence(
                $this->supplierId,
                $this->employeeId,
                '2026-06',
                $payload,
                $this->userId,
                1,
            );
            self::fail('Zastaralá verze měsíčních podkladů měla být odmítnuta.');
        } catch (\MyInvoice\Repository\Payroll\PayrollEnforcementConflictException $e) {
            self::assertSame(2, $e->currentVersion);
        }
    }

    public function testMonthEvidenceAndDependantsCanBeReadAfterReload(): void
    {
        $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => 'none',
                'has_multiple_payers' => false,
                'protected_amount_override_minor_units' => null,
                'protected_amount_override_verified' => false,
                'insolvency_mode' => 'none',
                'insolvency_decision_verified' => false,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ],
            $this->userId,
            null,
        );
        $dependant = $this->repository->addDependant(
            $this->supplierId,
            $this->employeeId,
            [
                'dependant_kind' => 'dependant',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'eligibility_verified' => true,
                'excluded_for_maintenance' => false,
            ],
        );

        $evidenceResponse = $this->action->monthEvidence(
            $this->request(
                'GET',
                "/api/payroll/enforcement/people/{$this->employeeId}/month/2026-06/evidence",
            ),
            new Response(),
            ['employeeId' => (string) $this->employeeId, 'period' => '2026-06'],
        );
        self::assertSame(200, $evidenceResponse->getStatusCode());
        $evidence = PayrollTimeValue::row(
            $this->json($evidenceResponse)['evidence'] ?? null,
            'evidence',
        );
        self::assertSame(2, $evidence['row_version']);
        self::assertFalse($evidence['dependants_evidence_complete']);

        $dependantsResponse = $this->action->dependants(
            $this->request(
                'GET',
                "/api/payroll/enforcement/people/{$this->employeeId}/dependants",
            ),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(200, $dependantsResponse->getStatusCode());
        $dependants = PayrollTimeValue::rows(
            $this->json($dependantsResponse)['dependants'] ?? null,
            'dependants',
        );
        self::assertSame($dependant['id'], $dependants[0]['id']);
    }

    public function testMonthEvidenceRequiresInsolvencyPermission(): void
    {
        $payrollOnly = new EffectiveRole(
            97,
            'Syntetická exekuční role',
            'staff',
            true,
            ['payroll.enforcement' => 2],
        );
        $response = $this->action->monthEvidence(
            $this->request(
                'GET',
                "/api/payroll/enforcement/people/{$this->employeeId}/month/2026-06/evidence",
            )->withAttribute('auth.effective_role', $payrollOnly),
            new Response(),
            ['employeeId' => (string) $this->employeeId, 'period' => '2026-06'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
    }

    public function testApprovedInsolvencyEvidenceRequiresDocumentPermission(): void
    {
        $withoutDocuments = new EffectiveRole(
            95,
            'Syntetická role pro oddlužení bez dokumentů',
            'staff',
            true,
            ['payroll.enforcement' => 2, 'payroll.insolvency' => 2],
        );
        $response = $this->action->saveMonthEvidence(
            $this->request(
                'PUT',
                "/api/payroll/enforcement/people/{$this->employeeId}/month/2026-06/evidence",
            )->withAttribute('auth.effective_role', $withoutDocuments)
                ->withParsedBody(['insolvency_mode' => 'approved_standard']),
            new Response(),
            ['employeeId' => (string) $this->employeeId, 'period' => '2026-06'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
    }

    public function testAddingDependantRequiresInsolvencyPermission(): void
    {
        $payrollOnly = new EffectiveRole(
            96,
            'Syntetická exekuční role bez insolvence',
            'staff',
            true,
            ['payroll.enforcement' => 2],
        );
        $listResponse = $this->action->dependants(
            $this->request(
                'GET',
                "/api/payroll/enforcement/people/{$this->employeeId}/dependants",
            )->withAttribute('auth.effective_role', $payrollOnly),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );
        self::assertSame(403, $listResponse->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($listResponse));

        $response = $this->action->addDependant(
            $this->request(
                'POST',
                "/api/payroll/enforcement/people/{$this->employeeId}/dependants",
            )->withAttribute('auth.effective_role', $payrollOnly)
                ->withParsedBody([
                    'dependant_kind' => 'dependant',
                    'valid_from' => '2026-06-01',
                    'valid_to' => null,
                    'eligibility_verified' => true,
                    'excluded_for_maintenance' => false,
                ]),
            new Response(),
            ['employeeId' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($response));
        self::assertSame(
            [],
            $this->repository->dependantsForEmployee(
                $this->supplierId,
                $this->employeeId,
            ),
        );
    }

    public function testClaimsCanShareOneEnforcementOrderExplicitly(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $payload = [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ];
        $first = $this->repository->addClaim($this->supplierId, $caseId, $payload);
        $this->repository->addClaim($this->supplierId, $caseId, [
            ...$payload,
            'same_order_as_claim_id' => PayrollTimeValue::int($first['id'] ?? null, 'id'),
        ]);

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT enforcement_order_key)
               FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND case_id = ?'
        );
        $stmt->execute([$this->supplierId, $caseId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testInsolvencyVirtualAllocationIsPersistedInSnapshotAndLedger(): void
    {
        [$request, $calculation] = $this->syntheticInsolvencyCalculation();
        $resultId = $this->repository->store(
            $request,
            $calculation,
            null,
            'synthetic-insolvency-result',
        );

        $allocation = $this->db->pdo()->prepare(
            'SELECT allocation_key, case_id, claim_id, total_minor_units
               FROM payroll_enforcement_allocations
              WHERE supplier_id = ? AND month_result_id = ?'
        );
        $allocation->execute([$this->supplierId, $resultId]);
        $allocationRow = PayrollTimeValue::row(
            $allocation->fetch(PDO::FETCH_ASSOC),
            'allocation',
        );
        self::assertSame('insolvency-administrator', $allocationRow['allocation_key']);
        self::assertNull($allocationRow['case_id']);
        self::assertNull($allocationRow['claim_id']);
        self::assertSame(
            10_000,
            PayrollTimeValue::int(
                $allocationRow['total_minor_units'] ?? null,
                'total_minor_units',
            ),
        );

        $ledger = $this->db->pdo()->prepare(
            'SELECT entry_kind, case_id, claim_id, amount_minor_units
               FROM payroll_enforcement_ledger
              WHERE supplier_id = ? AND month_result_id = ?
              ORDER BY id'
        );
        $ledger->execute([$this->supplierId, $resultId]);
        $ledgerRows = $ledger->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['withheld', 'held'], array_column($ledgerRows, 'entry_kind'));
        self::assertSame([null, null], array_column($ledgerRows, 'case_id'));
        self::assertSame([null, null], array_column($ledgerRows, 'claim_id'));
    }

    public function testIdempotencyReplayRejectsDifferentRevision(): void
    {
        [$request, $calculation] = $this->syntheticInsolvencyCalculation();
        $revisionId = $this->payrollRevision();
        $this->repository->store(
            $request,
            $calculation,
            $revisionId,
            'synthetic-revision-bound-result',
        );

        $this->expectException(\DomainException::class);
        $this->repository->store(
            $request,
            $calculation,
            null,
            'synthetic-revision-bound-result',
        );
    }

    public function testPaymentDateSelectsEffectiveCasesAndDependants(): void
    {
        $case = $this->repository->createCase(
            $this->supplierId,
            $this->employeeId,
            'enforcement',
            '2026-07-10',
            $this->userId,
        );
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $this->repository->addClaim($this->supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-07-10',
            'order_issued_on' => '2026-07-09',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ]);
        $this->repository->updateCaseEvidence(
            $this->supplierId,
            $caseId,
            true,
            true,
            2,
            $this->userId,
        );
        $activationHash = str_repeat('d', 64);
        $activationDocumentId = $this->document(
            $this->supplierId,
            $activationHash,
        );
        $this->repository->transition(
            $this->supplierId,
            $caseId,
            \MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand::MarkFinal,
            3,
            null,
            new EnforcementDecisionDocumentReference(
                $activationDocumentId,
                $activationHash,
            ),
            $this->userId,
            $this->lifecycle,
        );

        $this->repository->addDependant($this->supplierId, $this->employeeId, [
            'dependant_kind' => 'dependant',
            'valid_from' => '2026-07-10',
            'valid_to' => null,
            'eligibility_verified' => true,
            'excluded_for_maintenance' => false,
        ]);
        $this->repository->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => 'none',
                'has_multiple_payers' => false,
                'protected_amount_override_minor_units' => null,
                'protected_amount_override_verified' => false,
                'insolvency_mode' => 'none',
                'insolvency_decision_verified' => false,
                'insolvency_recipient_verified' => false,
                'court_determined_amount_minor_units' => null,
            ],
            $this->userId,
            null,
        );

        $evidence = $this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-15',
        );

        self::assertCount(1, $evidence->claims);
        self::assertSame(1, $evidence->eligibleDependants);
    }

    public function testNextPeriodUsesClaimBalanceReducedByPriorWithholding(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $this->repository->addClaim($this->supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ]);
        $this->repository->updateCaseEvidence(
            $this->supplierId,
            $caseId,
            true,
            true,
            2,
            $this->userId,
        );
        $activationHash = str_repeat('f', 64);
        $activationDocumentId = $this->document(
            $this->supplierId,
            $activationHash,
        );
        $this->repository->transition(
            $this->supplierId,
            $caseId,
            \MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand::MarkFinal,
            3,
            null,
            new EnforcementDecisionDocumentReference(
                $activationDocumentId,
                $activationHash,
            ),
            $this->userId,
            $this->lifecycle,
        );
        $claimId = $this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-06-30',
        )->claims[0]->id;
        $request = new EnforcementPersonMonthRequest(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-06-30',
            [],
            true,
        );
        $input = new GarnishmentInput(
            '2026-06',
            '2026-06-30',
            new GarnishableIncomeResult(
                GarnishmentStatus::Supported,
                50_000,
                0,
                [],
                [],
            ),
            [],
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            InsolvencyInstruction::none(),
            false,
            true,
        );
        $resultId = $this->repository->store(
            $request,
            new PayrollGarnishmentCalculation(
                $this->supplierId,
                $this->employeeId,
                $input,
                new GarnishmentResult(
                    '2026-06',
                    GarnishmentStatus::Supported,
                    50_000,
                    20_000,
                    10_000,
                    0,
                    0,
                    30_000,
                    20_000,
                    false,
                    false,
                    [new GarnishmentAllocation($claimId, 30_000, 0)],
                    [],
                    [],
                    'enforcement-2026',
                    str_repeat('f', 64),
                ),
            ),
            null,
            'synthetic-prior-period-balance',
        );
        $allocation = $this->db->pdo()->prepare(
            'SELECT case_id, claim_id
               FROM payroll_enforcement_allocations
              WHERE supplier_id = ? AND month_result_id = ?'
        );
        $allocation->execute([$this->supplierId, $resultId]);
        $allocationRow = PayrollTimeValue::row(
            $allocation->fetch(PDO::FETCH_ASSOC),
            'enforcement_allocation',
        );
        $movement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_ledger
                (supplier_id, case_id, claim_id, month_result_id, entry_kind,
                 amount_minor_units, idempotency_key_hash, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $movement->execute([
            $this->supplierId,
            $allocationRow['case_id'],
            $allocationRow['claim_id'],
            $resultId,
            'released_to_employee',
            10_000,
            hash('sha256', 'synthetic-balance-release', true),
            $this->userId,
        ]);
        $movement->execute([
            $this->supplierId,
            $allocationRow['case_id'],
            $allocationRow['claim_id'],
            $resultId,
            'adjustment',
            -5_000,
            hash('sha256', 'synthetic-balance-adjustment', true),
            $this->userId,
        ]);

        $samePeriod = $this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-06-30',
        );
        $nextPeriod = $this->repository->evidenceFor(
            $this->supplierId,
            $this->employeeId,
            '2026-07',
            '2026-07-31',
        );

        self::assertSame(100_000, $samePeriod->claims[0]->outstandingMinorUnits);
        self::assertSame(85_000, $nextPeriod->claims[0]->outstandingMinorUnits);
    }

    public function testAddingClaimInvalidatesEvidenceAndIsForbiddenAfterActivation(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $payload = [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ];
        $this->repository->addClaim($this->supplierId, $caseId, $payload);
        $this->repository->updateCaseEvidence(
            $this->supplierId,
            $caseId,
            true,
            true,
            2,
            $this->userId,
        );

        $this->repository->addClaim($this->supplierId, $caseId, $payload);
        $invalidated = $this->repository->findCase($this->supplierId, $caseId);
        self::assertNotNull($invalidated);
        self::assertFalse($invalidated['evidence_complete']);
        self::assertSame(4, $invalidated['row_version']);

        $this->repository->updateCaseEvidence(
            $this->supplierId,
            $caseId,
            true,
            true,
            4,
            $this->userId,
        );
        $activationHash = str_repeat('e', 64);
        $activationDocumentId = $this->document(
            $this->supplierId,
            $activationHash,
        );
        $this->repository->transition(
            $this->supplierId,
            $caseId,
            \MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand::MarkFinal,
            5,
            null,
            new EnforcementDecisionDocumentReference(
                $activationDocumentId,
                $activationHash,
            ),
            $this->userId,
            $this->lifecycle,
        );

        $this->expectException(\DomainException::class);
        $this->repository->addClaim($this->supplierId, $caseId, $payload);
    }

    public function testStandaloneMonthResultCannotBeDuplicatedWithAnotherKey(): void
    {
        [$request, $calculation] = $this->syntheticInsolvencyCalculation();
        $this->repository->store(
            $request,
            $calculation,
            null,
            'synthetic-standalone-result-a',
        );

        try {
            $this->repository->store(
                $request,
                $calculation,
                null,
                'synthetic-standalone-result-b',
            );
            self::fail('Stejný samostatný výsledek nesmí vytvořit druhý ledger.');
        } catch (\PDOException|\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testLedgerRejectsInvalidOwnerAndSign(): void
    {
        [$request, $calculation] = $this->syntheticInsolvencyCalculation();
        $resultId = $this->repository->store(
            $request,
            $calculation,
            null,
            'synthetic-ledger-constraints',
        );
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_ledger
                (supplier_id, case_id, claim_id, month_result_id, entry_kind,
                 amount_minor_units, idempotency_key_hash)
             VALUES (?, NULL, NULL, ?, "withheld", -1, ?)'
        );

        $this->expectException(\PDOException::class);
        $stmt->execute([
            $this->supplierId,
            $resultId,
            hash('sha256', 'synthetic-invalid-ledger', true),
        ]);
    }

    public function testLedgerRejectsOverRemittance(): void
    {
        [$request, $calculation] = $this->syntheticInsolvencyCalculation();
        $resultId = $this->repository->store(
            $request,
            $calculation,
            null,
            'synthetic-over-remittance',
        );
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_ledger
                (supplier_id, case_id, claim_id, month_result_id, entry_kind,
                 amount_minor_units, idempotency_key_hash)
             VALUES (?, NULL, NULL, ?, "remitted", 10001, ?)'
        );

        $this->expectException(\PDOException::class);
        $stmt->execute([
            $this->supplierId,
            $resultId,
            hash('sha256', 'synthetic-over-remittance-entry', true),
        ]);
    }

    public function testStartedCasesAndClaimsCannotBeHardDeleted(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $claim = $this->repository->addClaim($this->supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => true,
            'order_or_notice_delivered' => true,
            'priority_classification_verified' => true,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => true,
        ]);
        $claimId = PayrollTimeValue::int($claim['id'] ?? null, 'id');
        $this->db->pdo()->prepare(
            "UPDATE payroll_enforcement_cases
                SET status = 'withhold_and_hold'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $caseId]);

        try {
            $this->db->pdo()->prepare(
                'DELETE FROM payroll_enforcement_claims WHERE supplier_id = ? AND id = ?'
            )->execute([$this->supplierId, $claimId]);
            self::fail('Pohledávka nesmí být fyzicky smazána.');
        } catch (\PDOException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_enforcement_cases WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $caseId]);
    }

    public function testUnusedReceivedCaseCanBeDeletedAfterDraftEvidenceWasSaved(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $changed = $this->repository->updateCaseEvidence(
            $this->supplierId,
            $caseId,
            false,
            true,
            1,
            $this->userId,
        );
        $rowVersion = PayrollTimeValue::int($changed['row_version'] ?? null, 'row_version');

        $response = $this->deleteCase($caseId, $rowVersion);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue((bool) ($this->json($response)['deleted'] ?? false));
        self::assertNull($this->repository->findCase($this->supplierId, $caseId));

        $audit = $this->db->pdo()->prepare(
            'SELECT action FROM activity_log
              WHERE supplier_id = ? AND entity_type = ? AND entity_id = ?
              ORDER BY id'
        );
        $audit->execute([$this->supplierId, 'payroll_enforcement_case', $caseId]);
        self::assertSame([
            'payroll.enforcement.case.created',
            'payroll.enforcement.case.deleted',
        ], $audit->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testDeleteFailsClosedForClaimAndStaleVersion(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');

        $stale = $this->deleteCase($caseId, 2);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($stale));

        $this->repository->addClaim($this->supplierId, $caseId, [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => false,
            'order_or_notice_delivered' => false,
            'priority_classification_verified' => false,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => false,
        ]);
        $blocked = $this->deleteCase($caseId, 2);
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame('enforcement_case_delete_blocked', $this->errorCode($blocked));
        self::assertStringContainsString('pohledávku', (string) $blocked->getBody());
        self::assertNotNull($this->repository->findCase($this->supplierId, $caseId));
    }

    public function testDeleteRequiresSessionAndTenantOwnership(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');

        $bearer = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}",
                authMethod: 'bearer',
            )->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $caseId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $foreign = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}",
                supplierId: $this->otherSupplierId,
            )->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $caseId],
        );
        self::assertSame(404, $foreign->getStatusCode());
        self::assertNotNull($this->repository->findCase($this->supplierId, $caseId));
    }

    public function testUnusedClaimCanBeCorrectedAndThenDeletedWithAuditTrail(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $claim = $this->createClaim($caseId);
        $claimId = PayrollTimeValue::int($claim['id'] ?? null, 'id');

        $updated = $this->action->updateClaim(
            $this->request(
                'PUT',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
            )->withParsedBody([
                ...$this->claimBody(),
                'outstanding_minor_units' => 125_000,
                'priority_date' => '2026-05-21',
                'row_version' => 1,
            ]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        $updatedClaim = PayrollTimeValue::row(
            $this->json($updated)['claim'] ?? null,
            'claim',
        );
        self::assertSame(125_000, $updatedClaim['outstanding_minor_units']);
        self::assertSame('2026-05-21', $updatedClaim['priority_date']);
        self::assertSame(2, $updatedClaim['row_version']);

        $deleted = $this->action->deleteClaim(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
            )->withParsedBody(['row_version' => 2]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $deletedPayload = $this->json($deleted);
        self::assertTrue((bool) ($deletedPayload['deleted'] ?? false));
        self::assertSame(4, $deletedPayload['case_row_version']);

        $detail = $this->repository->findCase($this->supplierId, $caseId);
        self::assertNotNull($detail);
        self::assertSame(0, $detail['claim_count']);
        self::assertSame([], $detail['claims']);

        $caseDelete = $this->deleteCase($caseId, 4);
        self::assertSame(200, $caseDelete->getStatusCode(), (string) $caseDelete->getBody());

        $audit = $this->db->pdo()->prepare(
            'SELECT action FROM activity_log
              WHERE supplier_id = ? AND entity_type = ? AND entity_id = ?
              ORDER BY id'
        );
        $audit->execute([$this->supplierId, 'payroll_enforcement_claim', $claimId]);
        self::assertSame([
            'payroll.enforcement.claim.created',
            'payroll.enforcement.claim.updated',
            'payroll.enforcement.claim.deleted',
        ], $audit->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testClaimMutationFailsClosedAfterActivationOrPayrollSnapshot(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $claim = $this->createClaim($caseId);
        $claimId = PayrollTimeValue::int($claim['id'] ?? null, 'id');

        $this->db->pdo()->prepare(
            "UPDATE payroll_enforcement_cases
                SET status = 'withhold_and_hold'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $caseId]);

        $blockedUpdate = $this->updateClaim($caseId, $claimId, 1);
        self::assertSame(409, $blockedUpdate->getStatusCode());
        self::assertSame('enforcement_claim_change_blocked', $this->errorCode($blockedUpdate));
        $blockedDelete = $this->deleteClaim($caseId, $claimId, 1);
        self::assertSame(409, $blockedDelete->getStatusCode());
        self::assertSame('enforcement_claim_change_blocked', $this->errorCode($blockedDelete));

        $this->db->pdo()->prepare(
            "UPDATE payroll_enforcement_cases
                SET status = 'received'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $caseId]);
        $claimKeyStatement = $this->db->pdo()->prepare(
            'SELECT claim_key FROM payroll_enforcement_claims
              WHERE supplier_id = ? AND id = ?'
        );
        $claimKeyStatement->execute([$this->supplierId, $claimId]);
        $claimKey = (string) $claimKeyStatement->fetchColumn();
        $snapshot = json_encode([
            'claims' => [['id' => $claimKey]],
        ], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_month_results
                (supplier_id, revision_id, employee_id, period_start,
                 result_status, ruleset_id, ruleset_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 total_withheld_minor_units, employee_payment_minor_units,
                 employer_fee_minor_units, idempotency_key_hash)
             VALUES (?, NULL, ?, "2026-06-01", "supported", "synthetic", ?, ?, ?,
                     "{}", ?, 0, 0, 0, ?)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            str_repeat('a', 64),
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', '{}'),
            hash('sha256', 'synthetic-claim-footprint', true),
        ]);

        $payrollBlocked = $this->deleteClaim($caseId, $claimId, 1);
        self::assertSame(409, $payrollBlocked->getStatusCode());
        self::assertSame('enforcement_claim_change_blocked', $this->errorCode($payrollBlocked));
        self::assertNotNull($this->repository->findCase($this->supplierId, $caseId));
    }

    public function testClaimMutationRequiresFreshVersionSessionAndTenantOwnership(): void
    {
        $case = $this->createCase($this->employeeId);
        $caseId = PayrollTimeValue::int($case['id'] ?? null, 'id');
        $claim = $this->createClaim($caseId);
        $claimId = PayrollTimeValue::int($claim['id'] ?? null, 'id');

        $stale = $this->updateClaim($caseId, $claimId, 2);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($stale));

        $bearer = $this->action->deleteClaim(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
                authMethod: 'bearer',
            )->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $foreign = $this->action->deleteClaim(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
                supplierId: $this->otherSupplierId,
            )->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
        self::assertSame(404, $foreign->getStatusCode());
        self::assertNotNull($this->repository->findCase($this->supplierId, $caseId));
    }

    /** @return array<string,mixed> */
    private function createCase(int $employeeId, ?int $supplierId = null): array
    {
        $request = $this->request(
            'POST',
            '/api/payroll/enforcement/cases',
            supplierId: $supplierId,
        )->withParsedBody([
            'employee_id' => $employeeId,
            'case_kind' => 'enforcement',
            'effective_from' => '2026-05-20',
        ]);
        $response = $this->action->create($request, new Response());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return PayrollTimeValue::row($this->json($response)['case'] ?? null, 'case');
    }

    private function deleteCase(int $caseId, int $rowVersion): ResponseInterface
    {
        return $this->action->delete(
            $this->request('DELETE', "/api/payroll/enforcement/cases/{$caseId}")
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $caseId],
        );
    }

    /** @return array<string,mixed> */
    private function createClaim(int $caseId): array
    {
        $response = $this->action->addClaim(
            $this->request('POST', "/api/payroll/enforcement/cases/{$caseId}/claims")
                ->withParsedBody($this->claimBody()),
            new Response(),
            ['id' => (string) $caseId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return PayrollTimeValue::row($this->json($response)['claim'] ?? null, 'claim');
    }

    /** @return array<string,mixed> */
    private function claimBody(): array
    {
        return [
            'legal_basis' => 'statutory',
            'category' => 'non_priority',
            'outstanding_minor_units' => 100_000,
            'maintenance_weight_minor_units' => null,
            'priority_date' => '2026-05-20',
            'order_issued_on' => '2026-05-19',
            'legal_title_verified' => false,
            'order_or_notice_delivered' => false,
            'priority_classification_verified' => false,
            'agreement_verified' => false,
            'due_monetary_claim_verified' => false,
        ];
    }

    private function updateClaim(
        int $caseId,
        int $claimId,
        int $rowVersion,
    ): ResponseInterface {
        return $this->action->updateClaim(
            $this->request(
                'PUT',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
            )->withParsedBody([
                ...$this->claimBody(),
                'row_version' => $rowVersion,
            ]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
    }

    private function deleteClaim(
        int $caseId,
        int $claimId,
        int $rowVersion,
    ): ResponseInterface {
        return $this->action->deleteClaim(
            $this->request(
                'DELETE',
                "/api/payroll/enforcement/cases/{$caseId}/claims/{$claimId}",
            )->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $caseId, 'claimId' => (string) $claimId],
        );
    }

    /** @return array<string,mixed> */
    private function transition(
        int $caseId,
        string $command,
        int $version,
        ?int $decisionDocumentId = null,
    ): array
    {
        $body = ['row_version' => $version];
        if ($decisionDocumentId !== null) {
            $body['decision_document_id'] = $decisionDocumentId;
        }
        $response = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/enforcement/cases/{$caseId}/commands/{$command}",
            )->withParsedBody($body),
            new Response(),
            ['id' => (string) $caseId, 'command' => $command],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return PayrollTimeValue::row($this->json($response)['case'] ?? null, 'case');
    }

    private function employee(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        );
        $stmt->execute([$supplierId, $name]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function document(
        int $supplierId,
        string $sha256,
        string $scope = 'company',
        ?int $ownerUserId = null,
    ): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope, owner_user_id)
             VALUES (?, 'Syntetické rozhodnutí', 'decision.pdf', ?, ?, 'application/pdf',
                     1, 'pdf', 'manual', ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId,
            $sha256 . '.pdf',
            $sha256,
            $this->userId,
            $scope,
            $ownerUserId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{EnforcementPersonMonthRequest,PayrollGarnishmentCalculation}
     */
    private function syntheticInsolvencyCalculation(): array
    {
        $request = new EnforcementPersonMonthRequest(
            $this->supplierId,
            $this->employeeId,
            '2026-06',
            '2026-07-15',
            [],
            true,
        );
        $input = new GarnishmentInput(
            '2026-06',
            '2026-07-15',
            new GarnishableIncomeResult(
                GarnishmentStatus::Supported,
                20_000,
                0,
                [],
                [],
            ),
            [],
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            new InsolvencyInstruction(InsolvencyMode::ApprovedStandard, true, true),
            false,
            true,
        );
        return [
            $request,
            new PayrollGarnishmentCalculation(
                $this->supplierId,
                $this->employeeId,
                $input,
                new GarnishmentResult(
                    '2026-06',
                    GarnishmentStatus::Supported,
                    20_000,
                    10_000,
                    10_000,
                    0,
                    0,
                    10_000,
                    10_000,
                    false,
                    true,
                    [new GarnishmentAllocation('insolvency-administrator', 10_000, 0)],
                    [],
                    [],
                    'enforcement-2026',
                    str_repeat('d', 64),
                ),
            ),
        ];
    }

    private function payrollRevision(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, "2026-06-01", "2026-06-30")'
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated",
                     "payroll-run-input.v1", ?, "{}", ?, ?)'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            hash('sha256', 'synthetic-enforcement-revision', true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")'
        )->execute([$this->supplierId, $revisionId, $this->employeeId]);
        return $revisionId;
    }

    private function caseCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_enforcement_cases WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : PayrollTimeValue::int($value, "{$table}.id");
    }

    private function request(
        string $method,
        string $uri,
        string $authMethod = 'session',
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = PayrollTimeValue::row(
            $this->json($response)['error'] ?? null,
            'error',
        );
        return PayrollTimeValue::string($error['code'] ?? null, 'error.code');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return PayrollTimeValue::row(
            json_decode((string) $response->getBody(), true),
            'response',
        );
    }
}
