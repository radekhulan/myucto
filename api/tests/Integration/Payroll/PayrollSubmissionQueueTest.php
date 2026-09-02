<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionQueueService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fronta odchozích podání nad skutečným schématem.
 *
 * Co se tu ověřuje především: že se do fronty připravené podání dostane SAMO
 * a že se z ní nic mlčky neztratí. Právě mizející položky jsou důvod, proč
 * fronta vznikla — účetní opakovaně nevěděla, jestli podání odešlo, nebo na
 * ně zapomněla.
 */
#[Group('integration')]
final class PayrollSubmissionQueueTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PayrollSubmissionQueueService $queue;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->queue = $container->get(PayrollSubmissionQueueService::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $this->pdo = $this->db->pdo();
        if (!$this->db->hasTable('payroll_submissions')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }
        $this->supplierId = (int) ($this->pdo
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo
            ->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí firma nebo uživatel.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Zmrazená registrace se ve frontě objeví BEZ jakéhokoli přepnutí a je
     * odeslatelná. Tohle je jádro zadání: účetní nemusí za každým zaměstnancem
     * na jeho kartu.
     */
    public function testFrozenRegistrationIsListedAndDispatchable(): void
    {
        $submissionId = $this->seed(
            PayrollDispatchCapabilityCatalog::canonical('PREZEC26'),
            'ready',
            dueOn: '2026-08-20',
        );

        $row = $this->row($submissionId);
        self::assertNotNull($row, 'Zmrazené podání ve frontě chybí.');
        self::assertTrue($row['dispatch']['dispatchable']);
        self::assertNull($row['dispatch']['blocked_reason']);
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION,
            $row['dispatch']['mode'],
        );
    }

    /**
     * Nezmrazené podání se z fronty NEZTRATÍ — ukáže se s důvodem. Kdyby
     * zmizelo, uživatel by nevěděl, že o něm aplikace ví.
     */
    public function testPreparedSubmissionIsListedWithReason(): void
    {
        $submissionId = $this->seed('ELDP', 'prepared', dueOn: '2026-08-20');

        $row = $this->row($submissionId);
        self::assertNotNull($row);
        self::assertFalse($row['dispatch']['dispatchable']);
        self::assertNotNull($row['dispatch']['blocked_reason']);
    }

    /**
     * Agenda bez odesílacího kanálu se ukáže taky — a řekne proč. Tvrdit
     * u ELDP „odešleme" by znamenalo tlačítko, které vždycky selže.
     */
    public function testUndispatchableAgendaExplainsItself(): void
    {
        $submissionId = $this->seed('ELDP', 'ready', dueOn: '2026-08-20');

        $row = $this->row($submissionId);
        self::assertNotNull($row);
        self::assertFalse($row['dispatch']['dispatchable']);
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_NONE,
            $row['dispatch']['mode'],
        );
        self::assertStringContainsString(
            'Evidenční list',
            (string) $row['dispatch']['blocked_reason'],
        );
    }

    /** Odeslané podání ve frontě nemá co dělat — už není „k odeslání". */
    public function testSubmittedSubmissionLeavesTheQueue(): void
    {
        $submissionId = $this->seed('JMHZ25', 'ready', dueOn: '2026-08-20');
        self::assertNotNull($this->row($submissionId));

        $this->pdo->prepare(
            'UPDATE payroll_submissions
                SET status = ?, submitted_at = ?
              WHERE id = ?',
        )->execute(['submitted', '2026-08-15 10:00:00', $submissionId]);

        self::assertNull($this->row($submissionId));
    }

    /** Řadí se podle lhůty: co hoří, je nahoře. */
    public function testQueueIsOrderedByDeadline(): void
    {
        $later = $this->seed('JMHZ25', 'ready', dueOn: '2026-11-20');
        $sooner = $this->seed('JMHZ25', 'ready', dueOn: '2026-08-20');

        $ids = array_column(
            $this->queue->queue($this->supplierId, 'test', 200, 0)['items'],
            'submission_id',
        );
        $positionSooner = array_search($sooner, $ids, true);
        $positionLater = array_search($later, $ids, true);

        self::assertIsInt($positionSooner);
        self::assertIsInt($positionLater);
        self::assertLessThan(
            $positionLater,
            $positionSooner,
            'Podání s dřívější lhůtou musí být ve frontě výš.',
        );
    }

    /** Fronta jedné firmy nesmí ukázat podání jiné firmy ani jiného prostředí. */
    public function testQueueIsScopedToTenantAndEnvironment(): void
    {
        $submissionId = $this->seed('JMHZ25', 'ready', dueOn: '2026-08-20');

        self::assertNotNull($this->row($submissionId));
        // Totéž podání v produkčním pohledu vidět není.
        self::assertNull($this->row($submissionId, 'production'));
    }

    /**
     * Odeslání se nespustí u řádku, který fronta označila za neodeslatelný —
     * ani přímým voláním se zastaralým ID.
     */
    public function testDispatchRefusesBlockedRow(): void
    {
        $submissionId = $this->seed('ELDP', 'ready', dueOn: '2026-08-20');

        $this->expectException(\DomainException::class);
        $this->queue->dispatch(
            $this->supplierId,
            'test',
            $submissionId,
            'test-' . bin2hex(random_bytes(6)),
            $this->userId,
        );
    }

    /**
     * JEDNA CHYBA NESMÍ SHODIT DÁVKU. Při stovce zaměstnanců je vždycky někdo,
     * komu chybí údaj — a kvůli němu se nesmí zdržet ostatní.
     */
    public function testBatchKeepsGoingAfterAFailedItem(): void
    {
        $blocked = $this->seed('ELDP', 'ready', dueOn: '2026-08-20');
        $alsoBlocked = $this->seed('OZUSPOJ', 'ready', dueOn: '2026-08-21');
        $missing = 999_999_999;

        $result = $this->queue->dispatchMany(
            $this->supplierId,
            'test',
            [
                ['submission_id' => $blocked, 'idempotency_key' => $this->key()],
                ['submission_id' => $missing, 'idempotency_key' => $this->key()],
                ['submission_id' => $alsoBlocked, 'idempotency_key' => $this->key()],
            ],
            $this->userId,
        );

        self::assertSame(3, $result['summary']['requested']);
        self::assertSame(3, $result['summary']['failed']);
        self::assertCount(3, $result['results'], 'Dávka se zastavila na první chybě.');
        foreach ($result['results'] as $item) {
            self::assertFalse($item['ok']);
            // Věta, ne kód: účetní ji čte v souhrnu dávky.
            self::assertNotSame('', trim((string) $item['message']));
            self::assertStringEndsWith('.', (string) $item['message']);
        }
        // Každá položka má SVŮJ důvod, ne jeden společný.
        $messages = array_unique(array_column($result['results'], 'message'));
        self::assertGreaterThan(1, count($messages));
    }

    /** Táž položka dvakrát v jedné dávce se nesmí odeslat dvakrát. */
    public function testBatchCollapsesDuplicateItems(): void
    {
        $submissionId = $this->seed('ELDP', 'ready', dueOn: '2026-08-20');

        $result = $this->queue->dispatchMany(
            $this->supplierId,
            'test',
            [
                ['submission_id' => $submissionId, 'idempotency_key' => $this->key()],
                ['submission_id' => $submissionId, 'idempotency_key' => $this->key()],
            ],
            $this->userId,
        );

        self::assertSame(1, $result['summary']['requested']);
        self::assertCount(1, $result['results']);
    }

    /**
     * Porce má tvrdý strop. Tiché oříznutí by znamenalo, že se část dávky
     * neodešle a nikdo se to nedozví.
     */
    public function testBatchRejectsOversizedChunk(): void
    {
        $items = array_fill(0, PayrollSubmissionQueueService::MAX_BATCH_SIZE + 1, [
            'submission_id' => 1,
            'idempotency_key' => $this->key(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->queue->dispatchMany($this->supplierId, 'test', $items, $this->userId);
    }

    /** Filtr podle agendy i řazení podle agendy musí platit nad CELOU frontou. */
    public function testAgendaFilterAndSort(): void
    {
        $eldp = $this->seed('ELDP', 'ready', dueOn: '2026-08-25');
        $jmhz = $this->seed('JMHZ25', 'ready', dueOn: '2026-08-26');

        $filtered = $this->queue->queue(
            $this->supplierId,
            'test',
            200,
            0,
            'ELDP',
        );
        $ids = array_column($filtered['items'], 'submission_id');
        self::assertContains($eldp, $ids);
        self::assertNotContains($jmhz, $ids);

        // Číselník filtru se počítá nad celou frontou, ne nad stránkou.
        $codes = array_column(
            $this->queue->queue($this->supplierId, 'test', 200, 0)['agendas'],
            'agenda_code',
        );
        self::assertContains('ELDP', $codes);
        self::assertContains('JMHZ25', $codes);

        $byAgenda = $this->queue->queue(
            $this->supplierId,
            'test',
            200,
            0,
            null,
            'agenda',
        );
        $sortedCodes = array_column($byAgenda['items'], 'agenda_code');
        $expected = $sortedCodes;
        sort($expected);
        self::assertSame($expected, $sortedCodes);
    }

    private function key(): string
    {
        return 'queue-test-' . bin2hex(random_bytes(8));
    }

    /** @return array<string,mixed>|null */
    private function row(int $submissionId, string $environment = 'test'): ?array
    {
        $result = $this->queue->queue($this->supplierId, $environment, 200, 0);
        foreach ($result['items'] as $item) {
            if ((int) $item['submission_id'] === $submissionId) {
                return $item;
            }
        }

        return null;
    }

    /** Povinnost + lhůta + podání v testovacím prostředí. */
    private function seed(
        string $agendaCode,
        string $status,
        string $dueOn,
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $this->pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash, row_version, created_by)
             VALUES (?, "test", ?, "employer", ?, "2026-07-01", "2026-07-31",
                     "regular", "vrep_apep", "open", "payroll_run_approved",
                     ?, ?, ?, ?, 1, ?)',
        )->execute([
            $this->supplierId,
            $agendaCode,
            'queue-test-' . $suffix,
            'queue-event-' . $suffix,
            str_repeat('c', 64),
            str_repeat('a', 64),
            random_bytes(32),
            $this->userId,
        ]);
        $obligationId = (int) $this->pdo->lastInsertId();

        // Lhůta je povinná: fronta bez ní řádek nemá podle čeho seřadit,
        // takže ji spojuje INNER JOINem.
        $this->pdo->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis, ruleset_id,
                 ruleset_hash, trigger_event_hash, created_by)
             VALUES (?, "test", ?, "regular", "2026-08-01", ?, "calendar_days",
                     "queue-test.v1", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $dueOn,
            str_repeat('e', 64),
            str_repeat('f', 64),
            $this->userId,
        ]);

        $this->pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash, row_version, created_by)
             VALUES (?, "test", ?, "regular", "vrep_apep", ?, ?, ?, ?, 1, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $status,
            str_repeat('d', 64),
            str_repeat('b', 64),
            random_bytes(32),
            $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
