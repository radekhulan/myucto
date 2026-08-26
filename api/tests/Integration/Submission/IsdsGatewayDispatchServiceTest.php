<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsGatewayRegistrationRepository;
use MyInvoice\Repository\Submission\IsdsGatewaySessionRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayCredential;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayDispatchService;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistrationService;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsGatewayClient;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Odeslání přes odesílací bránu ISDS — izolace tenantů, idempotence a to,
 * co se stane, když se uživatel nevrátí nebo koncept zamítne.
 *
 * Nic tu nesahá na síť ({@see FakeIsdsGatewayClient}) a už vůbec ne na ostrou
 * datovou schránku.
 */
#[Group('integration')]
final class IsdsGatewayDispatchServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ENVIRONMENT = 'test';

    private Connection $db;
    private SubmissionOutboxRepository $outbox;
    private IsdsGatewaySessionRepository $sessions;
    private SubmissionOutboxService $queue;
    private IsdsGatewayDispatchService $service;
    private FakeIsdsGatewayClient $client;

    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $otherUserId;
    private int $recipientId;
    private int $otherRecipientId;

    private string $artifactBytes = '<podani/>';

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->outbox = new SubmissionOutboxRepository($db);
        $this->sessions = new IsdsGatewaySessionRepository($db);
        $registrations = new IsdsGatewayRegistrationRepository($db);
        if (!$this->outbox->isAvailable() || !$this->sessions->isAvailable() || !$registrations->isAvailable()) {
            $this->markTestSkipped('Migrace 1411–1413 neproběhly.');
        }

        $crypto = $container->get(SecretEncryption::class);
        self::assertInstanceOf(SecretEncryption::class, $crypto);
        if ($crypto->validateKey() !== null) {
            $this->markTestSkipped('Bez cfg.app.secret_encryption_key nelze trezor brány testovat.');
        }

        $pdo = $db->pdo();
        $pdo->beginTransaction();

        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $template);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $template);

        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->otherUserId = $this->cloneUser($pdo, $this->userId);

        $recipients = new SubmissionRecipientRepository($db);
        $this->recipientId = $this->recipient($recipients, $this->supplierId);
        $this->otherRecipientId = $this->recipient($recipients, $this->otherSupplierId);

        // Registrace provozovatele. Certifikát se sem zapisuje už zašifrovaný —
        // tabulka nic jiného nepřijme (CHECK `LIKE 'enc:v%'`).
        $registrations->save(self::ENVIRONMENT, [
            'ats_id' => 'TESTGW1',
            'label' => 'Testovací brána',
            'return_url' => 'https://dev.myucto.cz/api/submissions/gateway/callback',
            'error_url' => null,
            'concept_ttl_seconds' => 900,
            'portal_host' => 'datovka-test.gov.cz',
            'service_host' => 'cert.datovka-test.gov.cz',
            'user_login_policy' => 'unknown',
            'certificate_ciphertext' => $crypto->encryptFor(base64_encode('synteticky-pkcs12'), 'isds:gateway-certificate'),
            'certificate_passphrase_ciphertext' => null,
            'certificate_fingerprint' => str_repeat('a', 64),
            'certificate_valid_to' => null,
            'is_active' => true,
        ], $this->userId);

        $this->client = new FakeIsdsGatewayClient();

        $attempts = new SubmissionOutboxAttemptRepository($db);
        $this->queue = new SubmissionOutboxService(
            $this->outbox,
            $attempts,
            $recipients,
            new SubmissionChannelRegistry(
                new EpoChannel($this->stubEpoReader()),
                new IsdsChannel(new UnavailableIsdsTransport()),
            ),
            $this->stubArtifacts(),
            new SubmissionArtifactValidator(new XmlSchemaValidator()),
            new NullLogger(),
            null,
        );

        $this->service = new IsdsGatewayDispatchService(
            $this->db,
            $this->outbox,
            $attempts,
            $this->sessions,
            new IsdsGatewayRegistrationService($registrations, $crypto),
            $this->client,
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

    // ═══════════════════════ izolace tenantů ═══════════════════════

    /**
     * Jádro celého modulu: zpráva jedné firmy se nesmí odeslat pod identitou
     * druhé.
     *
     * Test se o to SKUTEČNĚ POKOUŠÍ — vezme `appToken` firmy A a přijde s ním
     * jako firma B. Neověřuje se jen, že se metoda zavolala se správným
     * argumentem, ale že se do ISDS NEODEŠLO NIC: `exchangedSessions`
     * i `pushedConcepts` musí zůstat prázdné, takže se ani nespotřebovalo
     * `sessionId`.
     */
    public function testForeignTenantCannotCompleteAnotherCompanysSession(): void
    {
        $started = $this->service->start($this->supplierId, $this->enqueue($this->supplierId, $this->recipientId), $this->userId);

        try {
            $this->service->complete(
                $this->otherSupplierId,
                $this->userId,
                $started['app_token'],
                '01-cizi-session-id',
            );
            self::fail('Cizí firma nesmí dokončit odeslání.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_session_foreign', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }

        self::assertSame([], $this->client->exchangedSessions, 'Cizí pokus nesmí spotřebovat sessionId.');
        self::assertSame([], $this->client->pushedConcepts, 'Cizí pokus nesmí vložit koncept do ISDS.');
    }

    /** Schválení odeslání je právní úkon — nedokončí ho za kolegu někdo jiný. */
    public function testAnotherUserOfTheSameCompanyCannotCompleteTheSession(): void
    {
        $started = $this->service->start($this->supplierId, $this->enqueue($this->supplierId, $this->recipientId), $this->userId);

        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('Dokončit ho musí tentýž člověk');
        $this->service->complete($this->supplierId, $this->otherUserId, $started['app_token'], '01-session');
    }

    /**
     * Souběžné relace dvou firem se nesmí zaměnit: každá vloží do ISDS SVŮJ
     * artefakt se SVOU spisovou značkou.
     */
    public function testConcurrentSessionsOfTwoCompaniesStayApart(): void
    {
        $firstOutbox = $this->enqueue($this->supplierId, $this->recipientId);
        $secondOutbox = $this->enqueue($this->otherSupplierId, $this->otherRecipientId);

        $first = $this->service->start($this->supplierId, $firstOutbox, $this->userId);
        $second = $this->service->start($this->otherSupplierId, $secondOutbox, $this->userId);

        $this->service->complete($this->supplierId, $this->userId, $first['app_token'], '01-session-a');
        $this->service->complete($this->otherSupplierId, $this->userId, $second['app_token'], '01-session-b');

        self::assertCount(2, $this->client->pushedConcepts);
        $firstRow = $this->outbox->find($this->supplierId, $firstOutbox);
        $secondRow = $this->outbox->find($this->otherSupplierId, $secondOutbox);
        self::assertNotNull($firstRow);
        self::assertNotNull($secondRow);

        self::assertSame($firstRow['correlation_reference'], $this->client->pushedConcepts[0]['sender_ident']);
        self::assertSame($secondRow['correlation_reference'], $this->client->pushedConcepts[1]['sender_ident']);
        self::assertNotSame(
            $this->client->pushedConcepts[0]['sender_ident'],
            $this->client->pushedConcepts[1]['sender_ident'],
        );
    }

    // ═══════════════════════ idempotence ═══════════════════════

    /** Dvojí kliknutí na „odeslat" nesmí vyrobit dva koncepty. */
    public function testStartingTwiceResumesTheSameSession(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);

        $first = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $second = $this->service->start($this->supplierId, $outboxId, $this->userId);

        self::assertFalse($first['resumed']);
        self::assertTrue($second['resumed']);
        self::assertSame($first['session_id'], $second['session_id']);
        self::assertCount(1, $this->sessions->listForOutbox($this->supplierId, $outboxId));
    }

    /**
     * Obnovení stránky nad návratovým URL nesmí vložit druhý koncept.
     *
     * Chrání to dvojitě: relace už není `awaiting_login` (takže se do vkládání
     * konceptu vůbec nedojde) a `sessionId` je v ISDS jednorázové (takže by
     * druhý pokus stejně skončil odmítnutím). Podání zůstává `ready`.
     */
    public function testRepeatedLoginCallbackPushesOnlyOneConcept(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);

        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        try {
            $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');
            self::fail('Znovupoužité sessionId musí datová schránka odmítnout.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_session_rejected', $e->errorCode);
        }

        self::assertCount(1, $this->client->pushedConcepts, 'Koncept se smí vložit právě jednou.');
        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('ready', $row['dispatch_state']);
    }

    /**
     * Celý průchod: přihlášení → koncept → schválení. Podání skončí `sent`
     * s dmID od ISDS a v ledgeru je právě jeden pokus. Opakovaný návrat
     * po schválení už nic nemění.
     */
    public function testApprovalRecordsDispatchExactlyOnce(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);

        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        // Fronta je pořád `ready`: vložený koncept není odeslaná zpráva.
        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('ready', $row['dispatch_state']);

        $this->client->conceptResolved = true;
        $result = $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-2');

        self::assertSame('approved', $result['state']);
        self::assertSame('DM-9000', $result['external_message_id']);

        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('sent', $row['dispatch_state']);
        self::assertSame('gateway', $row['dispatch_mode']);
        self::assertSame('DM-9000', $row['external_message_id']);
        self::assertSame($this->userId, $row['confirmed_by']);
        // Doručenka není protokol o přijetí — osa vyřízení zůstává nedotčená.
        self::assertSame('unknown', $row['acceptance_state']);

        self::assertCount(1, $this->queue->attemptsFor($this->supplierId, $outboxId));

        // Uživatel obnovil stránku: druhé odeslání se nekoná.
        $again = $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-3');
        self::assertSame('approved', $again['state']);
        self::assertCount(1, $this->queue->attemptsFor($this->supplierId, $outboxId));
    }

    /**
     * Pád staršího procesu mohl nastat po uzavření gateway relace, ale před
     * projekcí dmID do outboxu. Opakovaný callback musí chybějící lokální zápis
     * dokončit, ne pouze podle relace tvrdit, že už je podání evidované.
     */
    public function testApprovedSessionRepairsMissingOutboxProjection(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        $sessions = $this->sessions->listForOutbox($this->supplierId, $outboxId);
        self::assertCount(1, $sessions);
        self::assertNotNull($this->sessions->markApproved(
            $this->supplierId,
            (int) $sessions[0]['id'],
            'DM-RECOVERED',
            '0000',
            'Zpráva byla odeslána.',
        ));

        $before = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($before);
        self::assertSame('ready', $before['dispatch_state']);

        $result = $this->service->complete(
            $this->supplierId,
            $this->userId,
            $started['app_token'],
            '01-session-unused',
        );

        self::assertSame('approved', $result['state']);
        self::assertSame('DM-RECOVERED', $result['external_message_id']);

        $after = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($after);
        self::assertSame('sent', $after['dispatch_state']);
        self::assertSame('gateway', $after['dispatch_mode']);
        self::assertSame('DM-RECOVERED', $after['external_message_id']);

        $attempts = $this->queue->attemptsFor($this->supplierId, $outboxId);
        self::assertCount(1, $attempts);
        self::assertSame('sent', $attempts[0]['outcome']);
        self::assertSame('DM-RECOVERED', $attempts[0]['external_message_id']);
    }

    /** Externí dmID se nesmí ztratit ani tehdy, když lokální outbox mezitím někdo změnil. */
    public function testApprovedMessageIdSurvivesOutboxProjectionConflict(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        $this->outbox->cancel($this->supplierId, $outboxId, (int) $row['row_version']);

        $this->client->conceptResolved = true;
        try {
            $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-2');
            self::fail('Kolize lokální projekce nesmí být hlášena jako úspěšně dokončené odeslání.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_projection_failed', $e->errorCode);
            self::assertStringContainsString('znovu neodesílejte', mb_strtolower($e->getMessage()));
        }

        $sessions = $this->sessions->listForOutbox($this->supplierId, $outboxId);
        self::assertCount(1, $sessions);
        self::assertSame('approved', $sessions[0]['state']);
        self::assertSame('DM-9000', $sessions[0]['concept_dm_id']);

        $after = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($after);
        self::assertSame('cancelled', $after['dispatch_state']);
        self::assertSame([], $this->queue->attemptsFor($this->supplierId, $outboxId));
    }

    /**
     * Relace, ze které se uživatel nevrátil, nesmí podání zablokovat natrvalo.
     * UNIQUE nad živými relacemi je jinak past: bez úklidu by šlo podání
     * odeslat jen tak, že se založí znovu.
     */
    public function testAbandonedSessionExpiresAndUnblocksTheSubmission(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);

        $this->db->pdo()
            ->prepare('UPDATE isds_gateway_sessions SET expires_at = ?, row_version = row_version + 1 WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 3600), $started['session_id']]);

        $retry = $this->service->start($this->supplierId, $outboxId, $this->userId);

        self::assertFalse($retry['resumed'], 'Vypršelá relace se neobnovuje, zakládá se nová.');
        self::assertNotSame($started['session_id'], $retry['session_id']);

        $expired = $this->sessions->find($this->supplierId, $started['session_id']);
        self::assertNotNull($expired);
        self::assertSame('expired', $expired['state']);
    }

    // ═══════════════════════ nešťastné cesty ═══════════════════════

    /**
     * Zamítnutí uživatelem (kód 2305) prokazatelně nic neodeslalo, takže
     * podání zůstává připravené a jde spustit znovu. Kdyby se řádek zabral
     * dřív, skončil by nevratně v `sending`.
     */
    public function testUserRejectionLeavesTheSubmissionReadyForAnotherTry(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        $this->client->conceptResolved = true;
        $this->client->outcomeStatusCode = IsdsGatewayCredential::STATUS_REJECTED_BY_USER;
        $this->client->outcomeStatusMessage = 'Uživatel zamítl odeslání.';

        $result = $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-2');

        self::assertSame('rejected', $result['state']);
        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('ready', $row['dispatch_state']);
        self::assertNull($row['external_message_id']);

        // A dá se to zkusit znovu — relace už není živá, takže vznikne nová.
        $retry = $this->service->start($this->supplierId, $outboxId, $this->userId);
        self::assertFalse($retry['resumed']);
        self::assertNotSame($started['session_id'], $retry['session_id']);
    }

    /**
     * Když po schválení nedostaneme odpověď, NEVÍME, jestli zpráva odešla.
     * Fronta proto skončí v `send_uncertain`, ne zpátky v `ready` — jinak by
     * ji uživatel odeslal podruhé a úřad by dostal duplicitu.
     */
    public function testUnknownOutcomeParksTheSubmissionAsUncertain(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');

        $this->client->exchangeBehaviour = 'timeout';

        try {
            $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-2');
            self::fail('Neznámý výsledek musí skončit chybou, ne tichým průchodem.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_outcome_uncertain', $e->errorCode);
        }

        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('send_uncertain', $row['dispatch_state']);
        self::assertNull($row['external_message_id']);
    }

    /**
     * Přerušení PŘI VKLÁDÁNÍ konceptu je jiná situace: bez schválení
     * uživatelem zpráva odejít nemohla, takže je bezpečné nechat podání
     * připravené.
     */
    public function testInterruptedConceptPushKeepsTheSubmissionReady(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->client->conceptBehaviour = 'timeout';

        try {
            $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');
            self::fail('Přerušené vložení konceptu musí skončit chybou.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_concept_uncertain', $e->errorCode);
        }

        $row = $this->outbox->find($this->supplierId, $outboxId);
        self::assertNotNull($row);
        self::assertSame('ready', $row['dispatch_state']);
        self::assertSame([], $this->client->pushedConcepts);
    }

    /** Chybějící `appToken` nesmí vést na nic — ani na síťové volání. */
    public function testUnknownTokenIsRefusedWithoutTouchingTheNetwork(): void
    {
        try {
            $this->service->complete($this->supplierId, $this->userId, '999999999999999999', '01-session');
            self::fail('Neznámý token nesmí projít.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_session_unknown', $e->errorCode);
        }
        self::assertSame([], $this->client->exchangedSessions);
    }

    /** Vypnutá registrace = fail-closed s pojmenovaným důvodem, ne tichý pád. */
    public function testDisabledRegistrationIsFailClosed(): void
    {
        (new IsdsGatewayRegistrationRepository($this->db))->setActive(self::ENVIRONMENT, false);
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);

        try {
            $this->service->start($this->supplierId, $outboxId, $this->userId);
            self::fail('Vypnutá brána nesmí nic odeslat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_disabled', $e->errorCode);
            self::assertSame(503, $e->httpStatus);
        }
        self::assertSame([], $this->client->pushedConcepts);
    }

    /** Chybějící registrace taky — a uživateli se řekne, co má dělat místo toho. */
    public function testMissingRegistrationIsFailClosed(): void
    {
        (new IsdsGatewayRegistrationRepository($this->db))->delete(self::ENVIRONMENT);
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);

        try {
            $this->service->start($this->supplierId, $outboxId, $this->userId);
            self::fail('Bez registrace nesmí brána nic odeslat.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('isds_gateway_not_configured', $e->errorCode);
            self::assertStringContainsString('ručně', $e->getMessage());
        }
    }

    /** Podklad, který se mezi zahájením a návratem změnil, se do ISDS nedostane. */
    public function testChangedArtifactStopsTheConceptPush(): void
    {
        $outboxId = $this->enqueue($this->supplierId, $this->recipientId);
        $started = $this->service->start($this->supplierId, $outboxId, $this->userId);
        $this->artifactBytes = '<zmenene/>';

        $this->expectException(SubmissionChannelException::class);
        try {
            $this->service->complete($this->supplierId, $this->userId, $started['app_token'], '01-session-1');
        } finally {
            self::assertSame([], $this->client->pushedConcepts);
        }
    }

    // ───────────────────────── pomocné ─────────────────────────

    private function enqueue(int $supplierId, int $recipientId): int
    {
        $result = $this->queue->enqueue(
            $supplierId,
            self::ENVIRONMENT,
            'isds',
            'HOZ',
            'document',
            42,
            $recipientId,
            'Testovací podání odesílací branou',
            $this->userId,
        );

        return (int) $result['row']['id'];
    }

    /**
     * Druhý uživatel klonováním prvního.
     *
     * Testovací databáze má jen jeden účet, ale vazba „dokončit smí jen ten,
     * kdo začal" se bez druhého uživatele ověřit nedá — a přeskočený test by
     * ji nehlídal vůbec.
     */
    private function cloneUser(\PDO $pdo, int $templateId): int
    {
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME NOT IN ('id')
                AND (EXTRA IS NULL OR EXTRA NOT LIKE '%GENERATED%')"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $quoted = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
        // E-mail je unikátní, takže se musí odlišit už v INSERTu — dodatečný
        // UPDATE by nepomohl, kolize nastane hned při zápisu.
        $selected = array_map(
            static fn (string $c): string => $c === 'email' ? '?' : '`' . $c . '`',
            $columns,
        );

        $pdo->prepare(
            'INSERT INTO users (' . implode(',', $quoted) . ')
             SELECT ' . implode(',', $selected) . ' FROM users WHERE id = ?'
        )->execute(['gateway-clone-' . bin2hex(random_bytes(6)) . '@example.invalid', $templateId]);

        return (int) $pdo->lastInsertId();
    }

    private function recipient(SubmissionRecipientRepository $recipients, int $supplierId): int
    {
        return $recipients->upsertForSupplier($supplierId, [
            'code' => 'fu_testovaci',
            'name' => 'Testovací finanční úřad',
            'kind' => 'tax_office',
            'isds_box_id' => 'abcdefg',
            'source_url' => 'https://example.test/synteticky-zdroj',
            'source_note' => 'Syntetický záznam pro test',
            'is_active' => true,
        ], $this->userId);
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class ($this) implements SubmissionArtifactResolver {
            public function __construct(private readonly IsdsGatewayDispatchServiceTest $test) {}

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
