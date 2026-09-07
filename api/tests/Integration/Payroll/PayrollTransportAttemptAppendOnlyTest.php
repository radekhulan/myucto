<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Append-only ledger pokusů a adresná výjimka z něj.
 *
 * Append-only nehlídá konvence, ale TRIGGER (migrace 1372) — a to je správně:
 * ledger, který smí zapomenout odeslání, není ledger, ale stavová proměnná.
 * Trvalé smazání jednoho neúspěšného pokusu (migrace 1758) je proto skulina
 * ADRESNÁ: spojení si musí říct o konkrétní řádek.
 *
 * Test měří obě půlky. Kdyby zůstala jen ta povolující, prošel by i plošný
 * `SET @… = 1` u libovolného řádku a z výjimky by byla díra do evidence.
 */
#[Group('integration')]
final class PayrollTransportAttemptAppendOnlyTest extends TestCase
{
    private const TABLE = 'payroll_submission_transport_attempts';

    private PDO $pdo;
    private int $attemptId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI unavailable: ' . $exception->getMessage());
        }
        // Řádek si test ZALOŽÍ. Dřív bral první existující, jenže testovací DB
        // je prázdná, takže se všechny tři testy tiše přeskočily — zelená, která
        // nekontrolovala nic.
        $this->pdo->beginTransaction();
        $this->attemptId = $this->seedAttempt();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('SET @payroll_transport_attempt_delete_allowed = NULL');
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }

    public function testDeleteWithoutPermissionIsRefused(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')
            ->execute([$this->attemptId]);
    }

    /** Povolení na CIZÍ řádek nesmí odemknout tenhle — jinak je skulina plošná. */
    public function testPermissionForAnotherRowDoesNotUnlockThisOne(): void
    {
        $this->pdo->prepare('SET @payroll_transport_attempt_delete_allowed = ?')
            ->execute([$this->attemptId + 10_000]);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?')
            ->execute([$this->attemptId]);
    }

    public function testDeleteWithPermissionForThatRowPasses(): void
    {
        $this->pdo->prepare('SET @payroll_transport_attempt_delete_allowed = ?')
            ->execute([$this->attemptId]);

        $statement = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ?');
        $statement->execute([$this->attemptId]);

        self::assertSame(1, $statement->rowCount());
    }

    /**
     * Nejmenší povinnost + podání + pokus, na kterých jde trigger změřit.
     *
     * Vše uvnitř transakce, kterou tearDown vrací zpět — ledger je ostrá
     * evidence, ne testovací hřiště.
     */
    private function seedAttempt(): int
    {
        $supplierId = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn();
        $hash = str_repeat('a', 64);
        $unique = bin2hex(random_bytes(8));

        $this->pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference,
                 period_start, period_end, obligation_kind, preferred_channel, status,
                 source_event_type, source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash)
             VALUES (?, "test", "cssz_jmhz", "employer", ?, "2026-08-01", "2026-08-31",
                     "regular", "vrep_apep", "submitted", "test", ?, ?, ?, UNHEX(SHA2(?, 256)))',
        )->execute([$supplierId, $unique, $unique, $hash, $hash, $unique]);
        $obligationId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_snapshot_hash, request_fingerprint, idempotency_key_hash, submitted_at)
             VALUES (?, "test", ?, "regular", "vrep_apep", "submitted", ?, ?, UNHEX(SHA2(?, 256)), NOW())',
        )->execute([$supplierId, $obligationId, $hash, $hash, $unique]);
        $submissionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
                (supplier_id, environment, submission_id, channel, attempt_no, status,
                 idempotency_key_hash, request_sha256, sent_at)
             VALUES (?, "test", ?, "vrep_apep", 1, "sent", UNHEX(SHA2(?, 256)), ?, NOW())',
        )->execute([$supplierId, $submissionId, $unique, $hash]);

        return (int) $this->pdo->lastInsertId();
    }
}
