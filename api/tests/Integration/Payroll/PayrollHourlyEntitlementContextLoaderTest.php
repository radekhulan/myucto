<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageCalculator;
use MyInvoice\Service\Payroll\Hourly\PayrollHourlyWageContext;
use MyInvoice\Service\Payroll\PayrollHourlyEntitlementContextLoader;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBatchLoader;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHourlyEntitlementContextLoaderTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PayrollTimeRepository $time;
    private int $supplier;
    private int $user;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->time = $container->get(PayrollTimeRepository::class);
        $pdo = $this->db->pdo();
        $original = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $original);
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, $original);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        $this->db->close();
    }

    public function testZeroManualMonthIsCompleteWithoutAverageOrScheduleAndFreezesProofs(): void
    {
        $id = $this->employment();
        [$context, $terms, $source] = $this->context($id);
        self::assertNull($context['average_earning']);
        self::assertSame([], $context['schedule_source']);
        self::assertSame([], $context['ledger_source']['entitlements']);
        self::assertSame('statutory', $context['policy_versions'][0]['source']);
        $result = (new PayrollHourlyWageCalculator())->calculate(new PayrollHourlyWageContext($id, '2026-06-01', 'employment', $terms, $context['work_segments'], $source, entitlementContext: $context));
        self::assertTrue($result->complete, json_encode($result->blockers));
        self::assertSame(0, array_sum(array_column($result->claims, 'payable_minor')));
        self::assertSame($context, $this->context($id)[0]);
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_surcharge_policies (supplier_id, employment_id, valid_from, agreement_reference, night_rate_bp) VALUES (?, ?, "2026-06-01", "Syntetické sjednání", 2000)')->execute([$this->supplier, $id]);
        $changed = $this->context($id)[0];
        self::assertNotSame($context, $changed);
        self::assertSame(2000, $changed['policy_versions'][0]['rates']['night']['agreed_rate_bp']);
    }

    public function testChangedSelectedTimeSourceDoesNotReuseEarlierPremiumConfirmation(): void
    {
        $id = $this->employment();
        [$context] = $this->context($id);
        self::assertIsArray($context['premium_segments']);
        $this->db->pdo()->prepare('UPDATE payroll_time_months SET revision_no = revision_no + 1, row_version = row_version + 1 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $changed = $this->context($id)[0];
        self::assertNull($changed['premium_segments']);
        self::assertNotNull($changed['evidence_source']);
        self::assertNull($changed['nonworked_holidays']);
    }

    public function testBatchQueryCountDoesNotGrowPerEmploymentAndTenantScopeIsRespected(): void
    {
        $ids = [$this->employment(), $this->employment(), $this->employment(), $this->employment()];
        $loader = new PayrollHourlyEntitlementContextLoader($this->db);
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($this->supplier, $ids, '2026-06-01', '2026-06-30');
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, $ids, '2026-06-01');
        $loader->forMany($this->supplier, '2026-06-01', [], [$ids[0] => $terms[$ids[0]]], $sources);
        $before = $this->questions();
        $single = $loader->forMany($this->supplier, '2026-06-01', [], [$ids[0] => $terms[$ids[0]]], $sources);
        $singleCount = $this->questions() - $before;
        $before = $this->questions();
        $batch = $loader->forMany($this->supplier, '2026-06-01', [], $terms, $sources);
        $batchCount = $this->questions() - $before;
        self::assertCount(4, $batch);
        self::assertSame($singleCount, $batchCount);
        self::assertLessThanOrEqual(14, $batchCount);
        self::assertSame($single[$ids[0]], $batch[$ids[0]]);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplier);
        $foreign = $loader->forMany($other, '2026-06-01', [], $terms, $sources);
        self::assertNull($foreign[$ids[0]]['evidence_source']);
        self::assertSame([], $foreign[$ids[0]]['policy_source']);
    }

    private function context(int $id): array
    {
        $terms = (new PayrollRunSnapshotBatchLoader($this->db))->hourlyRemunerationTerms($this->supplier, [$id], '2026-06-01', '2026-06-30');
        $sources = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, [$id], '2026-06-01');
        return [(new PayrollHourlyEntitlementContextLoader($this->db))->forMany($this->supplier, '2026-06-01', [], $terms, $sources)[$id], $terms[$id], $sources[$id]];
    }

    private function questions(): int { return (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value']; }

    private function employment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetický nulový měsíc", "employee", 1)')->execute([$this->supplier]);
        $person = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, ?, "employment", "active", "2026-01-01")')->execute([$this->supplier, $person, 'SYN-HOUR-' . $person]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "contract", "Syntetické podmínky")')->execute([$this->supplier, $id]);
        $this->time->saveManualSummary($this->supplier, $id, '2026-06-01', 0, 'Výslovná nula', true, [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'worked_duration_units' => 0, 'overtime_duration_units' => 0, 'worked_days' => 0]], $this->user);
        $pdo->prepare('UPDATE payroll_time_months SET status = "approved", approved_at = NOW() WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplier, $id]);
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplier, [$id], '2026-06-01')[$id];
        $body = ['row_version' => 0, 'reason' => 'Výslovně bez dalších nároků', 'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'night_duration_units' => 0, 'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [], 'holiday_work_days' => [], 'overtime_work_days' => []]],
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
        (new PayrollHourlyEvidenceRepository($this->db))->save($this->supplier, $id, '2026-06-01', $body, $this->user, static fn() => null);
        return $id;
    }
}
