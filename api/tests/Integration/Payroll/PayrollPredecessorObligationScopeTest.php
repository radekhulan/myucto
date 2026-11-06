<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Povinnosti checklistu z doby před začátkem vedení mezd v MyÚčtu vyřídil
 * předchozí program. Převzetí mezd jich dřív založilo přes sto a checklist,
 * hlídač termínů i mzdový běh je hlásily jako nesplněné.
 */
#[Group('integration')]
final class PayrollPredecessorObligationScopeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ONBOARDING = [
        'employment_contract',
        'health_insurance_registration',
        'social_jmhz_registration',
        'tax_declaration',
    ];

    private Connection $db;
    private PayrollEmploymentRepository $employments;
    private PayrollDeadlineOverviewRepository $deadlines;
    private PayrollRunSnapshotBuilder $snapshots;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $employments = $container->get(PayrollEmploymentRepository::class);
        $deadlines = $container->get(PayrollDeadlineOverviewRepository::class);
        $snapshots = $container->get(PayrollRunSnapshotBuilder::class);
        if (!$db instanceof Connection
            || !$employments instanceof PayrollEmploymentRepository
            || !$deadlines instanceof PayrollDeadlineOverviewRepository
            || !$snapshots instanceof PayrollRunSnapshotBuilder
        ) {
            throw new \RuntimeException('Služby checklistu nejsou dostupné.');
        }
        $this->db = $db;
        $this->employments = $employments;
        $this->deadlines = $deadlines;
        $this->snapshots = $snapshots;
        foreach (['payroll_employment_checklist_items', 'payroll_module_state'] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period)
             VALUES (?, 'setup', '2026-09-01')
             ON DUPLICATE KEY UPDATE status = 'setup', start_period = '2026-09-01'"
        )->execute([$this->supplierId]);
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

    public function testImportedChecklistBeforeStartReadsAsHandledByPredecessor(): void
    {
        [$takenEmployee, $taken] = $this->employment('Syntetická převzatá osoba', '2026-03-01', null, 'active');
        [$newEmployee, $new] = $this->employment('Syntetická nová osoba', '2026-09-14', null, 'active');
        $this->items($taken, 'onboarding', self::ONBOARDING, '2026-03-09');
        $this->items($new, 'onboarding', self::ONBOARDING, '2026-09-22');

        foreach ($this->checklist($takenEmployee) as $item) {
            self::assertSame('completed', $item['effective_status'], $item['item_key']);
            self::assertSame(PayrollPredecessorObligationScope::REASON, $item['effective_reason']);
            self::assertSame('pending', $item['status'], 'Uložený stav se nepřepisuje.');
        }
        foreach ($this->checklist($newEmployee) as $item) {
            self::assertSame('pending', $item['effective_status'], $item['item_key']);
            self::assertNull($item['effective_reason']);
        }

        $dated = $this->deadlines->checklistDeadlines($this->supplierId, '2025-01-01', '2027-12-31');
        $undated = $this->deadlines->checklistWithoutDeadline($this->supplierId);
        $employmentIds = array_unique(array_map(
            static fn (array $row): int => (int) $row['employment_id'],
            [...$dated, ...$undated],
        ));
        self::assertSame([$new], array_values($employmentIds), 'Hlídač termínů hlásí jen povinnosti MyÚčta.');
        self::assertSame([], $this->deadlines->checklistWithoutDeadlineCounts($this->supplierId));
    }

    public function testRunDoesNotWarnAboutRegistrationHandledByPredecessor(): void
    {
        [, $taken] = $this->employment('Syntetická převzatá osoba', '2026-03-01', null, 'active');
        [, $new] = $this->employment('Syntetická nová osoba', '2026-09-14', null, 'active');
        $this->items($taken, 'onboarding', ['social_jmhz_registration', 'health_insurance_registration'], null);
        $this->items($new, 'onboarding', ['social_jmhz_registration', 'health_insurance_registration'], null);

        $gaps = (new \ReflectionMethod($this->snapshots, 'personRegistrationGaps'))
            ->invoke($this->snapshots, $this->supplierId, [$taken, $new]);

        self::assertSame(['social' => false, 'health' => false], $gaps[$taken]);
        self::assertSame(['social' => true, 'health' => true], $gaps[$new]);
    }

    public function testEndingBeforeStartCreatesNoOffboardingChecklist(): void
    {
        [, $old] = $this->employment('Syntetická odešlá osoba', '2026-01-01', null, 'active');
        [, $current] = $this->employment('Syntetická odcházející osoba', '2026-01-01', null, 'active');

        $this->employments->transition($this->supplierId, $old, 'ended', $this->version($old), '2026-05-31', null, $this->userId, null, null);
        $this->employments->transition($this->supplierId, $current, 'ended', $this->version($current), '2026-09-30', null, $this->userId, null, null);

        self::assertSame(0, $this->itemCount($old), 'Skončení před začátkem vedení mezd vyřídil předchozí program.');
        self::assertGreaterThan(0, $this->itemCount($current));
    }

    /**
     * SQL a PHP podoba pravidla se nesmí rozejít — checklist, hlídač i běh čtou
     * SQL, zakládání položek PHP.
     */
    public function testSqlMatchesPhpRule(): void
    {
        [, $a] = $this->employment('Syntetická osoba A', '2026-03-01', '2026-05-31', 'ended');
        [, $b] = $this->employment('Syntetická osoba B', '2026-09-14', null, 'active');
        [, $c] = $this->employment('Syntetická osoba C', null, null, 'active');
        foreach ([$a, $b, $c] as $employmentId) {
            $this->items($employmentId, 'onboarding', [...self::ONBOARDING, 'legacy_start_date'], null);
            $this->items($employmentId, 'offboarding', ['termination_document', 'taxable_income_confirmation'], null);
            $this->items($employmentId, 'change', ['health_insurance_change'], '2026-08-20');
            $this->items($employmentId, 'change', ['contract_amendment'], null);
        }
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_checklist_items SET due_date = '2026-10-05'
              WHERE supplier_id = ? AND employment_id = ? AND item_key = 'taxable_income_confirmation'"
        )->execute([$this->supplierId, $a]);

        $stmt = $this->db->pdo()->prepare(
            'SELECT item.item_key, item.phase, item.due_date,
                    COALESCE(employment.actual_start_date, employment.start_date) AS start_on,
                    employment.end_date,
                    ' . PayrollPredecessorObligationScope::sql('item') . ' AS handled
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id AND employment.id = item.employment_id
              WHERE item.supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(27, $rows);
        $handled = 0;
        foreach ($rows as $row) {
            $php = PayrollPredecessorObligationScope::handledByPredecessor(
                '2026-09',
                (string) $row['item_key'],
                (string) $row['phase'],
                $row['due_date'],
                $row['start_on'],
                $row['end_date'],
            );
            self::assertSame($php, (bool) $row['handled'], $row['item_key'] . '/' . $row['phase'] . '/' . ($row['start_on'] ?? '-'));
            $handled += (int) $php;
        }
        self::assertGreaterThan(0, $handled);
    }

    /** @return array{0:int,1:int} */
    private function employment(string $name, ?string $start, ?string $end, string $status): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date, end_date)
             VALUES (?, ?, ?, "employment", ?, 0, ?, ?, ?)'
        )->execute([$this->supplierId, $employeeId, 'P' . $employeeId, $status, $start, $start, $end]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    /** @param list<string> $keys */
    private function items(int $employmentId, string $phase, array $keys, ?string $dueOn): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status, due_date)
             VALUES (?, ?, ?, ?, "pending", ?)'
        );
        foreach ($keys as $key) {
            $insert->execute([$this->supplierId, $employmentId, $phase, $key, $dueOn]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function checklist(int $employeeId): array
    {
        $items = [];
        foreach ($this->employments->listForEmployee($this->supplierId, $employeeId) as $employment) {
            array_push($items, ...$employment['checklist']);
        }
        self::assertNotSame([], $items);

        return $items;
    }

    private function version(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }

    private function itemCount(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employment_checklist_items WHERE supplier_id = ? AND employment_id = ?'
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }
}
