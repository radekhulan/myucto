<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Submission\Channel\AcceptanceEvidence;
use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Chování odchozí cesty od zařazení po dořešení přerušeného odeslání.
 *
 * Všechno proti {@see FakeIsdsTransport} — žádný test nesahá na síť.
 */
#[Group('integration')]
final class SubmissionOutboxServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const USER_PLACEHOLDER = 0;

    private Connection $db;
    private SubmissionOutboxService $service;
    private SubmissionOutboxRepository $outbox;
    private FakeIsdsTransport $transport;
    private int $supplierId;
    private int $userId;
    private int $recipientId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->outbox = new SubmissionOutboxRepository($db);
        if (!$this->outbox->isAvailable()) {
            $this->markTestSkipped('Migrace 1381 neproběhla.');
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();

        $recipients = new SubmissionRecipientRepository($db);
        $this->recipientId = $recipients->upsertForSupplier($this->supplierId, [
            'code' => 'fu_testovaci',
            'name' => 'Testovací finanční úřad',
            'kind' => 'tax_office',
            'isds_box_id' => 'abcdefg',
            'source_url' => 'https://example.test/synteticky-zdroj',
            'source_note' => 'Syntetický záznam pro test',
            'is_active' => true,
        ], $this->userId);

        $this->transport = new FakeIsdsTransport();
        $registry = new SubmissionChannelRegistry(
            new EpoChannel($this->stubEpoReader()),
            new IsdsChannel($this->transport),
        );

        $this->service = new SubmissionOutboxService(
            $this->outbox,
            new SubmissionOutboxAttemptRepository($db),
            $recipients,
            $registry,
            $this->stubArtifacts(),
            new SubmissionArtifactValidator(new XmlSchemaValidator()),
            new NullLogger(),
            null,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testEnqueueIsIdempotent(): void
    {
        $first = $this->enqueue();
        $second = $this->enqueue();

        self::assertTrue($first['created']);
        self::assertFalse($second['created'], 'Druhé zařazení téhož podkladu nesmí založit druhý řádek.');
        self::assertSame($first['row']['id'], $second['row']['id']);
    }

    public function testAutomationCanOnlyPrepareNeverSend(): void
    {
        $row = $this->enqueue()['row'];

        // Zařazení samo o sobě nic neodesílá — na to je potřeba potvrzení člověkem.
        self::assertSame('ready', $row['dispatch_state']);
        self::assertNull($row['confirmed_by']);
        self::assertSame([], $this->transport->sentMessages);
    }

    public function testHumanConfirmationSendsAndRecordsTheAttempt(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->service->confirmAndSend($this->supplierId, (int) $row['id'], $this->userId, $this->context());

        self::assertTrue($result['dispatched']);
        self::assertSame('sent', $result['row']['dispatch_state']);
        self::assertSame('DM-1000', $result['row']['external_message_id']);
        self::assertSame($this->userId, $result['row']['confirmed_by']);
        // Osa vyřízení zůstává nedotčená — odesláno není přijato.
        self::assertSame('unknown', $result['row']['acceptance_state']);

        $attempts = $this->service->attemptsFor($this->supplierId, (int) $row['id']);
        self::assertCount(1, $attempts);
        self::assertSame('sent', $attempts[0]['outcome']);
    }

    /** Druhé potvrzení nesmí vyrobit druhé podání u úřadu. */
    public function testRepeatedConfirmationDoesNotSendTwice(): void
    {
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];

        $first = $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());
        $second = $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        self::assertTrue($first['dispatched']);
        self::assertFalse($second['dispatched']);
        self::assertCount(1, $this->transport->sentMessages, 'Zpráva smí odejít právě jednou.');
        self::assertCount(1, $this->service->attemptsFor($this->supplierId, $id));
    }

    public function testTimeoutLeavesSubmissionUncertainNotFailed(): void
    {
        $this->transport->sendBehaviour = 'timeout';
        $row = $this->enqueue()['row'];

        $result = $this->service->confirmAndSend($this->supplierId, (int) $row['id'], $this->userId, $this->context());

        self::assertFalse($result['dispatched']);
        self::assertSame('send_uncertain', $result['row']['dispatch_state']);
        self::assertNull($result['row']['external_message_id']);

        $attempts = $this->service->attemptsFor($this->supplierId, (int) $row['id']);
        self::assertSame('uncertain', $attempts[0]['outcome']);
    }

    /**
     * Nejzrádnější případ: spojení spadlo, ale zpráva ODEŠLA. Dohledání podle
     * naší spisové značky v `dmSenderIdent` to musí najít a podání označit za
     * odeslané — jinak by ho uživatel poslal podruhé.
     */
    public function testUncertainSubmissionThatActuallyLeftIsAdoptedByProbe(): void
    {
        $this->transport->sendBehaviour = 'timeout_but_delivered';
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        $resolved = $this->service->resolveUncertain($this->supplierId, $id, $this->context());

        self::assertSame('sent', $resolved['dispatch_state']);
        self::assertSame('DM-1000', $resolved['external_message_id']);
    }

    public function testUncertainSubmissionThatNeverLeftIsMarkedFailed(): void
    {
        $this->transport->sendBehaviour = 'timeout';
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        $resolved = $this->service->resolveUncertain($this->supplierId, $id, $this->context());

        self::assertSame('failed', $resolved['dispatch_state']);
        self::assertSame('not_sent', $resolved['last_error_code']);
    }

    /** Nedovolali jsme se → nic se nemění. Opakovat by znamenalo riskovat duplicitu. */
    public function testUnreachableMailboxLeavesUncertainSubmissionUntouched(): void
    {
        $this->transport->sendBehaviour = 'timeout';
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        $this->transport->probeBehaviour = 'fail';
        $resolved = $this->service->resolveUncertain($this->supplierId, $id, $this->context());

        self::assertSame('send_uncertain', $resolved['dispatch_state']);
    }

    /**
     * ⚠️ Jádro celého zadání. Kanál s doručenkou nesmí podání posunout na
     * „zpracováno úřadem", i kdyby to sám tvrdil.
     */
    public function testDeliveryOnlyChannelCannotMoveSubmissionToProcessed(): void
    {
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        // Adaptér (třeba po výměně knihovny) začne vracet přijetí. Nesmí projít.
        $lie = new ChannelStatus(
            DispatchState::Delivered,
            AcceptanceState::Accepted,
            AcceptanceEvidence::AgencyProtocolMessage,
            new \DateTimeImmutable('+1 day'),
        );

        $applied = $this->service->applyStatus($this->supplierId, $id, $lie);

        self::assertSame('delivered', $applied['dispatch_state'], 'Doručení se zapsat smí.');
        self::assertSame('unknown', $applied['acceptance_state'], 'Vyřízení od kanálu s doručenkou NE.');
        self::assertNull($applied['acceptance_evidence_kind']);
        self::assertNull($applied['accepted_at']);
    }

    public function testDeliveryReceiptMovesOnlyTheTransportAxis(): void
    {
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        $applied = $this->service->applyStatus(
            $this->supplierId,
            $id,
            ChannelStatus::deliveredOnly(new \DateTimeImmutable('+1 day')),
        );

        self::assertSame('delivered', $applied['dispatch_state']);
        self::assertSame('unknown', $applied['acceptance_state']);
    }

    /** Doručenka nedorazila — podání zůstává „odesláno", ne „doručeno". */
    public function testMissingDeliveryReceiptLeavesSubmissionSent(): void
    {
        $row = $this->enqueue()['row'];
        $id = (int) $row['id'];
        $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());

        $applied = $this->service->applyStatus($this->supplierId, $id, ChannelStatus::sentOnly());

        self::assertSame('sent', $applied['dispatch_state']);
        self::assertNull($applied['delivered_at']);
    }

    /** Nepoužitelná schránka příjemce zastaví odeslání ještě před odchodem zprávy. */
    public function testUnusableRecipientBoxStopsTheSendBeforeItLeaves(): void
    {
        $this->transport->boxBehaviour = 'unusable';
        $row = $this->enqueue()['row'];

        $result = $this->service->confirmAndSend($this->supplierId, (int) $row['id'], $this->userId, $this->context());

        self::assertFalse($result['dispatched']);
        self::assertSame('failed', $result['row']['dispatch_state']);
        self::assertSame('recipient_box_unusable', $result['row']['last_error_code']);
        self::assertSame([], $this->transport->sentMessages);
    }

    /** Před odesláním se podklad kontroluje proti XSD — ISDS to za nás neudělá. */
    public function testInvalidXmlIsRefusedBeforeSending(): void
    {
        $this->artifactBytes = '<neplatne-xml-podle-schematu/>';
        $row = $this->enqueue('DPHDP3')['row'];

        $result = $this->service->confirmAndSend($this->supplierId, (int) $row['id'], $this->userId, $this->context());

        self::assertFalse($result['dispatched']);
        self::assertSame('artifact_invalid', $result['row']['last_error_code']);
        self::assertSame([], $this->transport->sentMessages, 'Vadné XML nesmí opustit aplikaci.');
    }

    /** Podklad, který se mezi zařazením a potvrzením změnil, se neodešle. */
    public function testChangedArtifactStopsTheSend(): void
    {
        $row = $this->enqueue()['row'];
        $this->artifactBytes = '<zmenene-xml/>';

        $result = $this->service->confirmAndSend($this->supplierId, (int) $row['id'], $this->userId, $this->context());

        self::assertFalse($result['dispatched']);
        self::assertSame('artifact_changed', $result['row']['last_error_code']);
    }

    // ───────────────────────── pomocné ─────────────────────────

    private string $artifactBytes = '<neco/>';

    /** @return array{row:array<string,mixed>,created:bool} */
    private function enqueue(string $agenda = 'HOZ'): array
    {
        return $this->service->enqueue(
            $this->supplierId,
            'test',
            'isds',
            $agenda,
            'document',
            42,
            $this->recipientId,
            'Testovací podání',
            $this->userId,
        );
    }

    private function context(): ChannelContext
    {
        return new ChannelContext($this->supplierId, 'test', new ChannelCredentials('zzzzzzz', 'certificate'));
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class ($this) implements SubmissionArtifactResolver {
            public function __construct(private readonly SubmissionOutboxServiceTest $test) {}

            public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
            {
                return [
                    'filename' => 'podani.xml',
                    'mime' => 'application/xml',
                    'bytes' => $this->test->currentArtifactBytes(),
                ];
            }
        };
    }

    public function currentArtifactBytes(): string
    {
        return $this->artifactBytes;
    }

    private function stubEpoReader(): EpoAttemptStatusReader
    {
        return new class implements EpoAttemptStatusReader {
            public function findAttempt(int $supplierId, string $attemptReference): ?array
            {
                return null;
            }

            public function confirmation(int $supplierId, string $attemptReference): ?array
            {
                return null;
            }
        };
    }
}
