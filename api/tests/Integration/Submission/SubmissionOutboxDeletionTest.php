<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Action\Submission\SubmissionOutboxAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Trvalé smazání ZRUŠENÉ odchozí zprávy.
 *
 * ── Co se tu vlastně hlídá ───────────────────────────────────────────────────
 * Zrušená zpráva, která nikdy neopustila aplikaci, nedokládá nic a smí zmizet.
 * Cokoli, co ven šlo — ID datové zprávy, doručenka, příchozí dokument, pokus
 * v ledgeru — je doklad a nemaže se NIKDY. A protože „nabídnout a pak odmítnout"
 * je horší než nenabídnout, testuje se obojí: že se akce ve výpisu nenabízí
 * i že ji server odmítne.
 */
#[Group('integration')]
final class SubmissionOutboxDeletionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionOutboxService $service;
    private SubmissionOutboxRepository $outbox;
    private SubmissionOutboxAction $action;
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
        $this->action = $container->get(SubmissionOutboxAction::class);

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn(),
        );
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
        $this->service = new SubmissionOutboxService(
            $this->outbox,
            new SubmissionOutboxAttemptRepository($db),
            $recipients,
            new SubmissionChannelRegistry(
                new EpoChannel($this->stubEpoReader()),
                new IsdsChannel($this->transport),
            ),
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

    public function testCancelledMessageWithoutAnyTraceOfSendingIsDeleted(): void
    {
        $id = $this->enqueueAndCancel();

        $listed = $this->listedRow($id);
        self::assertTrue($listed['deletable'], 'Zrušená zpráva bez stopy po odeslání se musí dát smazat.');
        self::assertNull($listed['delete_blocked_reason']);

        $snapshot = $this->service->delete($this->supplierId, $id);
        self::assertSame('HOZ', $snapshot['agenda_code']);
        self::assertNull($this->outbox->find($this->supplierId, $id), 'Řádek musí z databáze zmizet.');
        self::assertSame([], $this->service->listForSupplier($this->supplierId, 'test'));
    }

    public function testSentMessageIsNeitherOfferedNorDeleted(): void
    {
        $id = (int) $this->enqueue()['row']['id'];
        $sent = $this->service->confirmAndSend($this->supplierId, $id, $this->userId, $this->context());
        self::assertTrue($sent['dispatched']);

        $listed = $this->listedRow($id);
        self::assertArrayNotHasKey(
            'deletable',
            $listed,
            'Odeslaná zpráva není zrušená, takže se mazání nesmí ani nabídnout.',
        );

        $this->assertDeleteRefused($id, 'submission_not_deletable');
        self::assertNotNull($this->outbox->find($this->supplierId, $id));
    }

    /**
     * Doručenka je důkaz o dni podání. Zpráva, ke které dorazila, ven
     * prokazatelně šla — i kdyby řádek z jakéhokoli důvodu nesl „zrušeno".
     */
    public function testCancelledMessageWithLinkedIncomingDocumentIsNotDeletable(): void
    {
        $id = $this->enqueueAndCancel();
        $this->insertInboxMessage($id);

        $listed = $this->listedRow($id);
        self::assertFalse($listed['deletable']);
        self::assertSame('receipt', $listed['delete_blocked_reason']);

        $this->assertDeleteRefused($id, 'submission_not_deletable');
        self::assertNotNull($this->outbox->find($this->supplierId, $id));
    }

    /**
     * Pokus, který dostal ID datové zprávy, je sám o sobě doklad — a to i na
     * řádku, který se tváří jako zrušený.
     */
    public function testCancelledMessageWithDispatchedAttemptIsNotDeletable(): void
    {
        $id = $this->enqueueAndCancel();
        $this->insertAttempt($id, 'sent', 'DM-SYNTETICKE-1');

        $listed = $this->listedRow($id);
        self::assertFalse($listed['deletable']);
        self::assertSame('sent', $listed['delete_blocked_reason']);
        $this->assertDeleteRefused($id, 'submission_not_deletable');
    }

    /**
     * Neúspěšný pokus sám o sobě neznamená, že zpráva odešla — ledger je ale
     * append-only, takže se s ním zpráva zahodit nedá. Uživatel to musí vidět
     * jako důvod u řádku, ne jako chybu po kliknutí.
     */
    public function testCancelledMessageWithFailedAttemptSaysWhyItStays(): void
    {
        $id = $this->enqueueAndCancel();
        $this->insertAttempt($id, 'failed', null);

        $listed = $this->listedRow($id);
        self::assertFalse($listed['deletable']);
        self::assertSame('attempt', $listed['delete_blocked_reason']);
        $this->assertDeleteRefused($id, 'submission_not_deletable');
    }

    public function testDeletionIsLoggedWithEverythingThatWasInTheRow(): void
    {
        $id = $this->enqueueAndCancel();
        $row = $this->outbox->find($this->supplierId, $id);
        self::assertNotNull($row);

        $response = $this->action->delete($this->request('DELETE'), new Response(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->outbox->find($this->supplierId, $id));

        $stmt = $this->db->pdo()->prepare(
            'SELECT user_id, entity_type, entity_id, payload FROM activity_log
              WHERE supplier_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, 'submission_outbox_deleted']);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($log, 'Smazání se musí zapsat do auditní stopy.');
        self::assertSame($this->userId, (int) $log['user_id']);
        self::assertSame('submission_outbox', (string) $log['entity_type']);
        self::assertSame($id, (int) $log['entity_id']);

        $payload = json_decode((string) $log['payload'], true);
        self::assertIsArray($payload);
        self::assertSame('HOZ', $payload['agenda_code']);
        self::assertSame((string) $row['correlation_reference'], $payload['correlation_reference']);
        self::assertSame((string) $row['subject'], $payload['subject']);
        self::assertSame('cancelled', $payload['dispatch_state']);
    }

    public function testReadonlyRoleCannotDelete(): void
    {
        $id = $this->enqueueAndCancel();

        $response = $this->action->delete(
            $this->request('DELETE', 'session', 'readonly'),
            new Response(),
            ['id' => (string) $id],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertNotNull($this->outbox->find($this->supplierId, $id));
    }

    // ───────────────────────── pomocné ─────────────────────────

    private function enqueueAndCancel(): int
    {
        $id = (int) $this->enqueue()['row']['id'];
        $this->service->cancel($this->supplierId, $id);

        return $id;
    }

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
    private function listedRow(int $id): array
    {
        foreach ($this->service->listForSupplier($this->supplierId, 'test') as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        self::fail('Zpráva ' . $id . ' ve výpisu není.');
    }

    private function assertDeleteRefused(int $id, string $expectedCode): void
    {
        try {
            $this->service->delete($this->supplierId, $id);
            self::fail('Mazání mělo být odmítnuto.');
        } catch (SubmissionChannelException $e) {
            self::assertSame($expectedCode, $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    private function insertInboxMessage(int $outboxId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO submission_inbox_messages
                (supplier_id, environment, channel, external_message_id, classification, matched_outbox_id)
             VALUES (?, \'test\', \'isds\', ?, \'delivery_receipt\', ?)'
        );
        $stmt->execute([$this->supplierId, 'DM-DORUCENKA-' . $outboxId, $outboxId]);
    }

    private function insertAttempt(int $outboxId, string $outcome, ?string $messageId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO submission_outbox_attempts
                (supplier_id, outbox_id, channel, attempt_no, outcome, request_sha256,
                 correlation_reference, external_message_id, error_code, error_message, started_at)
             VALUES (?, ?, \'isds\', 1, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $this->supplierId,
            $outboxId,
            $outcome,
            str_repeat('a', 64),
            'HOZ-TEST-' . $outboxId,
            $messageId,
            $outcome === 'failed' ? 'synteticka_chyba' : null,
            $outcome === 'failed' ? 'Syntetická chyba pro test.' : null,
        ]);
    }

    private function request(
        string $method,
        string $authMethod = 'session',
        string $role = 'admin',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/submissions/outbox/1')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    private function context(): ChannelContext
    {
        return new ChannelContext($this->supplierId, 'test', new ChannelCredentials('zzzzzzz', 'certificate'));
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class implements SubmissionArtifactResolver {
            public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
            {
                return [
                    'filename' => 'podani.xml',
                    'mime' => 'application/xml',
                    'bytes' => '<?xml version="1.0"?><podani/>',
                ];
            }
        };
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
