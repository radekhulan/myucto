<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DeliveryFictionCalculator;
use MyInvoice\Service\Submission\DeliveryResolutionService;
use MyInvoice\Service\Submission\SubmissionLegalRules;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionInboxService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/**
 * Příchozí cesta: vědomá akce podle § 17 odst. 3, rozlišení prázdna od poruchy
 * a zařazování zpráv.
 *
 * Nic z toho nesahá na síť — {@see FakeIsdsTransport} je paměťová náhrada.
 */
#[Group('integration')]
final class SubmissionInboxServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionInboxService $service;
    private SubmissionInboxRepository $inbox;
    private SubmissionChannelCredentialRepository $credentials;
    private FakeIsdsTransport $transport;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->inbox = new SubmissionInboxRepository($db);
        if (!$this->inbox->isAvailable()) {
            $this->markTestSkipped('Migrace 1381 neproběhla.');
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();

        $this->credentials = new SubmissionChannelCredentialRepository($db);
        $this->transport = new FakeIsdsTransport();

        $outboxRepo = new SubmissionOutboxRepository($db);
        $recipients = new SubmissionRecipientRepository($db);
        $registry = new SubmissionChannelRegistry(
            new EpoChannel($this->stubEpoReader()),
            new IsdsChannel($this->transport),
        );
        $outboxService = new SubmissionOutboxService(
            $outboxRepo,
            new SubmissionOutboxAttemptRepository($db),
            $recipients,
            $registry,
            $this->stubArtifacts(),
            new SubmissionArtifactValidator(new XmlSchemaValidator()),
            new NullLogger(),
            null,
        );

        $documents = $container->get(DocumentIngestService::class);
        self::assertInstanceOf(DocumentIngestService::class, $documents);
        $activity = $container->get(ActivityLogger::class);
        self::assertInstanceOf(ActivityLogger::class, $activity);

        $this->service = new SubmissionInboxService(
            $this->inbox,
            $recipients,
            $this->credentials,
            $outboxService,
            $registry,
            new InboxMessageClassifier($outboxRepo),
            $documents,
            new DeliveryResolutionService(
                $this->inbox,
                $recipients,
                new DeliveryFictionCalculator(),
                new SubmissionLegalRules(CzechPayrollRulesets2026::provider()),
                $activity,
                new NativeClock(),
            ),
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

    /**
     * § 17 odst. 3 zák. 300/2008 Sb.: vyzvednutí seznamu může způsobit
     * doručení. Bez konkrétního uživatele, který právě spustil akci, se na
     * schránku nesmí sáhnout.
     */
    public function testPollingIsRefusedWithoutInteractiveActor(): void
    {
        $this->insertCredential();

        try {
            $this->service->poll($this->context(), 'isds');
            self::fail('Vybírání schránky bez souhlasu mělo být odmítnuto.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('interactive_action_required', $e->errorCode);
        }

        // A hlavně: k síti se to vůbec nedostalo.
        self::assertNotContains('listReceived', $this->transport->callLog);
    }

    public function testFreshCredentialDoesNotEnablePersistentPolling(): void
    {
        $this->insertCredential();

        $credential = $this->credentials->findPublic($this->supplierId, 'isds', 'test');
        self::assertNotNull($credential);
        self::assertFalse($credential['inbox_polling_enabled']);
    }

    public function testInteractivePollingWorksWithoutPersistentOptIn(): void
    {
        $this->insertCredential();
        $this->transport->inboxMessages = [];

        $result = $this->service->poll(
            $this->context(),
            'isds',
            null,
            50,
            $this->userId,
        );

        $credential = $this->credentials->findPublic($this->supplierId, 'isds', 'test');
        self::assertNotNull($credential);
        self::assertFalse($credential['inbox_polling_enabled']);
        self::assertSame(0, $result['fetched']);
        self::assertContains('listReceived', $this->transport->callLog);
    }

    /** Uložení nového certifikátu nesmí souhlas s vybíráním schránky zapnout. */
    public function testSavingCredentialDoesNotSilentlyEnablePolling(): void
    {
        $this->insertCredential();
        $this->credentials->save($this->supplierId, 'isds', 'test', [
            'label' => 'Nový certifikát',
            'box_id' => 'abcdefg',
            'certificate_ciphertext' => 'enc:v2:0000:jiny',
            'certificate_passphrase_ciphertext' => null,
            'certificate_fingerprint' => null,
            'certificate_valid_to' => null,
        ], $this->userId);

        $credential = $this->credentials->findPublic($this->supplierId, 'isds', 'test');
        self::assertNotNull($credential);
        self::assertFalse($credential['inbox_polling_enabled']);
    }

    /**
     * ⚠️ Selhání dotazu se NIKDY nesmí tvářit jako prázdná schránka.
     * Tichý neúspěch by zastavil vyzvedávání výzev podle § 74 DŘ a nikdo by
     * si toho nevšiml, dokud by nepropadla lhůta.
     */
    public function testFailedQueryIsNotReportedAsEmptyInbox(): void
    {
        $this->enablePolling();
        $this->transport->inboxBehaviour = 'fail';

        $result = $this->pollInteractively();

        self::assertSame(1, $result['failed']);
        self::assertNotNull($result['error']);
        self::assertSame(0, $result['stored']);

        $state = $this->service->pollState($this->supplierId, 'isds', 'test');
        self::assertNotNull($state);
        // Rozdíl mezi „pokusili jsme se" a „povedlo se" je celý ten důkaz.
        self::assertNotNull($state['last_attempt_at']);
        self::assertNull($state['last_ok_at'], 'Neúspěšný dotaz nesmí zapsat úspěch.');
        self::assertSame(1, $state['consecutive_failures']);
    }

    /** Prázdná schránka je naopak legitimní odpověď — a pozná se podle last_ok_at. */
    public function testEmptyInboxRecordsSuccess(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [];

        $result = $this->pollInteractively();

        self::assertSame(0, $result['fetched']);
        self::assertSame(0, $result['failed']);

        $state = $this->service->pollState($this->supplierId, 'isds', 'test');
        self::assertNotNull($state);
        self::assertNotNull($state['last_ok_at']);
        self::assertSame(0, $state['consecutive_failures']);
    }

    public function testPartialMessageDownloadFailureIsVisibleAndDoesNotRecordPollSuccess(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [
            [
                'message_id' => 'DM-FAIL',
                'sender_box_id' => 'qqqqqqq',
                'sender_name' => 'Syntetický odesílatel',
                'subject' => 'Rozbitá zpráva',
                'sender_ident' => null,
                'delivered_at' => '2026-08-15 08:00:00',
                'accepted_at' => null,
            ],
            [
                'message_id' => 'DM-OK',
                'sender_box_id' => 'qqqqqqq',
                'sender_name' => 'Syntetický odesílatel',
                'subject' => 'Platná zpráva',
                'sender_ident' => null,
                'delivered_at' => '2026-08-15 08:01:00',
                'accepted_at' => null,
            ],
        ];
        $this->transport->downloadFailures['DM-FAIL'] = new SubmissionChannelException(
            'isds_message_download_failed',
            'Syntetické selhání stažení.',
            502,
        );
        $this->transport->downloads['DM-OK'] = $this->syntheticZfo();

        $result = $this->pollInteractively();

        self::assertSame(2, $result['fetched']);
        self::assertSame(1, $result['stored']);
        self::assertSame(1, $result['failed']);
        self::assertSame('isds_inbox_message_ingest_failed', $result['error']);
        $state = $this->service->pollState($this->supplierId, 'isds', 'test');
        self::assertNotNull($state);
        self::assertNull($state['last_ok_at']);
        self::assertSame(1, $state['consecutive_failures']);
    }

    /** Neznámá zpráva skončí v „nezařazeno" a NIKDY se neváže na podání. */
    public function testUnrecognisedMessageLandsInUnclassifiedWithoutGuessing(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-777',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Neznámý odesílatel s. r. o.',
            'subject' => 'Nějaká zpráva',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-777'] = $this->syntheticZfo();

        $result = $this->pollInteractively();

        self::assertSame(1, $result['stored']);
        self::assertSame(1, $result['unclassified']);

        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-777');
        self::assertNotNull($stored);
        self::assertSame('unclassified', $stored['classification']);
        self::assertNull($stored['matched_outbox_id'], 'Nezařazená zpráva se nesmí hádat na podání.');
    }

    public function testDeliveryReceiptIsRecognisedByItsSubject(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-778',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Informační systém datových schránek',
            'subject' => 'Doručenka k datové zprávě',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-778'] = $this->syntheticZfo();

        $this->pollInteractively();

        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-778');
        self::assertNotNull($stored);
        self::assertSame('delivery_receipt', $stored['classification']);
    }

    /** Stažená zpráva se ukládá do Dokumentů toutéž cestou jako ruční nahrání. */
    public function testDownloadedMessageIsStoredInTheDocumentSection(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-779',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Okresní správa sociálního zabezpečení',
            'subject' => 'Protokol',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-779'] = $this->syntheticZfo();

        $this->pollInteractively();

        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-779');
        self::assertNotNull($stored);
        self::assertNotNull($stored['document_id'], 'Zpráva musí skončit v sekci Dokumenty.');
        self::assertSame('cssz_protocol', $stored['classification']);
    }

    /** Opakované stažení téže zprávy nesmí založit druhý záznam. */
    public function testAlreadyDownloadedMessageIsSkipped(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-780',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Cokoliv',
            'subject' => 'Zpráva',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-780'] = $this->syntheticZfo();

        $first = $this->pollInteractively();
        $second = $this->pollInteractively();

        self::assertSame(1, $first['stored']);
        self::assertSame(0, $second['stored']);
        self::assertSame(1, $second['skipped']);
    }

    /** Ruční zařazení nesmí vytvořit nezařazenou zprávu s vazbou na podání. */
    public function testUnclassifiedCannotBeLinkedToASubmission(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->service->reclassify($this->supplierId, 1, 'unclassified', 5);
    }

    // ───────────────────────── pomocné ─────────────────────────

    private function insertCredential(): void
    {
        $this->credentials->save($this->supplierId, 'isds', 'test', [
            'label' => 'Testovací schránka',
            'box_id' => 'abcdefg',
            'certificate_ciphertext' => 'enc:v2:0000:synteticky',
            'certificate_passphrase_ciphertext' => null,
            'certificate_fingerprint' => null,
            'certificate_valid_to' => null,
        ], $this->userId);
    }

    private function enablePolling(): void
    {
        $this->insertCredential();
    }

    /** @return array{fetched:int,stored:int,skipped:int,failed:int,unclassified:int,error:?string} */
    private function pollInteractively(): array
    {
        return $this->service->poll(
            $this->context(),
            'isds',
            null,
            50,
            $this->userId,
        );
    }

    private function context(): ChannelContext
    {
        return new ChannelContext($this->supplierId, 'test', new ChannelCredentials('abcdefg', 'certificate'));
    }

    /**
     * Bajty, které NEJSOU platné ZFO. Ingest je uloží jako prostý soubor
     * a metadata neextrahuje — pro tenhle test stačí, že dokument vznikne.
     * Skutečné ZFO se do repozitáře nedává: obsahovalo by reálná data.
     */
    private function syntheticZfo(): string
    {
        return "SYNTETICKA-DATOVA-ZPRAVA-BEZ-REALNYCH-UDAJU";
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class implements SubmissionArtifactResolver {
            public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
            {
                return ['filename' => 'podani.xml', 'mime' => 'application/xml', 'bytes' => '<x/>'];
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
