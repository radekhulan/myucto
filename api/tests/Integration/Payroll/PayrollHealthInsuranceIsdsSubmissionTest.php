<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Submission\IsdsGatewayAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
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
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use Psr\Log\NullLogger;
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
    private MockClock $clock;
    private \Psr\Container\ContainerInterface $container;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->container = $container;
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
        $this->clock = new MockClock('2026-08-25 12:00:00 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $this->clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $this->clock,
        );
        $this->isds = new HealthInsuranceIsdsSubmissionService(
            $repository,
            new SubmissionRecipientRepository($connection),
            $outbox,
            new HealthInsurerChannelCatalog(),
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

    /**
     * Přehled o platbě je podaný dodáním do schránky pojišťovny; pojišťovna
     * výsledek neposílá. Doručení proto samo splní povinnost — dřív zůstala
     * „odeslaná" navždy a měsíc šlo uzavřít jen ručně.
     */
    public function testDeliveryOfPaymentOverviewFulfilsTheObligation(): void
    {
        $submissionId = $this->sentSubmission('delivered');
        self::assertSame('submitted', $this->obligationStatus($submissionId));

        $outboxId = $this->outboxIdOf($submissionId);
        $row = $this->outbox->applyStatus(
            $this->supplierId,
            $outboxId,
            ChannelStatus::deliveredOnly(new \DateTimeImmutable('+1 second')),
        );

        self::assertSame('delivered', $row['dispatch_state']);
        self::assertSame('fulfilled', $this->obligationStatus($submissionId));
        // Výrok úřadu se nevymýšlí: osa vyřízení i stav podání zůstávají.
        self::assertSame('unknown', $row['acceptance_state']);
        self::assertSame(
            'submitted',
            $this->submissions->get($this->supplierId, $submissionId)['status'],
        );
    }

    public function testHozDeliveryFulfilsTheObligationToo(): void
    {
        $submissionId = $this->readyBulkNotificationSubmission('hoz-delivered');
        $queued = $this->isds->enqueue($this->supplierId, $submissionId, self::INSURER, null);
        $this->outbox->markSentManually(
            $this->supplierId,
            (int) $queued['outbox_id'],
            $this->userId,
            '123456790',
        );

        $this->outbox->applyStatus(
            $this->supplierId,
            (int) $queued['outbox_id'],
            ChannelStatus::deliveredOnly(new \DateTimeImmutable('+1 second')),
        );

        self::assertSame('fulfilled', $this->obligationStatus($submissionId));
    }

    /**
     * Firma s více pojišťovnami: JEDNA autorizace (jedna relace Mobilního
     * klíče) pošle přehledy všem třem pojišťovnám, každou zprávu jiné
     * schránce. Po doručení jsou splněné všechny tři povinnosti.
     */
    public function testOneAuthorisationSendsOverviewsToThreeInsurersAndDeliveryFulfilsAll(): void
    {
        $transport = new FakeIsdsTransport();
        $transport->incrementMessageIds = true;
        $container = $this->container;
        $sender = new SubmissionOutboxService(
            new SubmissionOutboxRepository($this->db),
            new SubmissionOutboxAttemptRepository($this->db),
            new SubmissionRecipientRepository($this->db),
            new SubmissionChannelRegistry(
                $container->get(EpoChannel::class),
                new IsdsChannel($transport),
            ),
            $container->get(SubmissionArtifactResolver::class),
            $container->get(SubmissionArtifactValidator::class),
            new NullLogger(),
            $container->get(PayrollSubmissionDispatchProjection::class),
        );

        $outboxIds = [];
        $submissionIds = [];
        foreach (['205', '207', '213'] as $insurer) {
            $submissionId = $this->readySubmission('batch-' . $insurer, $insurer);
            $queued = $this->isds->enqueue($this->supplierId, $submissionId, $insurer, $this->userId);
            $submissionIds[] = $submissionId;
            $outboxIds[] = (int) $queued['outbox_id'];
        }

        $results = $sender->confirmAndSendBatch(
            $this->supplierId,
            $outboxIds,
            $this->userId,
            new ChannelContext($this->supplierId, 'production', new ChannelCredentials('zzzzzzz', 'certificate')),
        );

        self::assertCount(3, $results);
        foreach ($results as $result) {
            self::assertTrue($result['dispatched'], (string) $result['error_message']);
        }
        self::assertCount(3, $transport->sentMessages, 'Každá pojišťovna dostane svou zprávu.');
        self::assertCount(
            3,
            array_unique(array_map(
                static fn (array $row): string => (string) $row['recipient_box_id'],
                array_column($results, 'row'),
            )),
        );

        foreach ($outboxIds as $outboxId) {
            $sender->applyStatus(
                $this->supplierId,
                $outboxId,
                ChannelStatus::deliveredOnly(new \DateTimeImmutable('+1 second')),
            );
        }
        foreach ($submissionIds as $submissionId) {
            self::assertSame('fulfilled', $this->obligationStatus($submissionId));
        }
    }

    /** Odeslání bez doloženého dodání povinnost neuzavře. */
    public function testSentWithoutDeliveryKeepsTheObligationOpen(): void
    {
        $submissionId = $this->sentSubmission('sent-only');

        $this->outbox->applyStatus(
            $this->supplierId,
            $this->outboxIdOf($submissionId),
            ChannelStatus::sentOnly(),
        );

        self::assertSame('submitted', $this->obligationStatus($submissionId));
    }

    /**
     * Podání doručená před zavedením automatického uzavření dorovná migrace
     * 1955 — jinak by u nich povinnost visela „odeslaná" navždy.
     */
    public function testBackfillMigrationFulfilsAlreadyDeliveredOverview(): void
    {
        $delivered = $this->sentSubmission('backfill-delivered');
        $sentOnly = $this->sentSubmission('backfill-sent', '207');
        $outboxId = $this->outboxIdOf($delivered);
        $row = (new SubmissionOutboxRepository($this->db))->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        // Doručení zapsané přímo, bez služby — tak, jak ho zapsala starší verze.
        (new SubmissionOutboxRepository($this->db))->markDelivered(
            $this->supplierId,
            $outboxId,
            new \DateTimeImmutable('+1 second'),
            (int) $row['row_version'],
        );
        self::assertSame('submitted', $this->obligationStatus($delivered));

        $sql = (string) file_get_contents(
            \dirname(__DIR__, 4) . '/db/migrations/1955_payroll_health_delivery_fulfils_obligation.sql',
        );
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->pdo()->exec($statement);
        }
        // Druhý běh nic nezmění (idempotence).
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->pdo()->exec($statement);
        }

        self::assertSame('fulfilled', $this->obligationStatus($delivered));
        self::assertSame('submitted', $this->obligationStatus($sentOnly));
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
            'payroll_setup_incomplete',
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

    public function testRbpUsesTheFrozenXmlAttachment(): void
    {
        $result = $this->isds->enqueue(
            $this->supplierId,
            $this->readySubmission('rbp-xml', '213'),
            '213',
            null,
        );

        self::assertSame('edyadmh', $result['recipient']['box_id']);
        self::assertSame('application/xml', $result['attachment']['mime']);
        self::assertSame('xml', $result['attachment']['format']);
    }

    public function testEnqueueKeepsTheFormatFrozenWhenTheCalendarMoves(): void
    {
        $submissionId = $this->readySubmission('frozen-vzp-pdf', '111');
        $this->clock->modify('+130 days');

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            '111',
            null,
        );

        self::assertSame('application/pdf', $result['attachment']['mime']);
        self::assertSame('text_pdf', $result['attachment']['format']);
    }

    public function testLegacyUnfrozenAttachmentFailsClosed(): void
    {
        $submissionId = $this->readySubmission('legacy-unfrozen', '205', false);

        try {
            $this->isds->enqueue($this->supplierId, $submissionId, '205', null);
            self::fail('Staré podání bez zmrazeného formátu se nesmí odhadovat.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame(
                'health_submission_attachment_unfrozen',
                $exception->errorCode,
            );
        }
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

    /**
     * Jádro rozšíření zadání: HOZ šlo dřív jen stáhnout, teď se zařazuje do
     * ISDS fronty stejnou cestou jako PPZ — jen s jiným předmětem zprávy
     * (`HOZ`, ne `PPPZ`, protože ta zkratka je doložená jen pro přehled).
     */
    public function testBulkNotificationCanBeEnqueuedIntoIsdsQueueTooWithDistinctSubject(): void
    {
        $submissionId = $this->readyBulkNotificationSubmission('hoz-a');

        $result = $this->isds->enqueue(
            $this->supplierId,
            $submissionId,
            self::INSURER,
            null,
        );

        self::assertSame('mk5ab8i', $result['recipient']['box_id']);
        self::assertStringStartsWith('HOZ ', $result['subject']);
        self::assertStringNotContainsString('PPPZ', $result['subject']);
        self::assertSame(
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            $result['row']['agenda_code'],
        );
    }

    public function testBulkNotificationForAnotherInsurerCannotBeAdopted(): void
    {
        $submissionId = $this->readyBulkNotificationSubmission('hoz-b');

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('nepatří zvolené zdravotní pojišťovně');
        $this->isds->enqueue($this->supplierId, $submissionId, '207', null);
    }

    private function sentSubmission(string $key, string $insurer = self::INSURER): int
    {
        $submissionId = $this->readySubmission($key, $insurer);
        $queued = $this->isds->enqueue($this->supplierId, $submissionId, $insurer, null);
        $this->outbox->markSentManually(
            $this->supplierId,
            (int) $queued['outbox_id'],
            $this->userId,
            (string) random_int(100000000, 999999999),
        );

        return $submissionId;
    }

    private function outboxIdOf(int $submissionId): int
    {
        $row = (new PayrollSubmissionRepository($this->db))->findDispatchOutboxForSubmission(
            $this->supplierId,
            'production',
            $submissionId,
        );
        self::assertNotNull($row);

        return (int) $row['id'];
    }

    private function obligationStatus(int $submissionId): string
    {
        $obligation = (new PayrollSubmissionRepository($this->db))->findObligationOfSubmission(
            $this->supplierId,
            'production',
            $submissionId,
        );
        self::assertNotNull($obligation);

        return $obligation['status'];
    }

    private function readyBulkNotificationSubmission(
        string $key,
        string $insurer = self::INSURER,
    ): int {
        $obligation = $this->obligations->register(
            $this->supplierId,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            'employer',
            'health_bulk_notification:2026-07:' . $insurer,
            '2026-07-01',
            '2026-07-31',
            'regular',
            'health_portal',
            HealthInsuranceSubmissionService::SOURCE_EVENT_BULK_NOTIFICATION,
            'synthetic-hoz:' . $key . ':' . $insurer,
            str_repeat('a', 64),
            '2026-07-05',
            '2026-07-13',
            'calendar_days',
            'health-bulk-notification-test',
            str_repeat('b', 64),
            'health-hoz-isds-obligation:' . $key . ':' . $insurer,
            environment: 'production',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'health_portal',
            str_repeat('c', 64),
            'health-hoz-isds-submission:' . $key . ':' . $insurer,
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
            $insurer === '205' || $insurer === '207' || $insurer === '213'
                ? 'health-isds-attachment.v1:xml'
                : null,
            'health_portal',
            'health-hoz-isds-artifact:' . $key . ':' . $insurer,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            (int) $submission['id'],
            (int) $artifact['submission_row_version'],
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

    private function readySubmission(
        string $key,
        string $insurer = self::INSURER,
        bool $freezeAttachment = true,
    ): int
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
            $freezeAttachment
                && ($insurer === '205' || $insurer === '207' || $insurer === '213')
                ? 'health-isds-attachment.v1:xml'
                : null,
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
            $freezeAttachment
                && in_array($insurer, ['111', '201', '209', '211'], true)
                ? 'health-isds-attachment.v1:text_pdf'
                : null,
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
