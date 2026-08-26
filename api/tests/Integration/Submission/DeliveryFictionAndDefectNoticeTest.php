<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionDefectNoticeRepository;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Submission\Channel\Epo\EpoAttemptStatusReader;
use MyInvoice\Service\Submission\Channel\Epo\EpoChannel;
use MyInvoice\Service\Submission\Channel\Isds\IsdsChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\DefectGround;
use MyInvoice\Service\Submission\DefectNoticeAssessor;
use MyInvoice\Service\Submission\DefectNoticeService;
use MyInvoice\Service\Submission\DeliveryBasis;
use MyInvoice\Service\Submission\DeliveryFictionCalculator;
use MyInvoice\Service\Submission\DeliveryResolutionService;
use MyInvoice\Service\Submission\InboxMessageClassifier;
use MyInvoice\Service\Submission\SubmissionArtifactResolver;
use MyInvoice\Service\Submission\SubmissionArtifactValidator;
use MyInvoice\Service\Submission\SubmissionChannelRegistry;
use MyInvoice\Service\Submission\SubmissionLegalRules;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\FakeIsdsTransport;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

/**
 * Uložený rozhodný den doručení a evidence výzvy podle § 74 DŘ — proti databázi.
 *
 * ⚠️ Žádný test tady nesahá na síť ani na skutečnou datovou schránku. Zprávy se
 * zapisují přímo do tabulky, čas řídí falešné hodiny.
 *
 * Co se hlídá:
 *   1. závěr o doručení se UKLÁDÁ, ne dopočítává — a mění se, jen když se změní
 *      fakta nebo uplyne lhůta,
 *   2. databáze nepustí doručení bez podkladu ani podklad bez doručení,
 *   3. výzva bez lhůty zůstane v „nevíme" a nikdy neprojde jako vyřízená,
 *   4. tatáž zpráva nezaloží druhou výzvu.
 */
