<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lednové hlášení chtělo u každé nové brigádnice ruční „nepožádal" o roční
 * zúčtování za rok, ve kterém u firmy vůbec nepracovala. O zúčtování se žádá
 * u plátce, od kterého měl poplatník v roce příjem, takže tam odpověď plyne
 * z evidence sama — pokud mzdy v aplikaci pokrývají celý ten rok.
 */
#[Group('integration')]
final class JmhzAnnualRequestWithoutEmploymentTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzAnnualEvidenceService $evidence;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('Aplikační kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->evidence = $container->get(JmhzAnnualEvidenceService::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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

    public function testNewHireInCoveredYearIsNotRequestedWithoutManualEntry(): void
    {
        $this->payrollStartsOn('2025-01-01');
        $newHire = $this->employeeWithEmployment('Syntetická Nová', '2026-01-08');
        $lastYear = $this->employeeWithEmployment('Syntetický Loňský', '2025-06-01');

        $snapshots = $this->evidence->snapshotsForPreparation($this->supplierId, [$newHire, $lastYear], 2026);

        self::assertSame(
            JmhzAnnualEvidenceService::PROOF_NO_EMPLOYMENT_IN_TAX_YEAR,
            $snapshots[$newHire]['request_evidence']['proof'],
        );
        self::assertSame('request_absent_under_unique_key_lock', $snapshots[$lastYear]['request_evidence']['proof']);

        self::assertSame([], $this->annualRequestBlockers($snapshots[$newHire]));
        self::assertSame(
            ['jmhz_annual_request_source_missing'],
            $this->annualRequestBlockers($snapshots[$lastYear]),
        );
    }

    /**
     * Firma vede mzdy v aplikaci až od ledna a loňskou historii nepřevzala:
     * chybějící loňský vztah nic nedokazuje, brigádnice u ní mohla pracovat
     * v předchozím programu. Odpověď musí dál zadat účetní.
     */
    public function testYearNotCoveredByPayrollStillRequiresExplicitAnswer(): void
    {
        $this->payrollStartsOn('2026-01-01');
        $newHire = $this->employeeWithEmployment('Syntetická Nová', '2026-01-08');

        $snapshots = $this->evidence->snapshotsForPreparation($this->supplierId, [$newHire], 2026);

        self::assertSame('request_absent_under_unique_key_lock', $snapshots[$newHire]['request_evidence']['proof']);
        self::assertSame(
            ['jmhz_annual_request_source_missing'],
            $this->annualRequestBlockers($snapshots[$newHire]),
        );
    }

    private function payrollStartsOn(string $period): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', ?)
             ON DUPLICATE KEY UPDATE start_period = VALUES(start_period)",
        )->execute([$this->supplierId, $period]);
    }

    private function employeeWithEmployment(string $name, string $start): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, "dpp", "active", ?, ?, 1)',
        )->execute([$this->supplierId, $employeeId, 'SYN-' . $employeeId, $start, $start]);

        return $employeeId;
    }

    /**
     * Kódy nálezů pole 10319 z resolveru lednového hlášení.
     *
     * @param array<string,mixed> $evidence
     * @return list<string>
     */
    private function annualRequestBlockers(array $evidence): array
    {
        $resolver = new JmhzScenario1DocumentResolver();
        $blockers = [];
        \Closure::bind(
            function (array $evidence) use (&$blockers): void {
                $this->annualSummary($evidence, '2026-01-01', 1, $blockers);
            },
            $resolver,
            JmhzScenario1DocumentResolver::class,
        )($evidence);

        return array_values(array_filter(
            array_map(static fn ($blocker): string => $blocker->code, $blockers),
            static fn (string $code): bool => str_starts_with($code, 'jmhz_annual_request'),
        ));
    }
}
