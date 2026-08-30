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
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
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
use MyInvoice\Service\Submission\SubmissionInboxPrivacyService;
use MyInvoice\Service\Submission\SubmissionInboxStorageSettingsService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\SyntheticZfoBuilder;
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
    private SubmissionOutboxRepository $outbox;
    private SubmissionChannelCredentialRepository $credentials;
    private SubmissionInboxStorageSettingsService $storageSettings;
    private SubmissionInboxPrivacyService $privacy;
    private FakeIsdsTransport $transport;
    private int $supplierId;
    private int $userId;
    private DocumentIngestService $documents;
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . '/myucto-inbox-zfo-' . bin2hex(random_bytes(8));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

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
        $this->outbox = $outboxRepo;
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
        $this->documents = $documents;
        $storageSettings = $container->get(SubmissionInboxStorageSettingsService::class);
        self::assertInstanceOf(SubmissionInboxStorageSettingsService::class, $storageSettings);
        $this->storageSettings = $storageSettings;
        $privacy = $container->get(SubmissionInboxPrivacyService::class);
        self::assertInstanceOf(SubmissionInboxPrivacyService::class, $privacy);
        $this->privacy = $privacy;
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
            $storageSettings,
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
        if (isset($this->dataDir) && is_dir($this->dataDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataDir);
        }
        if (isset($this->previousDataDir)) {
            $this->previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
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

    public function testPrivateUnclassifiedMessageCanBeHiddenAndItsLocalTreePurged(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-PRIVATE',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Soukromý syntetický odesílatel',
            'subject' => 'Soukromá syntetická zpráva',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-PRIVATE'] = SyntheticZfoBuilder::receivedMessage([[
            'name' => 'soukroma-priloha.txt',
            'mime' => 'text/plain',
            'bytes' => 'SYNTETICKY-SOUKROMY-OBSAH',
            'meta_type' => 'main',
        ]], ['message_id' => 'DM-PRIVATE']);
        $this->pollInteractively();

        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-PRIVATE');
        self::assertNotNull($stored);
        self::assertSame('unclassified', $stored['classification']);
        $documentRows = $this->db->pdo()->prepare(
            'SELECT id, sha256, filename FROM documents
              WHERE supplier_id = ? AND (id = ? OR parent_document_id = ?)'
        );
        $documentRows->execute([
            $this->supplierId,
            $stored['document_id'],
            $stored['document_id'],
        ]);
        $rows = $documentRows->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $paths = array_map(
            fn (array $row): string => DocumentStorage::baseDir($this->supplierId)
                . '/' . substr((string) $row['sha256'], 0, 2)
                . '/' . $row['filename'],
            $rows,
        );
        foreach ($paths as $path) {
            self::assertFileExists($path);
        }

        $hidden = $this->privacy->hide(
            $this->supplierId,
            (int) $stored['id'],
            (int) $stored['lifecycle_row_version'],
            $this->userId,
        );
        self::assertNotNull($hidden['hidden_at']);
        self::assertSame([], $this->service->listRecent(
            $this->supplierId,
            'test',
            visibility: 'active',
        ));
        self::assertCount(1, $this->service->listRecent(
            $this->supplierId,
            'test',
            visibility: 'hidden',
        ));
        $documentRepository = new DocumentRepository($this->db);
        $viewer = DocumentViewerContext::admin($this->userId);
        $rootDocumentId = (int) $stored['document_id'];
        $childDocumentId = (int) array_values(array_filter(
            array_column($rows, 'id'),
            static fn (mixed $id): bool => (int) $id !== $rootDocumentId,
        ))[0];
        self::assertNull($documentRepository->find(
            $rootDocumentId,
            $this->supplierId,
            $viewer,
        ));
        self::assertNull($documentRepository->find(
            $childDocumentId,
            $this->supplierId,
            $viewer,
        ));

        $restored = $this->privacy->restore(
            $this->supplierId,
            (int) $stored['id'],
            (int) $hidden['lifecycle_row_version'],
            $this->userId,
        );
        self::assertNull($restored['hidden_at']);
        self::assertNotNull($documentRepository->find(
            $rootDocumentId,
            $this->supplierId,
            $viewer,
        ));
        self::assertNotNull($documentRepository->find(
            $childDocumentId,
            $this->supplierId,
            $viewer,
        ));
        $hidden = $this->privacy->hide(
            $this->supplierId,
            (int) $stored['id'],
            (int) $restored['lifecycle_row_version'],
            $this->userId,
        );

        $purged = $this->privacy->purgeLocalContent(
            $this->supplierId,
            (int) $stored['id'],
            (int) $hidden['lifecycle_row_version'],
            $this->userId,
        );
        self::assertSame('purged', $purged['local_content_state']);
        self::assertNull($purged['document_id']);
        self::assertNotNull($purged['local_content_purged_at']);
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND id IN (?, ?)'
        );
        $count->execute([$this->supplierId, $rows[0]['id'], $rows[1]['id']]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testClassifiedMessageCannotUsePrivacyPurge(): void
    {
        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-LEGAL',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Okresní správa sociálního zabezpečení',
            'subject' => 'Protokol',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-LEGAL'] = $this->syntheticZfo();
        $this->pollInteractively();
        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-LEGAL');
        self::assertNotNull($stored);

        try {
            $this->privacy->purgeLocalContent(
                $this->supplierId,
                (int) $stored['id'],
                (int) $stored['lifecycle_row_version'],
                $this->userId,
            );
            self::fail('Zařazený protokol nesmí jít odstranit jako soukromá zpráva.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_inbox_message_has_business_link', $e->errorCode);
        }
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
        // Bez vlastní volby kořene se zprávy nesypou do kořene Dokumentů —
        // archiv si založí vlastní složku, pro testovací provoz oddělenou.
        self::assertSame(
            ['Datová schránka (testovací provoz)', '2026', '08', '15', 'DM-779'],
            $this->folderPath($this->documentFolderId((int) $stored['document_id'])),
        );
    }

    public function testDownloadedMessageUsesConfiguredDeterministicArchivePath(): void
    {
        $this->enablePolling();
        $baseFolderId = $this->createFolder(null, 'ISDS archiv');
        $this->storageSettings->save($this->supplierId, 'test', $baseFolderId, 0, $this->userId);
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-ARCHIVE-1',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Syntetický odesílatel',
            'subject' => 'Archivovaná zpráva',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-ARCHIVE-1'] = $this->syntheticZfo();

        $result = $this->pollInteractively();

        self::assertSame(1, $result['stored']);
        $stored = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-ARCHIVE-1');
        self::assertNotNull($stored);
        self::assertSame(
            ['ISDS archiv', '2026', '08', '15', 'DM-ARCHIVE-1'],
            $this->folderPath($this->documentFolderId((int) $stored['document_id'])),
        );
    }

    public function testDeletedConfiguredArchiveFolderFailsClosedWithoutRootFallback(): void
    {
        $this->enablePolling();
        $baseFolderId = $this->createFolder(null, 'ISDS archiv ke smazání');
        $this->storageSettings->save($this->supplierId, 'test', $baseFolderId, 0, $this->userId);
        $this->db->pdo()->prepare('UPDATE document_folders SET deleted_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$baseFolderId]);
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-DELETED-BASE',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Syntetický odesílatel',
            'subject' => 'Nesmí spadnout do rootu',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-DELETED-BASE'] = $this->syntheticZfo();

        $result = $this->pollInteractively();

        self::assertSame(0, $result['stored']);
        self::assertSame(1, $result['failed']);
        self::assertNull($this->inbox->find($this->supplierId, 'isds', 'test', 'DM-DELETED-BASE'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND original_name = 'datova-zprava-DM-DELETED-BASE.zfo'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /**
     * ČSSZ garantuje vazbu odpovědi přes dmID původní zprávy v předmětu.
     * Bez ní by protokol skončil jen ve správné kategorii, ale u cizího
     * podání by se nikdy neprovedlo jeho ověření a promítnutí výsledku.
     */
    public function testCsszProtocolMatchesJmhzOutboxByGuaranteedOriginalMessageId(): void
    {
        $queued = $this->outbox->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'JMHZ25',
            'recipient_id' => null,
            'recipient_box_id' => '9tsaf6s',
            'subject' => 'Syntetické JMHZ',
            'artifact_kind' => 'payroll_submission',
            'artifact_id' => 987654,
            'artifact_filename' => 'jmhz.xml',
            'artifact_sha256' => str_repeat('a', 64),
            'correlation_reference' => 'JMHZ-SYNTHETIC-01',
            'created_by' => $this->userId,
        ], 'jmhz-inbox-response-match');
        $outboxId = (int) $queued['row']['id'];
        $claimed = $this->outbox->claimForManualSending(
            $this->supplierId,
            $outboxId,
            $this->userId,
        );
        self::assertNotNull($claimed);
        $this->outbox->markSentManually(
            $this->supplierId,
            $outboxId,
            '1752953337',
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            (int) $claimed['row_version'],
        );

        $this->enablePolling();
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-JMHZ-RESPONSE',
            'sender_box_id' => '9tsaf6s',
            'sender_name' => 'Česká správa sociálního zabezpečení',
            'subject' => 'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-CID-ABC-1752953337]',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-JMHZ-RESPONSE'] = $this->syntheticZfo();

        $this->pollInteractively();

        $stored = $this->inbox->find(
            $this->supplierId,
            'isds',
            'test',
            'DM-JMHZ-RESPONSE',
        );
        self::assertNotNull($stored);
        self::assertSame('cssz_protocol', $stored['classification']);
        self::assertSame($outboxId, $stored['matched_outbox_id']);
    }

    public function testHtmlAttachmentFromDownloadedZfoIsStoredForSafeDownload(): void
    {
        $this->enablePolling();
        $html = '<!doctype html><html><body><h1>Syntetické oznámení</h1></body></html>';
        $this->transport->inboxMessages = [[
            'message_id' => 'DM-HTML',
            'sender_box_id' => 'qqqqqqq',
            'sender_name' => 'Informační systém datových schránek',
            'subject' => 'Důležité oznámení',
            'sender_ident' => null,
            'delivered_at' => '2026-08-15 08:00:00',
            'accepted_at' => null,
        ]];
        $this->transport->downloads['DM-HTML'] = SyntheticZfoBuilder::receivedMessage([[
            'name' => 'oznámení.html',
            'mime' => 'text/html',
            'bytes' => $html,
            'meta_type' => 'main',
        ]], ['message_id' => 'DM-HTML']);

        $result = $this->pollInteractively();

        self::assertSame(1, $result['stored']);
        $inbox = $this->inbox->find($this->supplierId, 'isds', 'test', 'DM-HTML');
        self::assertNotNull($inbox);
        $stmt = $this->db->pdo()->prepare(
            'SELECT original_name, mime_type, doc_type, sha256, filename
               FROM documents
              WHERE supplier_id = ? AND parent_document_id = ? AND deleted_at IS NULL',
        );
        $stmt->execute([$this->supplierId, $inbox['document_id']]);
        $attachments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $attachments);
        self::assertSame('oznámení.html', $attachments[0]['original_name']);
        self::assertSame('application/octet-stream', $attachments[0]['mime_type']);
        self::assertSame('other', $attachments[0]['doc_type']);
        $path = DocumentStorage::baseDir($this->supplierId)
            . '/' . substr((string) $attachments[0]['sha256'], 0, 2)
            . '/' . $attachments[0]['filename'];
        self::assertSame($html, file_get_contents($path));

        $this->db->pdo()->prepare('DELETE FROM documents WHERE supplier_id = ? AND parent_document_id = ?')
            ->execute([$this->supplierId, $inbox['document_id']]);
        $recovered = $this->documents->reextractZfoAttachments(
            (int) $inbox['document_id'],
            $this->supplierId,
            DocumentViewerContext::admin($this->userId),
            $this->userId,
        );
        self::assertCount(1, $recovered['created_ids']);

        $again = $this->documents->reextractZfoAttachments(
            (int) $inbox['document_id'],
            $this->supplierId,
            DocumentViewerContext::admin($this->userId),
            $this->userId,
        );
        self::assertSame([], $again['created_ids']);
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
        $this->service->reclassify($this->supplierId, 1, 'unclassified', 5, 1);
    }

    public function testReclassificationBumpsLifecycleAndMakesStaleHideFail(): void
    {
        $row = $this->inbox->record($this->inboxRow('DM-RECLASSIFY'));

        self::assertTrue($this->service->reclassify(
            $this->supplierId,
            (int) $row['id'],
            'tax_office_response',
            null,
            (int) $row['lifecycle_row_version'],
        ));
        $changed = $this->inbox->findById($this->supplierId, (int) $row['id']);
        self::assertNotNull($changed);
        self::assertSame(
            (int) $row['lifecycle_row_version'] + 1,
            $changed['lifecycle_row_version'],
        );

        try {
            $this->privacy->hide(
                $this->supplierId,
                (int) $row['id'],
                (int) $row['lifecycle_row_version'],
                $this->userId,
            );
            self::fail('Zastaralá verze nesmí skrýt nově zařazenou zprávu.');
        } catch (SubmissionChannelException $e) {
            self::assertContains(
                $e->errorCode,
                ['isds_inbox_message_has_business_link', 'isds_inbox_privacy_conflict'],
            );
        }
    }

    public function testMatchedBusinessMessageCannotBeUnlinkedByReclassification(): void
    {
        $recipient = $this->createRecipient('recipient_immutable', 'zzzzzzz');
        $queued = $this->outbox->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'JMHZ',
            'recipient_id' => $recipient,
            'recipient_box_id' => 'zzzzzzz',
            'subject' => 'Syntetické podání',
            'artifact_kind' => 'document',
            'artifact_id' => 1,
            'artifact_filename' => 'synthetic.xml',
            'artifact_sha256' => hash('sha256', 'synthetic'),
            'correlation_reference' => 'JMHZ-IMMUTABLE-' . bin2hex(random_bytes(4)),
            'created_by' => $this->userId,
        ], 'immutable-' . bin2hex(random_bytes(8)));
        $row = $this->inbox->record([
            ...$this->inboxRow('DM-IMMUTABLE'),
            'classification' => 'cssz_protocol',
            'matched_outbox_id' => (int) $queued['row']['id'],
        ]);

        try {
            $this->service->reclassify(
                $this->supplierId,
                (int) $row['id'],
                'unclassified',
                null,
                (int) $row['lifecycle_row_version'],
            );
            self::fail('Business vazba příchozí zprávy nesmí jít odpojit.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_inbox_business_link_immutable', $e->errorCode);
        }
        $unchanged = $this->inbox->findById($this->supplierId, (int) $row['id']);
        self::assertNotNull($unchanged);
        self::assertSame((int) $queued['row']['id'], $unchanged['matched_outbox_id']);
        self::assertSame('cssz_protocol', $unchanged['classification']);
    }

    public function testInterruptedPhysicalPurgeRemainsRetryableUntilFilesAreVerifiedGone(): void
    {
        $row = $this->inbox->record($this->inboxRow('DM-PURGE-RETRY'));
        $sha = hash('sha256', 'synthetic-purge-retry');
        $filename = 'synthetic-purge-retry.bin';
        $this->db->pdo()->prepare(
            'UPDATE submission_inbox_messages
                SET local_content_state = \'purging\', lifecycle_row_version = lifecycle_row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $row['id']]);
        $this->db->pdo()->prepare(
            'INSERT INTO submission_inbox_purge_manifest
                (supplier_id, inbox_message_id, entry_no, sha256, internal_filename)
             VALUES (?, ?, 1, ?, ?)'
        )->execute([$this->supplierId, $row['id'], $sha, $filename]);
        $blockingPath = DocumentStorage::baseDir($this->supplierId)
            . '/' . substr($sha, 0, 2) . '/' . $filename;
        self::assertTrue(mkdir($blockingPath, 0755, true));

        $pending = $this->privacy->purgeLocalContent(
            $this->supplierId,
            (int) $row['id'],
            (int) $row['lifecycle_row_version'] + 1,
            $this->userId,
        );
        self::assertSame('purging', $pending['local_content_state']);
        self::assertSame('failed', $this->db->pdo()->query(
            'SELECT status FROM submission_inbox_purge_manifest WHERE inbox_message_id = ' . (int) $row['id'],
        )->fetchColumn());

        self::assertTrue(rmdir($blockingPath));
        $purged = $this->privacy->purgeLocalContent(
            $this->supplierId,
            (int) $row['id'],
            (int) $pending['lifecycle_row_version'],
            $this->userId,
        );
        self::assertSame('purged', $purged['local_content_state']);
        self::assertSame('deleted', $this->db->pdo()->query(
            'SELECT status FROM submission_inbox_purge_manifest WHERE inbox_message_id = ' . (int) $row['id'],
        )->fetchColumn());
    }

    // ───────────────────────── pomocné ─────────────────────────

    /** @return array<string,mixed> */
    private function inboxRow(string $messageId): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'external_message_id' => $messageId,
            'sender_box_id' => 'abc1234',
            'sender_name' => 'Syntetický odesílatel',
            'subject' => 'Syntetická zpráva',
            'sender_ident' => null,
            'classification' => 'unclassified',
            'matched_outbox_id' => null,
            'document_id' => null,
            'delivered_at' => '2026-08-27 08:00:00',
            'accepted_at' => null,
            'raw_sha256' => hash('sha256', $messageId),
        ];
    }

    private function createRecipient(string $code, string $boxId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO submission_recipients
                (supplier_id, code, name, kind, isds_box_id, source_url, created_by)
             VALUES (?, ?, ?, \'other\', ?, ?, ?)',
        );
        $stmt->execute([
            $this->supplierId,
            $code,
            'Syntetický příjemce',
            $boxId,
            'https://example.invalid/recipient-source',
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

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

    private function createFolder(?int $parentId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_folders (supplier_id, parent_id, name, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $parentId, $name, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function documentFolderId(int $documentId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT folder_id FROM documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$documentId, $this->supplierId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (int) $value : null;
    }

    /** @return list<string> */
    private function folderPath(?int $folderId): array
    {
        $path = [];
        while ($folderId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT parent_id, name FROM document_folders WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$folderId, $this->supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            array_unshift($path, (string) $row['name']);
            $folderId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }
        return $path;
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