#[Group('integration')]
final class DeliveryFictionAndDefectNoticeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const AUTHORITY_BOX = 'abcdefg';
    private const PRIVATE_BOX = 'zyxwvut';

    private Connection $db;
    private SubmissionInboxRepository $inbox;
    private SubmissionOutboxRepository $outbox;
    private SubmissionOutboxService $outboxService;
    private DeliveryResolutionService $delivery;
    private DefectNoticeService $notices;
    private MutableTestClock $clock;
    private int $supplierId;
    private int $userId;
    private int $recipientId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;

        $this->inbox = new SubmissionInboxRepository($db);
        if (!$this->inbox->supportsDeliveryResolution()) {
            $this->markTestSkipped('Migrace 1394 neproběhla.');
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
            'isds_box_id' => self::AUTHORITY_BOX,
            'source_url' => 'https://example.test/synteticky-zdroj',
            'source_note' => 'Syntetický záznam pro test',
            'is_active' => true,
        ], $this->userId);

        $this->outbox = new SubmissionOutboxRepository($db);
        $this->outboxService = new SubmissionOutboxService(
            $this->outbox,
            new SubmissionOutboxAttemptRepository($db),
            $recipients,
            new SubmissionChannelRegistry(
                new EpoChannel($this->stubEpoReader()),
                new IsdsChannel(new FakeIsdsTransport()),
            ),
            $this->stubArtifacts(),
            new SubmissionArtifactValidator(new XmlSchemaValidator()),
            new NullLogger(),
            null,
        );

        $activity = $container->get(ActivityLogger::class);
        self::assertInstanceOf(ActivityLogger::class, $activity);

        $this->clock = new MutableTestClock(new \DateTimeImmutable('2026-03-05 09:00:00', new \DateTimeZone('Europe/Prague')));
        $rules = new SubmissionLegalRules(CzechPayrollRulesets2026::provider());

        $this->delivery = new DeliveryResolutionService(
            $this->inbox,
            $recipients,
            new DeliveryFictionCalculator(),
            $rules,
            $activity,
            $this->clock,
        );
        $this->notices = new DefectNoticeService(
            new SubmissionDefectNoticeRepository($db),
            $this->inbox,
            $this->outbox,
            new DefectNoticeAssessor($rules),
            $this->delivery,
            $activity,
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    // ───────────────────────── doručení ─────────────────────────

    /** Dodáno, lhůta běží — doručeno NENÍ a den se neukládá. */
    public function testRunningPeriodIsStoredAsPendingWithoutADate(): void
    {
        $message = $this->storeMessage(['delivered_at' => '2026-03-02 09:15:00']);

        $resolved = $this->delivery->resolveMessage($message);
        self::assertSame(DeliveryBasis::Pending, $resolved->basis);

        $stored = $this->inbox->findById($this->supplierId, (int) $message['id']);
        self::assertNotNull($stored);
        self::assertSame('pending', $stored['delivery_basis']);
        self::assertNull($stored['delivered_on'], 'Během lhůty se den doručení ukládat nesmí.');
        self::assertSame('2026-03-12', $stored['fiction_due_on']);
        self::assertNotNull($stored['delivery_resolved_at']);
    }

    /** Uplynutí lhůty závěr změní — a rozhodným dnem zůstane desátý den. */
    public function testPeriodExpiryTurnsPendingIntoFiction(): void
    {
        $message = $this->storeMessage(['delivered_at' => '2026-03-02 09:15:00']);
        $this->delivery->resolveMessage($message);

        $this->clock->set('2026-03-16 08:00:00');
        $result = $this->delivery->refresh($this->supplierId, 'test');

        self::assertSame(1, $result['checked']);
        self::assertSame(1, $result['changed']);
        self::assertSame(1, $result['delivered_by_fiction']);

        $stored = $this->inbox->findById($this->supplierId, (int) $message['id']);
        self::assertNotNull($stored);
        self::assertSame('fiction', $stored['delivery_basis']);
        self::assertSame('2026-03-12', $stored['delivered_on']);
        self::assertSame(10, $stored['fiction_days']);
        self::assertSame('statute', $stored['fiction_days_source']);
    }

    /** Přihlášení ve lhůtě doručuje dřív a fikci vytlačí. */
    public function testLoginWithinPeriodIsStoredAsLogin(): void
    {
        $message = $this->storeMessage([
            'delivered_at' => '2026-03-02 09:15:00',
            'accepted_at' => '2026-03-04 11:00:00',
        ]);
        $this->delivery->resolveMessage($message);

        $stored = $this->inbox->findById($this->supplierId, (int) $message['id']);
        self::assertNotNull($stored);
        self::assertSame('login', $stored['delivery_basis']);
        self::assertSame('2026-03-04', $stored['delivered_on']);
    }

    /**
     * Odesílatel mimo číselník = fikce se neuplatní. Zpráva zůstane v „nevíme"
     * a při dalším přepočtu se zkusí znovu — „nevíme" nesmí být doživotní.
     */
    public function testSenderOutsideTheRegistryNeverGetsFiction(): void
    {
        $message = $this->storeMessage([
            'delivered_at' => '2026-03-02 09:15:00',
            'sender_box_id' => self::PRIVATE_BOX,
        ]);
        $this->clock->set('2026-04-01 08:00:00');
        $this->delivery->resolveMessage($message);

        $stored = $this->inbox->findById($this->supplierId, (int) $message['id']);
        self::assertNotNull($stored);
        self::assertSame('unknown', $stored['delivery_basis']);
        self::assertNull($stored['delivered_on']);
        self::assertNull($stored['sender_is_public_authority'], 'Nevíme není totéž co „není orgán veřejné moci".');

        self::assertCount(
            1,
            $this->inbox->listDeliveryPending($this->supplierId, 'test'),
            'Nevyhodnocená zpráva musí zůstat ve frontě k přepočtu.',
        );
    }

    /**
     * Databázová pojistka: den doručení bez podkladu (a naopak) nesmí jít
     * uložit ani ručním UPDATE, který obejde službu.
     */
    public function testDatabaseRefusesDeliveryDateWithoutABasis(): void
    {
        $message = $this->storeMessage(['delivered_at' => '2026-03-02 09:15:00']);

        $this->expectException(\PDOException::class);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE submission_inbox_messages
                SET delivery_basis = \'unknown\', delivered_on = \'2026-03-12\', delivery_resolved_at = UTC_TIMESTAMP()
              WHERE id = ?'
        );
        $stmt->execute([(int) $message['id']]);
    }

    /** Doručenka k NAŠEMU podání se fikcí neposuzuje — směr je opačný. */
    public function testDeliveryReceiptIsNotSubjectToFiction(): void
    {
        $message = $this->storeMessage([
            'classification' => InboxMessageClassifier::DELIVERY_RECEIPT,
            'delivered_at' => '2026-03-02 09:15:00',
        ]);
        $this->clock->set('2026-04-01 08:00:00');

        $resolved = $this->delivery->resolveMessage($message);

        self::assertSame(DeliveryBasis::Unknown, $resolved->basis);
        self::assertStringContainsString('odeslanému podání', $resolved->note);
        self::assertSame(
            [],
            $this->inbox->listDeliveryPending($this->supplierId, 'test'),
            'Doručenka do fronty přepočtu doručení nepatří.',
        );
    }

    // ───────────────────────── výzva podle § 74 DŘ ─────────────────────────

    /** Výzva navázaná na zprávu si vezme rozhodný den doručení z ní. */
    public function testNoticeInheritsTheResolvedDeliveryDate(): void
    {
        $message = $this->storeMessage(['delivered_at' => '2026-03-02 09:15:00']);
        $this->clock->set('2026-03-16 08:00:00');
        $this->delivery->refresh($this->supplierId, 'test');

        $notice = $this->notices->record($this->supplierId, 'test', [
            'inbox_message_id' => (int) $message['id'],
            'defect_ground' => 'a_not_processable',
            'stated_period_days' => 15,
            'authority_kind' => 'tax_office',
        ], $this->userId);

        self::assertTrue($notice['created']);
        self::assertSame('2026-03-12', $notice['delivered_on']);
        self::assertSame('2026-03-27', $notice['respond_by_on']);
        self::assertSame('open', $notice['status']);
        self::assertSame('ineffective', $notice['consequence']);
    }

    /** Odpověď ve lhůtě zhojí podání podle § 74 odst. 3. */
    public function testAnsweringInTimeCuresTheSubmission(): void
    {
        $notice = $this->recordNotice(['stated_period_days' => 15, 'delivered_on' => '2026-03-02']);

        $answered = $this->notices->recordResponse(
            $this->supplierId,
            (int) $notice['id'],
            (int) $notice['row_version'],
            '2026-03-10',
            null,
            $this->userId,
        );

        self::assertSame('answered_in_time', $answered['status']);
        self::assertSame('cured', $answered['outcome']);
        self::assertFalse($answered['assessment']['needs_attention']);
    }

    /** Zmeškaná lhůta u vady podle písm. a) znamená neúčinné podání. */
    public function testMissedPeriodMakesTheSubmissionIneffective(): void
    {
        $notice = $this->recordNotice(['stated_period_days' => 15, 'delivered_on' => '2026-03-02']);
        $this->clock->set('2026-04-10 08:00:00');

        $listed = $this->notices->list($this->supplierId, 'test', true);
        $item = $listed['items'][0];

        self::assertSame((int) $notice['id'], $item['id']);
        self::assertSame('missed', $item['assessment']['status']);
        self::assertSame('ineffective', $item['assessment']['outcome']);
        self::assertTrue($item['assessment']['needs_attention']);
    }

    /**
     * FAIL-CLOSED: výzva bez lhůty zůstane v „nevíme" a nikdy se neschová mezi
     * vyřízené. Zároveň databáze takový řádek nepustí do stavu, který by
     * o dodržení lhůty něco tvrdil.
     */
    public function testNoticeWithoutADeadlineStaysUnknownAndVisible(): void
    {
        $notice = $this->recordNotice([]);

        self::assertSame('unknown', $notice['status']);
        self::assertNull($notice['respond_by_on']);
        self::assertSame('unknown', $notice['respond_by_source']);
        self::assertTrue($notice['assessment']['needs_attention']);

        $open = $this->notices->list($this->supplierId, 'test', true);
        self::assertCount(1, $open['items'], 'Neznámý stav není vyřízený stav.');
    }

    /** Neznámý řetězec písmene nesmí spadnout na konkrétní následek. */
    public function testUnrecognisedGroundFallsBackToUnknownNeverToAConcreteConsequence(): void
    {
        $notice = $this->recordNotice(['defect_ground' => 'e_something_new', 'stated_period_days' => 15, 'delivered_on' => '2026-03-02']);

        self::assertSame('unknown', $notice['defect_ground']);
        self::assertSame('unknown', $notice['consequence']);
    }

    /** Tatáž zpráva nesmí vyrobit druhou výzvu — jinak by běžely dvě lhůty. */
    public function testSameMessageCannotCreateTwoNotices(): void
    {
        $message = $this->storeMessage(['delivered_at' => '2026-03-02 09:15:00']);
        $first = $this->notices->record($this->supplierId, 'test', [
            'inbox_message_id' => (int) $message['id'],
            'stated_period_days' => 15,
        ], $this->userId);
        $second = $this->notices->record($this->supplierId, 'test', [
            'inbox_message_id' => (int) $message['id'],
            'stated_period_days' => 15,
        ], $this->userId);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
    }

    /** Doložená odpověď se nepřepisuje — je to doklad, ne poznámka. */
    public function testRecordedResponseCannotBeOverwritten(): void
    {
        $notice = $this->recordNotice(['stated_period_days' => 15, 'delivered_on' => '2026-03-02']);
        $answered = $this->notices->recordResponse(
            $this->supplierId,
            (int) $notice['id'],
            (int) $notice['row_version'],
            '2026-03-10',
            null,
            $this->userId,
        );

        $this->expectException(SubmissionChannelException::class);
        $this->notices->recordResponse(
            $this->supplierId,
            (int) $answered['id'],
            (int) $answered['row_version'],
            '2026-03-12',
            null,
            $this->userId,
        );
    }

    /** Souběžná změna skončí konfliktem, ne tichým přepsáním. */
    public function testStaleRowVersionIsRejected(): void
    {
        $notice = $this->recordNotice(['stated_period_days' => 15, 'delivered_on' => '2026-03-02']);

        $this->expectException(SubmissionChannelException::class);
        $this->notices->amend(
            $this->supplierId,
            (int) $notice['id'],
            (int) $notice['row_version'] + 5,
            ['note' => 'cokoliv'],
            $this->userId,
        );
    }

    /** Výzva se nesmí navázat na podání z jiného prostředí. */
    public function testNoticeCannotPointAtAnotherEnvironment(): void
    {
        $row = $this->outboxService->enqueue(
            $this->supplierId,
            'production',
            'isds',
            'DPHDP3',
            'document',
            42,
            $this->recipientId,
            'Testovací podání',
            $this->userId,
        )['row'];

        $this->expectException(SubmissionChannelException::class);
        $this->notices->record($this->supplierId, 'test', [
            'outbox_id' => (int) $row['id'],
            'stated_period_days' => 15,
        ], $this->userId);
    }

    // ───────────────────────── pomocné ─────────────────────────

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function recordNotice(array $overrides): array
    {
        return $this->notices->record(
            $this->supplierId,
            'test',
            $overrides + ['defect_ground' => 'a_not_processable', 'authority_kind' => 'tax_office'],
            $this->userId,
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function storeMessage(array $overrides = []): array
    {
        static $counter = 0;
        $counter++;

        return $this->inbox->record($overrides + [
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'external_message_id' => '99' . str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
            'sender_box_id' => self::AUTHORITY_BOX,
            'sender_name' => 'Testovací finanční úřad',
            'subject' => 'Výzva k odstranění vad podání',
            'sender_ident' => null,
            'classification' => InboxMessageClassifier::TAX_OFFICE_RESPONSE,
            'matched_outbox_id' => null,
            'document_id' => null,
            'delivered_at' => null,
            'accepted_at' => null,
            'raw_sha256' => hash('sha256', 'synteticka-zprava-' . $counter),
        ]);
    }

    private function stubArtifacts(): SubmissionArtifactResolver
    {
        return new class implements SubmissionArtifactResolver {
            /** @return array{filename:string,mime:string,bytes:string}|null */
            public function resolve(int $supplierId, string $artifactKind, int $artifactId): ?array
            {
                // null = artefakt už neexistuje. Stub to musí umět taky, jinak
                // by fronta v testu nikdy nepotkala stav, se kterým počítá.
                if ($artifactId <= 0) {
                    return null;
                }

                return ['filename' => 'podani.xml', 'mime' => 'application/xml', 'bytes' => '<neco/>'];
            }
        };
    }

    private function stubEpoReader(): EpoAttemptStatusReader
    {
        return new class implements EpoAttemptStatusReader {
            /** @return array{status:string,submission_ref:?string,decided_at:?string,error_message:?string}|null */
            public function findAttempt(int $supplierId, string $attemptReference): ?array
            {
                return null;
            }

            /** @return array{filename:string,mime:string,bytes:string}|null */
            public function confirmation(int $supplierId, string $attemptReference): ?array
            {
                return null;
            }
        };
    }
}

/** Posunovatelné hodiny — lhůty se testují posunem času, ne čekáním. */
final class MutableTestClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now) {}

    public function set(string $when): void
    {
        $this->now = new \DateTimeImmutable($when, new \DateTimeZone('Europe/Prague'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
