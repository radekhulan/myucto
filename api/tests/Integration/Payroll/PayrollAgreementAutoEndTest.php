<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollAgreementAutoEndRunner;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Noční ukončení uplynulých dohod (`cron-payroll-end-agreements`): ukončí DPP
 * a DPČ po sjednaném konci stejnou cestou jako ruční hromadná akce, nechá být
 * trvající a budoucí dohody, pracovní poměr i dohodu s prací po konci,
 * a opakovaný běh nic nezmění.
 */
#[Group('integration')]
final class PayrollAgreementAutoEndTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TODAY = '2026-11-11';

    private Connection $db;
    private PayrollEmploymentBulkClosure $closure;
    private PayrollAgreementAutoEndRunner $runner;
    private int $supplierId;
    private int $foreignSupplierId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Chybí tabulka podmínek pracovního vztahu.');
        }
        $this->closure = $container->get(PayrollEmploymentBulkClosure::class);
        $this->runner = $container->get(PayrollAgreementAutoEndRunner::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->foreignSupplierId]);
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

    public function testEndsPastAgreementsAndLeavesTheRestAlone(): void
    {
        $january = $this->employment($this->supplierId, 'Lednová Dohoda', 'dpp', '2026-01-05', '2026-01-31');
        $suspended = $this->employment($this->supplierId, 'Přerušená DPČ', 'dpc', '2026-02-01', '2026-09-30', 'suspended');
        $open = $this->employment($this->supplierId, 'Trvající Dohoda', 'dpp', '2026-01-01', null);
        $future = $this->employment($this->supplierId, 'Budoucí Konec', 'dpp', '2026-03-01', '2026-12-31');
        $endsToday = $this->employment($this->supplierId, 'Končí Dnes', 'dpc', '2026-03-01', self::TODAY);
        $fixedTerm = $this->employment($this->supplierId, 'Poměr Na Dobu Určitou', 'employment', '2025-01-01', '2026-06-30');
        $continued = $this->employment($this->supplierId, 'Pracuje Dál', 'dpp', '2026-01-01', '2026-05-31');
        $this->input($continued, '2026-07-01', 90_000);

        $result = $this->closure->endExpiredAgreements($this->supplierId, self::TODAY);

        self::assertEqualsCanonicalizing([$january, $suspended], $result['ended']);
        self::assertSame([$continued], $result['blocked']);
        self::assertSame([], $result['failed']);

        self::assertSame(['status' => 'ended', 'end_date' => '2026-01-31'], $this->state($january));
        self::assertSame(['status' => 'ended', 'end_date' => '2026-09-30'], $this->state($suspended));
        self::assertSame(['status' => 'active', 'end_date' => null], $this->state($open));
        self::assertSame(['status' => 'active', 'end_date' => '2026-12-31'], $this->state($future));
        self::assertSame(['status' => 'active', 'end_date' => self::TODAY], $this->state($endsToday));
        self::assertSame(['status' => 'active', 'end_date' => '2026-06-30'], $this->state($fixedTerm));
        self::assertSame(['status' => 'active', 'end_date' => '2026-05-31'], $this->state($continued));

        // Stejná cesta jako ruční ukončení: událost bez uživatele, kontrolní seznam odchodu.
        $event = $this->db->pdo()->prepare(
            "SELECT created_by, effective_on, note FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'status_changed' AND to_status = 'ended'",
        );
        $event->execute([$this->supplierId, $january]);
        $rows = $event->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['created_by']);
        self::assertSame('2026-01-31', $rows[0]['effective_on']);
        self::assertStringContainsString('Automatické ukončení', (string) $rows[0]['note']);
        $checklist = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND phase = 'offboarding'",
        );
        $checklist->execute([$this->supplierId, $january]);
        self::assertGreaterThan(0, (int) $checklist->fetchColumn());

        $audit = $this->db->pdo()->prepare(
            "SELECT user_id, user_agent, payload FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll.employment.auto_closure'",
        );
        $audit->execute([$this->supplierId]);
        $log = $audit->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $log);
        self::assertNull($log[0]['user_id']);
        self::assertSame('system', $log[0]['user_agent']);
        self::assertStringContainsString('"actor":"system"', (string) $log[0]['payload']);
    }

    public function testSecondRunChangesNothing(): void
    {
        $january = $this->employment($this->supplierId, 'Lednová Dohoda', 'dpp', '2026-01-05', '2026-01-31');

        $first = $this->closure->endExpiredAgreements($this->supplierId, self::TODAY);
        $second = $this->closure->endExpiredAgreements($this->supplierId, self::TODAY);

        self::assertSame([$january], $first['ended']);
        self::assertSame(['ended' => [], 'blocked' => [], 'failed' => []], $second);
        self::assertSame(['status' => 'ended', 'end_date' => '2026-01-31'], $this->state($january));
        $events = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'status_changed'",
        );
        $events->execute([$this->supplierId, $january]);
        self::assertSame(1, (int) $events->fetchColumn());
    }

    public function testRunnerWorksPerCompany(): void
    {
        $own = $this->employment($this->supplierId, 'Vlastní Dohoda', 'dpp', '2026-01-05', '2026-01-31');
        $foreign = $this->employment($this->foreignSupplierId, 'Cizí Dohoda', 'dpp', '2026-01-05', '2026-01-31');

        $report = $this->runner->run([$this->supplierId], self::TODAY);

        self::assertSame(1, $report['suppliers']);
        self::assertSame(1, $report['ended']);
        self::assertSame(0, $report['errors']);
        self::assertSame('ended', $this->state($own)['status']);
        self::assertSame('active', $this->state($foreign, $this->foreignSupplierId)['status']);

        $again = $this->runner->run([$this->supplierId, $this->foreignSupplierId], self::TODAY);
        self::assertSame(2, $again['suppliers']);
        self::assertSame(1, $again['ended']);
        self::assertSame('ended', $this->state($foreign, $this->foreignSupplierId)['status']);
    }

    private function employment(
        int $supplierId,
        string $name,
        string $relationType,
        string $startOn,
        ?string $endOn,
        string $status = 'active',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, 'employee', 1)",
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)',
        )->execute([$supplierId, $employeeId, 'AUTO-' . $employeeId, $relationType, $status, $startOn, $startOn, $endOn]);

        return (int) $pdo->lastInsertId();
    }

    private function input(int $employmentId, string $periodStart, int $amountMinor): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, "ODMENA_DPP", "Odměna z dohody", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "521", "331", "2026-01-01")',
        )->execute([$this->supplierId]);
        $componentId = (int) $pdo->lastInsertId();
        $employeeId = (int) $pdo
            ->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $employmentId)
            ->fetchColumn();
        $json = CanonicalJson::encode(['code' => 'ODMENA_DPP', 'component_id' => $componentId]);
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, "manual", "draft", ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $employmentId,
            $componentId,
            $periodStart,
            $amountMinor,
            $json,
            hash('sha256', $json, true),
        ]);
    }

    /** @return array{status:string,end_date:?string} */
    private function state(int $employmentId, ?int $supplierId = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId ?? $this->supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return ['status' => (string) $row['status'], 'end_date' => $row['end_date'] === null ? null : (string) $row['end_date']];
    }
}
