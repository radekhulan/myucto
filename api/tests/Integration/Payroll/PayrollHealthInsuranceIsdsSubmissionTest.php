<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Submission\IsdsGatewayAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollHealthInsuranceIsdsSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const INSURER = '205';
    private const XML = '<?xml version="1.0" encoding="UTF-8"?><Pehled><KodPojistovny>205</KodPojistovny></Pehled>';
    private const PDF = "%PDF-1.4\n% synthetic extractable payroll overview\n%%EOF";

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private SubmissionOutboxService $outbox;
    private HealthInsuranceIsdsSubmissionService $isds;
    private IsdsGatewayAction $gatewayAction;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        $outbox = $container->get(SubmissionOutboxService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        self::assertInstanceOf(SubmissionOutboxService::class, $outbox);
        $this->outbox = $outbox;

        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')
            ->fetchColumn();
        self::assertGreaterThan(0, $this->userId);

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-25 12:00:00 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->isds = new HealthInsuranceIsdsSubmissionService(
            $repository,
            new SubmissionRecipientRepository($connection),
            $outbox,
            new HealthInsurerChannelCatalog(),
            $clock,
        );
        $this->gatewayAction = $container->get(IsdsGatewayAction::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testValidatedXmlIsPreparedForTheDocumentedInsurerBox(): void
    {
        $submissionId = $this->readySubmission('a');

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );

        self::assertTrue($result['created']);
        self::assertSame('mk5ab8i', $result['recipient']['box_id']);
        self::assertSame('isds', $result['row']['channel']);
        self::assertSame(
            'PPPZ 2026-07 — zdravotní pojišťovna 205',
            $result['subject'],
        );
        self::assertSame(
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            $result['row']['agenda_code'],
        );
        self::assertSame('ready', $result['row']['dispatch_state']);
        self::assertNull($result['row']['confirmed_by']);
        self::assertSame(hash('sha256', self::XML), $result['attachment']['sha256']);
        self::assertSame('xml', $result['attachment']['format']);
    }

    public function testRepeatedPreparationDoesNotCreateAnotherMessage(): void
    {
        $submissionId = $this->readySubmission('b');

        $first = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );
        $second = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['outbox_id'], $second['outbox_id']);
    }

    public function testManualIsdsSendMarksPayrollSubmissionSubmitted(): void
    {
        $submissionId = $this->readySubmission('manual-sent');
        $queued = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );

        $result = $this->outbox->markSentManually(
            $this->supplierId,
            (int) $queued['outbox_id'],
            $this->userId,
            '123456789',
        );

        self::assertTrue($result['recorded']);
        self::assertSame('sent', $result['row']['dispatch_state']);
        $submission = $this->submissions->get(
            $this->supplierId,
            $submissionId,
        );
        self::assertSame('submitted', $submission['status']);
        self::assertSame(
            '123456789',
            $submission['correlation_reference'],
        );
    }

    public function testOfficialCodebookContainsAllSevenInsurers(): void
    {
        $rows = $this->db->pdo()->query(
            "SELECT code, business_id, address, isds_box_id
               FROM submission_recipients
              WHERE supplier_id IS NULL AND kind = 'health_insurer'
              ORDER BY RIGHT(code, 3)",
        )->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(7, $rows);
        self::assertSame(
            HealthInsurers::codes(),
            array_map(
                static fn (array $row): string => substr(
                    (string) $row['code'],
                    -3,
                ),
                $rows,
            ),
        );
        self::assertSame(
            [
                'i48ae3q', 'uhff5yj', 'mk5ab8i', 'q9iadw9',
                '5kpadkp', '9swaix3', 'edyadmh',
            ],
            array_values(array_column($rows, 'isds_box_id')),
        );
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/^[0-9]{8}$/D', (string) $row['business_id']);
            self::assertNotSame('', trim((string) $row['address']));
        }
    }

    public function testCompanyCanOverrideTheSystemRecipient(): void
    {
        $recipients = new SubmissionRecipientRepository($this->db);
        $recipients->upsertForSupplier(
            $this->supplierId,
            [
                'code' => 'zp_cpzp_205',
                'name' => 'ČPZP — firemně ověřený adresát',
                'business_id' => '47672234',
                'address' => 'Syntetická firemní evidence',
                'kind' => 'health_insurer',
                'isds_box_id' => 'zzzzzzz',
                'source_url' => null,
                'source_note' => null,
                'is_active' => true,
            ],
            null,
        );

        $visible = $recipients->listVisible($this->supplierId, 'health_insurer');
        self::assertCount(7, $visible);
        $overrides = array_values(array_filter(
            $visible,
            static fn (array $row): bool => $row['code'] === 'zp_cpzp_205',
        ));
        self::assertCount(1, $overrides);
        self::assertSame($this->supplierId, (int) $overrides[0]['supplier_id']);
        self::assertSame('zzzzzzz', $overrides[0]['isds_box_id']);

        $result = $this->isds->enqueue(
            $this->supplierId,
            $this->readySubmission('override'),
            self::INSURER,
            null,
        );

        self::assertSame('zzzzzzz', $result['recipient']['box_id']);
        self::assertSame(
            'ČPZP — firemně ověřený adresát',
            $result['recipient']['name'],
        );
    }

    public function testPayrollGatewayPermissionAcceptsPpzOutbox(): void
    {
        $queued = $this->isds->enqueue(
            $this->supplierId,
            $this->readySubmission('gateway'),
            self::INSURER,
            null,
        );
        $outboxId = (int) $queued['outbox_id'];
        $role = new EffectiveRole(2, 'Mzdová účetní', 'staff', true, [
            'payroll.submissions' => 2,
        ]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/isds-gateway/outbox/' . $outboxId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 777, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);

        $response = $this->gatewayAction->payrollStart(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $outboxId],
        );
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'payroll_production_qualification_required',
            $body['error']['code'] ?? null,
        );

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        $response = $this->gatewayAction->payrollStart(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $outboxId],
        );
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertNotSame(403, $response->getStatusCode());
        self::assertNotSame(
            'payroll_gateway_outbox_forbidden',
            $body['error']['code'] ?? null,
        );
    }

    public function testDifferentInsurerCannotAdoptTheSubmission(): void
    {
        $submissionId = $this->readySubmission('c');

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('nepatří zvolené zdravotní pojišťovně');
        $this->isds->enqueue($this->supplierId, $submissionId, '207', null);
    }

    public function testVzpUsesTheExtractablePdf(): void
    {
        $submissionId = $this->readySubmission('d', '111');

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            '111',
            null,
        );

        self::assertSame('i48ae3q', $result['recipient']['box_id']);
        self::assertSame('application/pdf', $result['attachment']['mime']);
        self::assertSame('text_pdf', $result['attachment']['format']);
    }

    public function testVozpEmployerPaymentOverviewUsesTheDocumentedOlomoucBox(): void
    {
        $result = $this->isds->enqueue(
            $this->supplierId,
            $this->readySubmission('vozp-pdf', '201'),
            '201',
            null,
        );

        self::assertSame('uhff5yj', $result['recipient']['box_id']);
        self::assertSame('application/pdf', $result['attachment']['mime']);
        self::assertSame('text_pdf', $result['attachment']['format']);
    }

    public function testZpSkodaUsesTheExtractablePdf(): void
    {
        $submissionId = $this->readySubmission('pdf', '209');

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            '209',
            null,
        );

        self::assertSame('5kpadkp', $result['recipient']['box_id']);
        self::assertSame('application/pdf', $result['attachment']['mime']);
        self::assertSame('text_pdf', $result['attachment']['format']);
        self::assertSame(hash('sha256', self::PDF), $result['attachment']['sha256']);
    }

    public function testRecipientDatabaseIsTheSingleRuntimeSourceOfTruth(): void
    {
        $submissionId = $this->readySubmission('e');
        $this->db->pdo()->prepare(
            "UPDATE submission_recipients SET isds_box_id = 'aaaaaaa'
              WHERE supplier_id IS NULL AND code = 'zp_cpzp_205'",
        )->execute();

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );

        self::assertSame('aaaaaaa', $result['recipient']['box_id']);
    }

    public function testAnotherSupplierCannotEnqueueForeignSubmission(): void
    {
        $foreignSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            $this->supplierId,
        );
        $submissionId = $this->readySubmission('foreign-tenant');

        try {
            $this->isds->enqueue(
                $foreignSupplierId,
                $submissionId,
                self::INSURER,
                null,
            );
            self::fail('Cizí tenant nesmí zařadit podání do ISDS.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame(
                'health_submission_not_found',
                $exception->errorCode,
            );
        }
    }

    private function readySubmission(string $key, string $insurer = self::INSURER): int
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            'payroll_run',
            'payroll_run:synthetic:' . $insurer,
            '2026-07-01',
            '2026-07-31',
            'regular',
            'health_portal',
            HealthInsuranceSubmissionService::SOURCE_EVENT_OVERVIEW,
            'synthetic-health-overview:' . $key . ':' . $insurer,
            str_repeat('a', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'health-overview-test',
            str_repeat('b', 64),
            'health-isds-obligation:' . $key . ':' . $insurer,
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'health_portal',
            str_repeat('c', 64),
            'health-isds-submission:' . $key . ':' . $insurer,
            environment: 'production',
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            (int) $submission['id'],
            (int) $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            self::XML,
            '2026.1',
            null,
            'health_portal',
            'health-isds-artifact:' . $key . ':' . $insurer,
        );
        $pdfArtifact = $this->submissions->storeArtifact(
            $this->supplierId,
            (int) $submission['id'],
            (int) $artifact['submission_row_version'],
            null,
            'outbound_pdf',
            'outbound',
            'application/pdf',
            self::PDF,
            null,
            null,
            'health_portal',
            'health-isds-pdf-artifact:' . $key . ':' . $insurer,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            (int) $submission['id'],
            (int) $pdfArtifact['submission_row_version'],
            'validated',
        );
        $this->submissions->transition(
            $this->supplierId,
            (int) $submission['id'],
            (int) $validated['row_version'],
            'ready',
        );

        return (int) $submission['id'];
    }
}
