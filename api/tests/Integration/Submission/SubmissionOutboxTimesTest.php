<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časy odchozí fronty jsou v čase aplikace, stejně jako `created_at`.
 *
 * Dřív se události psaly přes `UTC_TIMESTAMP()`: zpráva vypadala odeslaná
 * hodinu před založením a den podání daňového tvrzení se četl o hodinu až dvě
 * dřív, takže odeslání krátce po půlnoci posledního dne lhůty vypadalo včasné.
 */
#[Group('integration')]
final class SubmissionOutboxTimesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionOutboxRepository $outbox;
    private int $supplierId;
    private int $userId;

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
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Chybí uživatel.');
        }
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testSendingIsRecordedInApplicationTimeNotBeforeCreation(): void
    {
        $row = $this->enqueue('DPHDP3-20260815-TIMES1');
        $row = $this->outbox->recordValidation($this->supplierId, $row['id'], 'passed', $row['row_version']);
        $row = $this->outbox->recordRecipientVerified($this->supplierId, (int) $row['id'], (int) $row['row_version']);
        $row = $this->outbox->claimForSending($this->supplierId, (int) $row['id'], $this->userId);
        self::assertNotNull($row);
        $row = $this->outbox->markSent($this->supplierId, (int) $row['id'], 'DM-7001', (int) $row['row_version']);

        $now = (string) $this->db->pdo()->query('SELECT NOW()')->fetchColumn();
        foreach (['artifact_validated_at', 'recipient_box_verified_at', 'confirmed_at', 'sent_at'] as $column) {
            self::assertGreaterThanOrEqual(
                (string) $row['created_at'],
                (string) $row[$column],
                $column . ' je před založením zprávy.',
            );
            self::assertLessThanOrEqual(
                300,
                abs(strtotime($now) - strtotime((string) $row[$column])),
                $column . ' není v čase aplikace.',
            );
        }
    }

    /**
     * Hranice půlnoci: ISDS hlásí okamžik s offsetem (tady v UTC). Do fronty
     * patří jako český čas, jinak by odeslání v 00:00 a doručení v 00:30
     * posledního dne lhůty +1 padlo do předchozího dne.
     */
    public function testChannelTimesAreStoredInApplicationTimeAcrossMidnight(): void
    {
        $row = $this->enqueue('DPHDP3-20260815-TIMES2');
        $row = $this->outbox->claimForManualSending($this->supplierId, $row['id'], $this->userId);
        self::assertNotNull($row);
        $row = $this->outbox->markSentManually(
            $this->supplierId,
            (int) $row['id'],
            'DM-7002',
            new \DateTimeImmutable('2026-08-20T22:00:00Z'),
            (int) $row['row_version'],
        );
        $row = $this->outbox->markDelivered(
            $this->supplierId,
            (int) $row['id'],
            new \DateTimeImmutable('2026-08-20T22:30:00+00:00'),
            (int) $row['row_version'],
        );

        self::assertSame('2026-08-21 00:00:00', $row['sent_at']);
        self::assertSame('2026-08-21 00:30:00', $row['delivered_at']);
    }

    /**
     * Neúspěšný pokus a pak úspěšné odeslání: zpráva nese stav `sent` a nesmí
     * dál nést čas selhání. Historie pokusů je v `submission_outbox_attempts`.
     */
    public function testSuccessfulRetryClearsFailure(): void
    {
        $row = $this->enqueue('DPHDP3-20260815-TIMES3');
        $row = $this->outbox->recordValidation($this->supplierId, $row['id'], 'passed', $row['row_version']);
        $row = $this->outbox->recordRecipientVerified($this->supplierId, (int) $row['id'], (int) $row['row_version']);
        $row = $this->outbox->claimForSending($this->supplierId, (int) $row['id'], $this->userId);
        self::assertNotNull($row);
        $row = $this->outbox->markFailed(
            $this->supplierId,
            (int) $row['id'],
            'isds_login_failed',
            'Přihlášení do schránky selhalo.',
            (int) $row['row_version'],
        );
        self::assertNotNull($row['failed_at']);

        $row = $this->outbox->claimForSending($this->supplierId, (int) $row['id'], $this->userId);
        self::assertNotNull($row);
        $row = $this->outbox->markSent($this->supplierId, (int) $row['id'], 'DM-7003', (int) $row['row_version']);

        self::assertSame('sent', $row['dispatch_state']);
        self::assertNull($row['failed_at']);
        self::assertNull($row['last_error_code']);
        self::assertNull($row['last_error_message']);
    }

    /** @return array{id:int,row_version:int} */
    private function enqueue(string $correlation): array
    {
        $result = $this->outbox->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'DPHDP3',
            'recipient_id' => null,
            'recipient_box_id' => 'abcdefg',
            'subject' => 'Přiznání k DPH',
            'artifact_kind' => 'tax_submission',
            'artifact_id' => 1,
            'artifact_filename' => 'dphdp3.xml',
            'artifact_sha256' => str_repeat('a', 64),
            'correlation_reference' => $correlation,
            'created_by' => null,
        ], 'idem-' . $correlation);

        return ['id' => (int) $result['row']['id'], 'row_version' => (int) $result['row']['row_version']];
    }
}
