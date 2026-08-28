<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Přehled mzdových termínů — modul lhůty počítal, ale nikomu je neřekl.
 *
 * Test drží tři věci, na kterých hlídač stojí: že zmeškaný termín je vidět,
 * že se doložená povinnost do seznamu nepřipomíná, a že termín za horizontem
 * seznam nezaplevelí.
 */
#[Group('integration')]
final class PayrollDeadlineOverviewTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDeadlineOverviewService $service;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_employments',
            'payroll_employees',
            'payroll_employment_checklist_items',
            'payroll_obligations',
            'payroll_submission_deadlines',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('Europe/Prague')),
        );
        $this->service = new PayrollDeadlineOverviewService(
            new PayrollDeadlineOverviewRepository($this->db),
            new PayrollDeadlineAssessmentService($clock),
            $clock,
        );

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba termíny", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, "TRM-1", "employment", "active", 1, "2026-08-03")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
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

    /** Endpoint musí jít sestavit kontejnerem, ne jen ručně v testu. */
    public function testContainerBuildsTheAction(): void
    {
        $action = Bootstrap::buildContainer()->get(
            \MyInvoice\Action\Payroll\PayrollDeadlineOverviewAction::class,
        );

        self::assertInstanceOf(
            \MyInvoice\Action\Payroll\PayrollDeadlineOverviewAction::class,
            $action,
        );
    }

    public function testOverdueChecklistItemIsReported(): void
    {
        $this->checklistItem('social_jmhz_registration', '2026-08-03');

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame('2026-08-20', $overview['as_of']);
        $items = $this->itemsOfSource($overview, 'checklist');
        self::assertCount(1, $items);
        self::assertSame('overdue', $items[0]['phase']);
        self::assertSame(-17, $items[0]['days_to_due']);
        self::assertTrue($items[0]['is_overdue']);
        self::assertSame(1, $overview['summary']['overdue']);
    }

    public function testItemWithoutDerivedDeadlineIsNotReported(): void
    {
        $this->checklistItem('taxable_income_confirmation', null);

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    public function testDocumentedRegistrationSilencesTheReminder(): void
    {
        $this->checklistItem('social_jmhz_registration', '2026-08-03');
        $this->obligation(
            'PREZEC26',
            'payroll_employment_registration',
            'payroll_employment:' . $this->employmentId,
            '2026-08-03',
        );

        $overview = $this->service->overview($this->supplierId, 'production');

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    public function testDeadlineBeyondTheHorizonIsNotReported(): void
    {
        $this->checklistItem('social_jmhz_registration', '2027-01-15');

        $overview = $this->service->overview($this->supplierId, 'production', 30);

        self::assertSame([], $this->itemsOfSource($overview, 'checklist'));
    }

    /**
     * @param array{items:list<array<string,mixed>>} $overview
     * @return list<array<string,mixed>>
     */
    private function itemsOfSource(array $overview, string $source): array
    {
        return array_values(array_filter(
            $overview['items'],
            static fn (array $item): bool => $item['source'] === $source,
        ));
    }

    private function checklistItem(string $itemKey, ?string $dueDate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status, due_date)
             VALUES (?, ?, "onboarding", ?, "pending", ?)',
        )->execute([$this->supplierId, $this->employmentId, $itemKey, $dueDate]);
    }

    private function obligation(
        string $agendaCode,
        string $sourceEventType,
        string $sourceEventReference,
        string $periodStart,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, status, source_event_type,
                 source_event_reference, source_event_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, "production", ?, "employment", ?, ?, ?, "regular",
                     "vrep_apep", "open", ?, ?, ?, ?, UNHEX(?))',
        )->execute([
            $this->supplierId,
            $agendaCode,
            'employment:' . $this->employmentId,
            $periodStart,
            $periodStart,
            $sourceEventType,
            $sourceEventReference,
            str_repeat('a', 64),
            str_repeat('b', 64),
            bin2hex(random_bytes(32)),
        ]);
    }
}
