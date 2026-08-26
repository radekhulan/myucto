<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DeliveryReceiptMatcher;
use MyInvoice\Service\Submission\DeliveryReceiptReader;
use MyInvoice\Service\Submission\DeliveryReceiptService;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\SyntheticZfoBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Nahraná doručenka a její spárování s podáním, které odešlo ručně.
 *
 * ⚠️ Žádný test tady nesahá na síť ani na skutečnou datovou schránku. Doručenky
 * vyrábí {@see SyntheticZfoBuilder} a všechny hodnoty v nich jsou zjevně
 * vymyšlené.
 *
 * Co se tu hlídá, seřazeno podle toho, co by bolelo nejvíc:
 *   1. doručenka NIKDY neposune osu vyřízení,
 *   2. bez přesného identifikátoru se nepáruje sama, ani kdyby byl kandidát jen jeden,
 *   3. tatáž doručenka podruhé nic nezmění,
 *   4. nespárovaná doručenka je dohledatelná, ne ztracená,
 *   5. špatný soubor selže s vysvětlením, ne prázdnem.
 */
#[Group('integration')]
final class DeliveryReceiptServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const RECIPIENT_BOX = 'abcdefg';

    private Connection $db;
    private DeliveryReceiptService $service;
    private SubmissionOutboxService $outboxService;
    private SubmissionOutboxRepository $outbox;
    private SubmissionInboxRepository $inbox;
    private int $supplierId;
    private int $userId;
    private int $recipientId;
    private string $artifactBytes = '<neco/>';

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
        if (!$this->hasManualDispatchColumns()) {
            $this->markTestSkipped('Migrace 1384 neproběhla.');
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
            'isds_box_id' => self::RECIPIENT_BOX,
            'source_url' => 'https://example.test/synteticky-zdroj',
            'source_note' => 'Syntetický záznam pro test',
            'is_active' => true,
        ], $this->userId);

        $registry = new SubmissionChannelRegistry(
            new EpoChannel($this->stubEpoReader()),
            new IsdsChannel(new FakeIsdsTransport()),
        );
        $this->outboxService = new SubmissionOutboxService(
            $this->outbox,
            new SubmissionOutboxAttemptRepository($db),
            $recipients,
            $registry,
            $this->stubArtifacts(),
            new SubmissionArtifactValidator(new XmlSchemaValidator()),
            new NullLogger(),
            null,
        );

        $this->inbox = new SubmissionInboxRepository($db);
        $documents = $container->get(DocumentIngestService::class);
        self::assertInstanceOf(DocumentIngestService::class, $documents);
        $activity = $container->get(ActivityLogger::class);
        self::assertInstanceOf(ActivityLogger::class, $activity);

        $this->service = new DeliveryReceiptService(
            new DeliveryReceiptReader(new ZfoExtractor()),
            new DeliveryReceiptMatcher($this->outbox),
            $this->inbox,
            $this->outbox,
            $this->outboxService,
            $documents,
            $activity,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    // ───────────────────────── párování ─────────────────────────

    /**
     * Nejsilnější vazba: naše vlastní spisová značka, kterou jsme si do odchozí
     * zprávy sami razítkovali. Tohle je jediný případ, kdy se páruje automaticky.
     */
    public function testReceiptCarryingOurReferenceMatchesAutomatically(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->upload($this->receiptFor($row));

        self::assertSame(DeliveryReceiptService::STATUS_MATCHED, $result['status']);
        self::assertSame((int) $row['id'], $result['outbox_id']);
        self::assertSame(DeliveryReceiptMatcher::BY_CORRELATION, $result['matched_by']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('delivered', $submission['dispatch_state']);
        self::assertSame('manual', $submission['dispatch_mode'], 'Odeslal to člověk, ne aplikace.');
        self::assertSame('9900001', $submission['external_message_id']);
        self::assertNotNull($submission['receipt_document_id']);
        self::assertSame(DeliveryReceiptMatcher::BY_CORRELATION, $submission['receipt_matched_by']);
    }

    /**
     * Doručenka bez naší značky se NEPÁRUJE — ani když je kandidát jediný.
     * Jeden kandidát není důkaz; dvě podání téže agendy do téže schránky
     * vypadají zvenčí stejně.
     */
    public function testReceiptWithoutOurReferenceOnlyOffersCandidates(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->upload($this->receiptFor($row, ['sender_ident' => null]));

        self::assertSame(DeliveryReceiptService::STATUS_CANDIDATES, $result['status']);
        self::assertNull($result['outbox_id']);
        self::assertCount(1, $result['candidates']);
        self::assertSame((int) $row['id'], $result['candidates'][0]['id']);
        self::assertContains('recipient_box', $result['candidates'][0]['reasons']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('ready', $submission['dispatch_state'], 'Bez potvrzení se nesmí změnit NIC.');
        self::assertNull($submission['receipt_document_id']);
        self::assertSame((int) $row['row_version'], (int) $submission['row_version']);
    }

    /** Dvě podání do téže schránky = nejednoznačná shoda. Nepáruje se sama. */
    public function testAmbiguousReceiptIsNeverMatchedAutomatically(): void
    {
        $first = $this->enqueue('HOZ', 43)['row'];
        $second = $this->enqueue('HOZ', 44)['row'];

        $result = $this->upload($this->receiptFor($first, ['sender_ident' => null]));

        self::assertSame(DeliveryReceiptService::STATUS_CANDIDATES, $result['status']);
        self::assertSame('ambiguous', $result['reason']);
        self::assertCount(2, $result['candidates']);

        foreach ([$first, $second] as $row) {
            $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
            self::assertNotNull($submission);
            self::assertSame('ready', $submission['dispatch_state']);
            self::assertNull($submission['receipt_document_id']);
        }
    }

    /** Rozpor mezi značkou a schránkou příjemce se nepřeklápí na jednu stranu. */
    public function testReferenceMatchWithDifferentRecipientBoxNeedsHumanConfirmation(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->upload($this->receiptFor($row, ['recipient_box_id' => 'zzzzzzz']));

        self::assertSame(DeliveryReceiptService::STATUS_CANDIDATES, $result['status']);
        self::assertSame('recipient_box_mismatch', $result['reason']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('ready', $submission['dispatch_state']);
    }

    /** Člověk kandidáta potvrdí — teprve tím vazba vznikne. */
    public function testHumanConfirmationCreatesTheLink(): void
    {
        $row = $this->enqueue()['row'];
        $uploaded = $this->upload($this->receiptFor($row, ['sender_ident' => null]));
        self::assertSame(DeliveryReceiptService::STATUS_CANDIDATES, $uploaded['status']);

        $confirmed = $this->service->confirmMatch(
            $this->supplierId,
            (int) $uploaded['inbox_message_id'],
            (int) $row['id'],
            $this->userId,
        );

        self::assertSame(DeliveryReceiptService::STATUS_MATCHED, $confirmed['status']);
        self::assertSame(DeliveryReceiptMatcher::BY_MANUAL, $confirmed['matched_by']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('delivered', $submission['dispatch_state']);
        self::assertSame(DeliveryReceiptMatcher::BY_MANUAL, $submission['receipt_matched_by']);
    }

    // ───────────────────────── osa vyřízení ─────────────────────────

    /**
     * ⚠️ Jádro zadání. Doručenka dokládá doručení do schránky úřadu — o tom,
     * jestli úřad podání přijal, nevypovídá nic.
     */
    public function testDeliveryReceiptNeverMovesTheAcceptanceAxis(): void
    {
        $row = $this->enqueue()['row'];

        $this->upload($this->receiptFor($row));

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('delivered', $submission['dispatch_state']);
        self::assertSame('unknown', $submission['acceptance_state'], 'Doručeno ≠ vyřízeno.');
        self::assertNull($submission['acceptance_evidence_kind']);
        self::assertNull($submission['accepted_at']);
        self::assertNull($submission['rejected_at']);
    }

    /** Doručenka je nahlášený, ne ověřený důkaz — podpis nikdo nečetl. */
    public function testReceiptSignatureStaysUnverified(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->upload($this->receiptFor($row));

        self::assertSame('unverified', $result['receipt']['signature_status']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('unverified', $submission['receipt_signature_status']);
    }

    // ───────────────────────── idempotence ─────────────────────────

    /** Tatáž doručenka podruhé: žádný druhý dokument, žádný druhý důkaz, žádná změna. */
    public function testSameReceiptUploadedTwiceIsANoOp(): void
    {
        $row = $this->enqueue()['row'];
        $bytes = $this->receiptFor($row);

        $first = $this->upload($bytes);
        $afterFirst = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterFirst);

        $second = $this->upload($bytes);

        self::assertSame(DeliveryReceiptService::STATUS_ALREADY_PROCESSED, $second['status']);
        self::assertSame($first['inbox_message_id'], $second['inbox_message_id']);
        self::assertSame($first['document_id'], $second['document_id']);

        $afterSecond = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterSecond);
        self::assertSame(
            (int) $afterFirst['row_version'],
            (int) $afterSecond['row_version'],
            'Druhé nahrání nesmí do podání zapsat vůbec nic.',
        );
        self::assertSame(1, $this->countInboxMessages(), 'Druhá zpráva se založit nesmí.');
    }

    /** Potvrzení vazby podruhé taky nic nezmění. */
    public function testConfirmingTheSameMatchTwiceIsANoOp(): void
    {
        $row = $this->enqueue()['row'];
        $uploaded = $this->upload($this->receiptFor($row, ['sender_ident' => null]));

        $this->service->confirmMatch($this->supplierId, (int) $uploaded['inbox_message_id'], (int) $row['id'], $this->userId);
        $afterFirst = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterFirst);

        $this->service->confirmMatch($this->supplierId, (int) $uploaded['inbox_message_id'], (int) $row['id'], $this->userId);
        $afterSecond = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterSecond);

        self::assertSame((int) $afterFirst['row_version'], (int) $afterSecond['row_version']);
    }

    // ───────────────────────── nezařazeno ─────────────────────────

    /** Nespárovaná doručenka nesmí zmizet. Musí být vidět a dohledatelná. */
    public function testUnmatchedReceiptRemainsFindable(): void
    {
        $result = $this->upload(SyntheticZfoBuilder::receipt([
            'message_id' => '9900777',
            'recipient_box_id' => 'zzzzzzz',
            'sender_ident' => null,
        ]));

        self::assertSame(DeliveryReceiptService::STATUS_UNMATCHED, $result['status']);
        self::assertSame('no_candidate', $result['reason']);

        $unmatched = $this->service->listUnmatched($this->supplierId, 'test');
        $ids = array_map(static fn (array $m): string => (string) $m['external_message_id'], $unmatched);
        self::assertContains('9900777', $ids, 'Nespárovaná doručenka musí zůstat dohledatelná.');
        self::assertNotNull($unmatched[0]['document_id'], 'Soubor musí zůstat uložený v Dokumentech.');
    }

    /**
     * Druhá, JINÁ doručenka k témuž podání první nepřepíše — a nezmizí:
     * skončí v nezařazených, kde ji člověk uvidí a rozhodne, kam patří.
     */
    public function testSecondDifferentReceiptDoesNotOverwriteTheFirstAndStaysVisible(): void
    {
        $row = $this->enqueue()['row'];
        $this->upload($this->receiptFor($row));
        $afterFirst = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterFirst);

        $second = $this->upload($this->receiptFor($row, ['message_id' => '9900002']));

        self::assertSame(DeliveryReceiptService::STATUS_UNMATCHED, $second['status']);
        self::assertSame('receipt_already_attached', $second['reason']);

        $afterSecond = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($afterSecond);
        self::assertSame((int) $afterFirst['receipt_document_id'], (int) $afterSecond['receipt_document_id']);

        $unmatched = $this->service->listUnmatched($this->supplierId, 'test');
        $ids = array_map(static fn (array $m): string => (string) $m['external_message_id'], $unmatched);
        self::assertContains('9900002', $ids);
    }

    /** Kandidáty jde k už uložené doručence dohledat i později. */
    public function testCandidatesCanBeListedForAStoredReceipt(): void
    {
        $row = $this->enqueue()['row'];
        $uploaded = $this->upload($this->receiptFor($row, ['sender_ident' => null]));

        $candidates = $this->service->candidatesFor($this->supplierId, (int) $uploaded['inbox_message_id']);

        self::assertNotSame([], $candidates);
        self::assertSame((int) $row['id'], $candidates[0]['id']);
    }

    // ───────────────────────── vadné soubory ─────────────────────────

    public function testNonZfoFileFailsWithAnExplanation(): void
    {
        try {
            $this->upload(SyntheticZfoBuilder::notAZfo(), 'vypis.pdf');
            self::fail('PDF místo doručenky musí selhat.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_not_zfo', $e->errorCode);
            self::assertStringContainsString('.zfo', $e->getMessage());
        }
    }

    public function testCorruptedZfoFailsWithAnExplanation(): void
    {
        try {
            $this->upload(SyntheticZfoBuilder::corruptedInsideValidEnvelope());
            self::fail('Poškozený obsah musí selhat.');
        } catch (DocumentException $e) {
            self::assertSame('zfo_parse_failed', $e->errorCode);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testReceiptWithoutMessageIdFailsWithAnExplanation(): void
    {
        try {
            $this->upload(SyntheticZfoBuilder::receiptWithoutMessageId());
            self::fail('Bez ID zprávy není co párovat.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_missing_message_id', $e->errorCode);
        }
    }

    public function testEmptyUploadFailsWithAnExplanation(): void
    {
        try {
            $this->upload('');
            self::fail('Prázdný soubor musí selhat.');
        } catch (DocumentException $e) {
            self::assertSame('receipt_empty', $e->errorCode);
        }
    }

    /** Nic se nesmí uložit, když se soubor nedal přečíst. */
    public function testFailedUploadStoresNothing(): void
    {
        try {
            $this->upload(SyntheticZfoBuilder::notAZfo(), 'vypis.pdf');
        } catch (DocumentException) {
            // očekávané
        }

        self::assertSame(0, $this->countInboxMessages());
    }

    // ───────────────────────── ruční odeslání ─────────────────────────

    /**
     * Bez transportu se podání odesílá ručně. Kdyby si to aplikace nemohla
     * zapsat, zůstalo by odeslané podání navždy v „připraveno" — a uživatel by
     * ho podal podruhé.
     */
    public function testManualDispatchIsRecordedWithoutAnyTransport(): void
    {
        $row = $this->enqueue()['row'];

        $result = $this->outboxService->markSentManually(
            $this->supplierId,
            (int) $row['id'],
            $this->userId,
            '9900123',
            new \DateTimeImmutable('2026-08-01 09:15:00'),
        );

        self::assertTrue($result['recorded']);
        self::assertSame('sent', $result['row']['dispatch_state']);
        self::assertSame('manual', $result['row']['dispatch_mode']);
        self::assertSame('9900123', $result['row']['external_message_id']);
        self::assertSame($this->userId, (int) $result['row']['confirmed_by']);
        self::assertSame('unknown', $result['row']['acceptance_state']);
        // Schránku příjemce jsme neověřovali — u ručního odeslání to ani nejde.
        self::assertNull($result['row']['recipient_box_verified_at']);
    }

    /** Druhé „odeslal jsem to" s týmž ID nic nezmění. */
    public function testManualDispatchIsIdempotent(): void
    {
        $row = $this->enqueue()['row'];

        $first = $this->outboxService->markSentManually($this->supplierId, (int) $row['id'], $this->userId, '9900123');
        $second = $this->outboxService->markSentManually($this->supplierId, (int) $row['id'], $this->userId, '9900123');

        self::assertTrue($first['recorded']);
        self::assertFalse($second['recorded']);
        self::assertSame((int) $first['row']['row_version'], (int) $second['row']['row_version']);
    }

    /** Jiné ID zprávy by přepsalo jediný důkaz, že zpráva u příjemce je. */
    public function testManualDispatchRefusesADifferentMessageId(): void
    {
        $row = $this->enqueue()['row'];
        $this->outboxService->markSentManually($this->supplierId, (int) $row['id'], $this->userId, '9900123');

        $this->expectException(SubmissionChannelException::class);
        $this->outboxService->markSentManually($this->supplierId, (int) $row['id'], $this->userId, '9900999');
    }

    /**
     * Ručně odeslané podání se pozná podle dmID, i když doručenka naši značku
     * nenese. Je to druhý přesný identifikátor, ne domněnka.
     */
    public function testReceiptMatchesManuallyDispatchedSubmissionByMessageId(): void
    {
        $row = $this->enqueue()['row'];
        $this->outboxService->markSentManually(
            $this->supplierId,
            (int) $row['id'],
            $this->userId,
            '9900001',
            new \DateTimeImmutable('2026-08-01 09:15:00'),
        );

        $result = $this->upload($this->receiptFor($row, ['sender_ident' => null]));

        self::assertSame(DeliveryReceiptService::STATUS_MATCHED, $result['status']);
        self::assertSame(DeliveryReceiptMatcher::BY_MESSAGE_ID, $result['matched_by']);

        $submission = $this->outbox->find($this->supplierId, (int) $row['id']);
        self::assertNotNull($submission);
        self::assertSame('delivered', $submission['dispatch_state']);
        self::assertSame('unknown', $submission['acceptance_state']);
    }

    /**
     * Vadné XML u ručně odeslaného podání se zapíše a řekne — ale nezastaví
     * evidenci. Zpráva je pryč; předstírat, že se nic nestalo, by bylo horší.
     */
    public function testInvalidArtifactIsRecordedButDoesNotBlockManualDispatch(): void
    {
        $this->artifactBytes = '<neplatne-xml-podle-schematu/>';
        $row = $this->enqueue('DPHDP3')['row'];

        $result = $this->outboxService->markSentManually($this->supplierId, (int) $row['id'], $this->userId, '9900123');

        self::assertTrue($result['recorded']);
        self::assertSame('sent', $result['row']['dispatch_state']);
        self::assertSame('failed', $result['row']['artifact_validation_status']);
        self::assertSame('failed', $result['validation']['status']);
    }

    // ───────────────────────── pomocné ─────────────────────────

    /** @return array<string,mixed> */
    private function upload(string $bytes, string $filename = 'dorucenka.zfo', ?int $outboxId = null): array
    {
        return $this->service->upload(
            $this->supplierId,
            'test',
            $bytes,
            $filename,
            $this->userId,
            $outboxId,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $overrides
     */
    private function receiptFor(array $row, array $overrides = []): string
    {
        // Časy jsou vázané na „teď", protože podání se do fronty zařadí těsně
        // před odesláním. Pevné datum z minulosti by test posunul mimo okno,
        // ve kterém se kandidáti hledají, a měřil by něco jiného, než chce.
        $now = new \DateTimeImmutable('now');

        return SyntheticZfoBuilder::receipt($overrides + [
            'message_id' => '9900001',
            'recipient_box_id' => self::RECIPIENT_BOX,
            'sender_ident' => (string) $row['correlation_reference'],
            'annotation' => (string) $row['subject'],
            'delivery_time' => $now->format(\DateTimeInterface::ATOM),
            'acceptance_time' => $now->modify('+1 hour')->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** @return array{row:array<string,mixed>,created:bool} */
    private function enqueue(string $agenda = 'HOZ', int $artifactId = 42): array
    {
        return $this->outboxService->enqueue(
            $this->supplierId,
            'test',
            'isds',
            $agenda,
            'document',
            $artifactId,
            $this->recipientId,
            'Testovací podání',
            $this->userId,
        );
    }

    private function countInboxMessages(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM submission_inbox_messages WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function hasManualDispatchColumns(): bool
    {
        $stmt = $this->db->pdo()->query(
            "SHOW COLUMNS FROM submission_outbox LIKE 'dispatch_mode'"
        );

        return $stmt !== false && $stmt->fetch() !== false;
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class ($this) implements SubmissionArtifactResolver {
            public function __construct(private readonly DeliveryReceiptServiceTest $test) {}

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
