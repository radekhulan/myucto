<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionInboxRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionInboxService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * MZ-19-W09 — chování odvozeného inboxu a alertingu mzdových podání.
 *
 * Vydáno JAKO ČERVENÝ TEST před migrací 1309, repository, service a
 * action: v okamžiku napsání tento soubor nešel ani zkompilovat, protože
 * `PayrollSubmissionInboxRepository` a `PayrollSubmissionInboxService`
 * ještě neexistovaly a tabulka `payroll_submission_inbox_items` nebyla
 * v databázi. Teprve po jejich doplnění test zezelená.
 */
#[Group('integration')]
final class PayrollSubmissionInboxServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollSubmissionInboxRepository $inboxRepository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_obligations')) {
            $this->markTestSkipped('Migrace 1279 neproběhla.');
        }
        if (!$this->db->hasTable('payroll_submission_inbox_items')) {
            $this->markTestSkipped('Migrace 1309 neproběhla.');
        }
        $this->obligations = $container->get(PayrollObligationService::class);
        $this->submissions = $container->get(PayrollSubmissionService::class);
        $this->inboxRepository = $container->get(
            PayrollSubmissionInboxRepository::class,
        );

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    private function service(MockClock $clock): PayrollSubmissionInboxService
    {
        return new PayrollSubmissionInboxService(
            $this->inboxRepository,
            new PayrollDeadlineAssessmentService($clock),
            $clock,
        );
    }

    /**
     * @return array{id:int,due_on:string}
     */
    private function registerObligation(
        int $supplierId,
        string $dueOn,
        string $idempotencyKey,
        string $earliest = '2026-08-01',
    ): array {
        $obligation = $this->obligations->register(
            $supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-08-01',
            '2026-08-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:' . $idempotencyKey,
            hash('sha256', 'event:' . $idempotencyKey),
            $earliest,
            $dueOn,
            'calendar_days',
            'inbox-test-ruleset',
            hash('sha256', 'ruleset:' . $idempotencyKey),
            $idempotencyKey,
            createdBy: $this->userId,
        );

        return ['id' => $obligation['id'], 'due_on' => $dueOn];
    }

    public function testEscalationIsMonotonicAndNeverRegresses(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $obligation = $this->registerObligation(
            $this->supplierId,
            '2026-08-13',
            'inbox-escalation',
        );

        // T+0: 3 dny do lhůty -> due_soon.
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('due_soon', $item['problem_kind']);
        self::assertSame('due_soon', $item['escalation_level']);
        $itemId = $item['id'];

        // T+3: den lhůty -> due_today.
        $clock->modify('+3 days');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame($itemId, $item['id'], 'Idempotence: stejný zdroj = stejná položka.');
        self::assertSame('due_today', $item['problem_kind']);
        self::assertSame('due_today', $item['escalation_level']);

        // T+5: dva dny po lhůtě -> overdue.
        $clock->modify('+2 days');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('overdue', $item['problem_kind']);
        self::assertSame('overdue', $item['escalation_level']);

        // Hodiny se vrátí zpět (chyba/souběh) -> aktuální fáze by byla
        // znovu due_soon, ale eskalace se nesmí nikdy snížit.
        $clock->modify('-5 days');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame(
            'overdue',
            $item['escalation_level'],
            'Eskalace nesmí regredovat, i když aktuální fáze vypadá mírněji.',
        );
    }

    public function testSameSourceKeyIsIdempotentAndDoesNotDuplicate(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $obligation = $this->registerObligation(
            $this->supplierId,
            '2026-08-12',
            'inbox-idempotent',
        );

        $service->sync($this->supplierId);
        $service->sync($this->supplierId);
        $service->sync($this->supplierId);

        $items = array_values(array_filter(
            $service->list($this->supplierId),
            static fn (array $item): bool => $item['obligation_id'] === $obligation['id'],
        ));
        self::assertCount(
            1,
            $items,
            'Opakovaná derivace ze stejného zdroje nesmí vytvořit duplicitní položku.',
        );
    }

    public function testSnoozeHasEndAndReturnsToOpenAfterExpiry(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $obligation = $this->registerObligation(
            $this->supplierId,
            '2026-08-11',
            'inbox-snooze',
        );

        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);

        $snoozedUntil = \DateTimeImmutable::createFromInterface($clock->now())
            ->modify('+3 hours')
            ->format(DATE_ATOM);
        $snoozed = $service->snooze(
            $this->supplierId,
            $item['id'],
            $item['row_version'],
            $snoozedUntil,
            'Čekáme na doklad od klienta.',
            $this->userId,
        );
        self::assertSame('snoozed', $snoozed['status']);

        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('snoozed', $item['status']);

        // Před koncem odložení zůstává snoozed.
        $clock->modify('+2 hours');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('snoozed', $item['status']);

        // Po konci odložení se položka automaticky vrací do open.
        $clock->modify('+1 hour');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('open', $item['status']);
        self::assertNull($item['snoozed_until']);
    }

    public function testResolvedItemNeverEscalatesAgain(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $obligation = $this->registerObligation(
            $this->supplierId,
            '2026-08-08',
            'inbox-resolved',
        );

        // Lhůta je v minulosti -> overdue.
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('overdue', $item['escalation_level']);

        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'inbox-resolved-submission',
            createdBy: $this->userId,
        );
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'] + 1,
            'prepared',
        );
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'] + 2,
            'ready',
        );
        $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'] + 3,
            'cancelled_in_time',
        );

        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame(
            'resolved',
            $item['status'],
            'Zrušená povinnost už nemá otevřený problém k řešení.',
        );
        $resolvedEscalation = $item['escalation_level'];

        // Další derivace (i s posunutými hodinami) resolved položku
        // nesmí znovu otevřít ani eskalovat.
        $clock->modify('+30 days');
        $items = $service->list($this->supplierId);
        $item = $this->findByObligation($items, $obligation['id']);
        self::assertNotNull($item);
        self::assertSame('resolved', $item['status']);
        self::assertSame($resolvedEscalation, $item['escalation_level']);
    }

    public function testTenantIsolation(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $this->registerObligation(
            $this->supplierId,
            '2026-08-11',
            'inbox-tenant-a',
        );

        $ownItems = $service->list($this->supplierId);
        $otherItems = $service->list($this->otherSupplierId);

        self::assertNotEmpty($ownItems);
        self::assertSame(
            [],
            $otherItems,
            'Inbox jiné firmy nesmí vidět cizí položky.',
        );
    }

    public function testSyncNeverMutatesObligationOrSubmissionState(): void
    {
        $clock = new MockClock('2026-08-10 09:00:00 Europe/Prague');
        $service = $this->service($clock);
        $obligation = $this->registerObligation(
            $this->supplierId,
            '2026-08-12',
            'inbox-no-mutation',
        );

        $submissionRepository = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(PayrollSubmissionRepository::class);
        self::assertInstanceOf(
            PayrollSubmissionRepository::class,
            $submissionRepository,
        );

        $before = $submissionRepository->lockObligation(
            $this->supplierId,
            $obligation['id'],
            'production',
        );
        self::assertNotNull($before);

        $service->sync($this->supplierId);
        $service->sync($this->supplierId);
        $service->acknowledge(
            $this->supplierId,
            $this->findByObligation(
                $service->list($this->supplierId),
                $obligation['id'],
            )['id'],
            $this->findByObligation(
                $service->list($this->supplierId),
                $obligation['id'],
            )['row_version'],
            $this->userId,
        );

        $after = $submissionRepository->lockObligation(
            $this->supplierId,
            $obligation['id'],
            'production',
        );
        self::assertNotNull($after);
        self::assertSame(
            $before['status'],
            $after['status'],
            'Inbox nesmí měnit stav povinnosti.',
        );
        self::assertSame(
            $before['row_version'],
            $after['row_version'],
            'Inbox nesmí zapisovat do povinnosti.',
        );
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function findByObligation(array $items, int $obligationId): ?array
    {
        foreach ($items as $item) {
            if ($item['obligation_id'] === $obligationId) {
                return $item;
            }
        }

        return null;
    }
}
