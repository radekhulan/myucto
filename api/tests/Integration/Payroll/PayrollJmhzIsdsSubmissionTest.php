<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Submission\IsdsGatewayAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsInboxProcessor;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionDispatchProjection;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\Channel\InboxMessageHeader;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\SyntheticZfoBuilder;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Clock\MockClock;

/**
 * Zařazení zmrazeného JMHZ do fronty podání datovou schránkou.
 *
 * Ověřuje se to, co se nedá ověřit jednotkově: že se podání opravdu propíše do
 * OBECNÉ fronty (a tedy pokračuje existující ruční cestou s doručenkou), že
 * míří na doloženou schránku a že opakované zařazení nevyrobí druhé podání.
 */
#[Group('integration')]
final class PayrollJmhzIsdsSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'isds';
    private const GUID = '01912B4C-7A3E-7C21-9F55-0A1B2C3D4E5F';
    private const VARIABLE_SYMBOL = '1234567890';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private SubmissionOutboxService $outboxService;
    private JmhzIsdsSubmissionService $isds;
    private IsdsGatewayAction $gatewayAction;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        $outbox = $container->get(SubmissionOutboxService::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        self::assertInstanceOf(SubmissionOutboxService::class, $outbox);
        $this->outboxService = $outbox;

        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $source = (int) $sourceStatement->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->isds = new JmhzIsdsSubmissionService(
            new JmhzFrozenPayloadReader($repository, $this->submissions),
            $repository,
            new SubmissionRecipientRepository($connection),
            $outbox,
        );
        $this->gatewayAction = $container->get(IsdsGatewayAction::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Testovací prostředí musí jít do TESTOVACÍ schránky. Kdyby cvičné podání
     * odešlo na `iie254d`, dorazí ČSSZ doopravdy a vzít zpět se nedá.
     */
    public function testEnqueueTargetsTheDocumentedTestBox(): void
    {
        $result = $this->enqueue('a');

        self::assertSame('9tsaf6s', $result['recipient']['box_id']);
        self::assertSame('isds', (string) $result['row']['channel']);
        self::assertSame('JMHZ25', (string) $result['row']['agenda_code']);
    }

    /** Přílohou je zmrazená datová věta beze změny — ne GovTalk, ne zip. */
    public function testAttachmentIsTheFrozenPayload(): void
    {
        $result = $this->enqueue('b');

        self::assertSame('application/xml', $result['attachment']['mime']);
        self::assertSame(
            hash('sha256', $this->payload()),
            $result['attachment']['sha256'],
        );
    }

    /**
     * Spisová značka z fronty je to, co se v ručním režimu opisuje do datové
     * schránky a podle čeho se pak dohledá odpověď — musí se vejít do
     * `dmSenderIdent` a musí se shodovat s tím, co drží fronta.
     */
    public function testSenderIdentComesFromTheQueueAndFitsIsdsLimit(): void
    {
        $result = $this->enqueue('c');

        self::assertSame(
            (string) $result['row']['correlation_reference'],
            $result['sender_ident'],
        );
        self::assertLessThanOrEqual(50, strlen($result['sender_ident']));
    }

    /**
     * Bez zaregistrované a zapnuté odesílací brány je automatický odchod
     * fail-closed a říká to nahlas. Tvrdit opak by uživatele nechalo čekat na
     * odeslání, které nepřijde.
     *
     * Služba se tu staví bez `IsdsGatewayRegistrationService`, což odpovídá
     * nasazení, kde provozovatel bránu ještě nenastavil.
     */
    public function testAutomaticTransportIsHonestlyReportedAsUnavailable(): void
    {
        $result = $this->enqueue('d');

        self::assertFalse($result['transport']['automatic']);
        self::assertSame('manual_upload', $result['transport']['channel']);
        self::assertSame('isds_transport_unavailable', $result['transport']['reason']);
    }

    /**
     * Volba kanálu nesmí vyrobit druhé podání ani druhý termín — opakované
     * zařazení téhož zmrazeného artefaktu vrátí TÝŽ řádek fronty.
     */
    public function testRepeatedEnqueueDoesNotCreateASecondSubmission(): void
    {
        $submissionId = $this->frozenSubmission('e');

        $first = $this->isds->enqueue($this->supplierId, 'test', $submissionId, null);
        $second = $this->isds->enqueue($this->supplierId, 'test', $submissionId, null);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['outbox_id'], $second['outbox_id']);
    }

    public function testDownloadedSignedProtocolUpdatesTheExactJmhzSubmission(): void
    {
        $submissionId = $this->frozenSubmission('inbox-protocol', false);
        $draft = $this->submissions->get($this->supplierId, $submissionId);
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submissionId,
            (int) $draft['row_version'],
            'validated',
        );
        $this->submissions->transition(
            $this->supplierId,
            $submissionId,
            (int) $validated['row_version'],
            'ready',
        );
        $queued = $this->isds->enqueue(
            $this->supplierId,
            'test',
            $submissionId,
            null,
        );
        $outbox = new SubmissionOutboxRepository($this->db);
        $userId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM users')->fetchColumn();
        $claimed = $outbox->claimForManualSending(
            $this->supplierId,
            (int) $queued['outbox_id'],
            $userId,
        );
        self::assertNotNull($claimed);
        $sentMessageId = '1752953337';
        $outbox->markSentManually(
            $this->supplierId,
            (int) $queued['outbox_id'],
            $sentMessageId,
            new \DateTimeImmutable('2026-08-15 08:00:00'),
            (int) $claimed['row_version'],
        );

        $correlation = 'CID-INBOX-0001';
        $protocol = JmhzTransportSample::partialProtocol(
            correlationId: $correlation,
        );
        $attachmentName = 'ČSSZ_Protokol_o_zpracování_e-Podání_CSSZ_JMHZ-'
            . $correlation . '-' . $sentMessageId . '.xml';
        $zfo = SyntheticZfoBuilder::receivedMessage([[
            'name' => $attachmentName,
            'mime' => 'application/xml',
            'bytes' => $protocol,
            'meta_type' => 'main',
        ]], ['message_id' => 'DM-JMHZ-PROTOCOL']);
        $signatures = new class implements JmhzProtocolSignatureVerifierInterface {
            public function verifiedProtocolXml(string $bytes, string $environment): string
            {
                return $bytes;
            }
        };
        $container = Bootstrap::buildContainer();
        $processor = new JmhzIsdsInboxProcessor(
            $outbox,
            new PayrollSubmissionRepository($this->db),
            $this->submissions,
            new PayrollSubmissionDispatchProjection(
                new PayrollSubmissionRepository($this->db),
                $this->submissions,
                new NullLogger(),
            ),
            $this->outboxService,
            $container->get(ZfoExtractor::class),
            $signatures,
        );

        $processed = $processor->process(
            $this->supplierId,
            'test',
            987654,
            new InboxMessageHeader(
                'DM-JMHZ-PROTOCOL',
                '9tsaf6s',
                'Česká správa sociálního zabezpečení',
                'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-'
                    . $correlation . '-' . $sentMessageId . ']',
                null,
                new \DateTimeImmutable('2026-08-15 09:00:00'),
                null,
            ),
            [
                'classification' => 'cssz_protocol',
                'matched_outbox_id' => (int) $queued['outbox_id'],
            ],
            $zfo,
            $userId,
        );

        self::assertSame(
            'processed',
            $processed['status'],
            json_encode($processed, JSON_THROW_ON_ERROR),
        );
        self::assertSame($submissionId, $processed['submission_id']);
        self::assertSame('accepted', $processed['remote_status']);
        self::assertSame('accepted', $this->submissions->get(
            $this->supplierId,
            $submissionId,
        )['status']);
        self::assertSame('accepted', $outbox->find(
            $this->supplierId,
            (int) $queued['outbox_id'],
        )['acceptance_state']);
    }

    /**
     * Číselník je editovatelný, takže se na něj u mzdových údajů nespoléhá
     * slepě: přepsané ID schránky musí podání zastavit, ne ho poslat jinam.
     */
    public function testTamperedRecipientBoxStopsTheSubmission(): void
    {
        $submissionId = $this->frozenSubmission('f');
        $this->db->pdo()->prepare(
            "UPDATE submission_recipients SET isds_box_id = 'aaaaaaa'
              WHERE supplier_id IS NULL AND code = 'cssz_epodani_test'"
        )->execute();

        $this->expectException(SubmissionChannelException::class);
        $this->isds->enqueue($this->supplierId, 'test', $submissionId, null);
    }

    public function testPayrollGatewayPermissionCannotSendANonJmhzOutbox(): void
    {
        $queued = (new SubmissionOutboxRepository($this->db))->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'DPH',
            'recipient_id' => null,
            'recipient_box_id' => '9tsaf6s',
            'subject' => 'Syntetické nemzdové podání',
            'artifact_kind' => 'tax_submission',
            'artifact_id' => 1,
            'artifact_filename' => 'synthetic.xml',
            'artifact_sha256' => str_repeat('e', 64),
            'correlation_reference' => 'TEST-DPH-GATEWAY-01',
            'created_by' => null,
        ], 'test-dph-gateway-guard');
        $outboxId = (int) $queued['row']['id'];

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

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('payroll_gateway_outbox_forbidden', $body['error']['code'] ?? null);
    }

    // ───────────────────────── příprava ─────────────────────────

    /** @return array<string,mixed> */
    private function enqueue(string $key): array
    {
        return $this->isds->enqueue(
            $this->supplierId,
            'test',
            $this->frozenSubmission($key),
            null,
        );
    }

    private function payload(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0">'
            . '<hlavicka>'
            . '<idPodani>' . self::GUID . '</idPodani>'
            . '<variabilniSymbol>' . self::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<mesic>7</mesic><rok>2026</rok><typPodani>R</typPodani>'
            . '</hlavicka>'
            . '</jmhz>';
    }

    private function frozenSubmission(string $key, bool $ready = true): int
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            self::CHANNEL,
            'payroll_run_approved',
            'run:isds:2026-07:' . $key,
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-jmhz-isds-2026-07-' . $key,
            environment: 'test',
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            self::CHANNEL,
            str_repeat('a', 64),
            'isds-2026-07-' . $key,
            environment: 'test',
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            'outbound_xml',
            'outbound',
            'application/xml',
            $this->payload(),
            '1.4.3',
            null,
            self::CHANNEL,
            'artifact-isds-2026-07-' . $key,
        );
        if ($ready) {
            $validated = $this->submissions->transition(
                $this->supplierId,
                $submission['id'],
                $artifact['submission_row_version'],
                'validated',
            );
            $this->submissions->transition(
                $this->supplierId,
                $submission['id'],
                $validated['row_version'],
                'ready',
            );
        }

        return (int) $submission['id'];
    }
}
