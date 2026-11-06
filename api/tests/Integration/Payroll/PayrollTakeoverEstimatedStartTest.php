<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage;
use MyInvoice\Service\Payroll\PayrollYearCloseService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mezera roku přechodu schovaná za odhadnutým nástupem.
 *
 * Řada převzatých hlášení začíná v březnu, takže import vzal jako nástup 1. 3.
 * a vztah se tvářil, jako by v lednu a únoru netrval. Varování viselo jen
 * v náhledu importu; kontrola převzetí, vyúčtování i uzávěrka o mezeře nevěděly.
 */
#[Group('integration')]
final class PayrollTakeoverEstimatedStartTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverCoverage $coverage;
    private PayrollEmploymentRepository $employments;
    private PayrollYearCloseService $yearClose;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $coverage = $container->get(PayrollTakeoverCoverage::class);
        $employments = $container->get(PayrollEmploymentRepository::class);
        $yearClose = $container->get(PayrollYearCloseService::class);
        if (!$db instanceof Connection
            || !$coverage instanceof PayrollTakeoverCoverage
            || !$employments instanceof PayrollEmploymentRepository
            || !$yearClose instanceof PayrollYearCloseService
        ) {
            throw new \RuntimeException('Služby převzetí nejsou dostupné.');
        }
        $this->db = $db;
        $this->coverage = $coverage;
        $this->employments = $employments;
        $this->yearClose = $yearClose;
        if (!$db->hasColumn('payroll_employments', 'start_estimated')) {
            self::markTestSkipped('Chybí migrace 1930.');
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

    public function testEstimatedStartShowsTheGapWithoutBlockingYearClose(): void
    {
        [$employeeId, $employmentId] = $this->employment('Syntetická osoba odhad', '2026-03-01', true);
        $this->employment('Syntetická osoba doložená', '2026-03-01', false);
        $this->employment('Syntetická osoba od ledna', '2026-01-01', true);

        $gaps = $this->coverage->estimatedStartGaps($this->supplierId, 2026);

        self::assertSame([[
            'employee_id' => $employeeId,
            'employee_name' => 'Syntetická osoba odhad',
            'employment_id' => $employmentId,
            'start_on' => '2026-03-01',
            'possible_months' => [1, 2],
        ]], $gaps);
        self::assertSame([], $this->coverage->estimatedStartGaps($this->supplierId, 2027), 'Jen rok přechodu.');
        self::assertStringContainsString('1–2/2026', (string) PayrollTakeoverCoverage::describeEstimatedStarts($gaps, 2026));

        $status = $this->yearClose->status($this->supplierId, 2026);
        $warning = null;
        foreach ($status['warnings'] ?? [] as $candidate) {
            if ($candidate['code'] === 'takeover_start_estimated') {
                $warning = $candidate;
            }
        }
        self::assertNotNull($warning, json_encode($status, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, $warning['count']);
        self::assertSame($employmentId, $warning['estimated_starts'][0]['employment_id']);
        self::assertNotContains(
            'takeover_start_estimated',
            array_column($status['blockers'] ?? [], 'code'),
            'Odhadnutý nástup je upozornění, ne závora.',
        );
    }

    public function testConfirmingOrMovingTheStartClearsTheFlag(): void
    {
        [$employeeId, $confirmed] = $this->employment('Syntetická osoba potvrzená', '2026-03-01', true);
        [, $moved] = $this->employment('Syntetická osoba posunutá', '2026-03-01', true);

        $card = $this->employments->confirmEstimatedStart($this->supplierId, $confirmed, $this->version($confirmed), $this->userId, null, null);
        self::assertFalse($card['start_estimated']);

        $this->employments->correctStartEarlier($this->supplierId, $moved, '2026-01-05', null, 'Syntetická oprava nástupu.', $this->userId, null, null);

        self::assertSame([], $this->coverage->estimatedStartGaps($this->supplierId, 2026));
        self::assertSame(0, $this->flag($confirmed));
        self::assertSame(0, $this->flag($moved));
        self::assertGreaterThan(0, $employeeId);
    }

    /**
     * Doplnění stávajících dat v migraci 1930: nástup na prvním dni měsíce,
     * ve kterém začínají převzaté mzdy z hlášení JMHZ firmy i vztahu.
     */
    public function testMigrationBackfillFlagsOnlyTheEarliestReportedMonth(): void
    {
        [$employeeA, $estimated] = $this->employment('Syntetická osoba A', '2026-03-01', false);
        [$employeeB, $later] = $this->employment('Syntetická osoba B', '2026-05-01', false);
        [$employeeC, $midMonth] = $this->employment('Syntetická osoba C', '2026-03-04', false);
        $this->totals($employeeA, $estimated, ['2026-03-01', '2026-04-01']);
        $this->totals($employeeB, $later, ['2026-05-01']);
        $this->totals($employeeC, $midMonth, ['2026-03-01']);

        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/1930_payroll_employment_start_estimated.sql');
        $update = substr($sql, (int) strpos($sql, 'UPDATE payroll_employments'));
        $this->db->pdo()->exec($update);
        $this->db->pdo()->exec($update);

        self::assertSame([1, 0, 0], [$this->flag($estimated), $this->flag($later), $this->flag($midMonth)]);
    }

    /** @return array{0:int,1:int} */
    private function employment(string $name, string $start, bool $estimated): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, is_primary, start_date, actual_start_date, start_estimated)
             VALUES (?, ?, ?, "employment", "active", 1, ?, ?, ?)'
        )->execute([$this->supplierId, $employeeId, 'E' . $employeeId, $start, $start, (int) $estimated]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, actual_start_on, workload_basis_points, weekly_hours, is_primary)
             VALUES (?, ?, ?, ?, ?, 10000, 40.00, 1)'
        )->execute([$this->supplierId, $employmentId, $start, $start, $start]);

        return [$employeeId, $employmentId];
    }

    /** @param list<string> $periods */
    private function totals(int $employeeId, int $employmentId, array $periods): void
    {
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref, external_relationship_ref, employee_id, employment_id)
             VALUES (?, 'jmhz', ?, ?, ?, ?, ?)"
        );
        foreach ($periods as $period) {
            $insert->execute([$this->supplierId, $period, 'employee:' . $employeeId, 'employment:' . $employmentId, $employeeId, $employmentId]);
        }
    }

    private function flag(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT start_estimated FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }

    private function version(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }
}
