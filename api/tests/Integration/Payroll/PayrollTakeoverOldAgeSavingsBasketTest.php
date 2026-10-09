<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationTakeoverFacts;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Roční koš § 6 odst. 9 písm. m) ZDP (příspěvky zaměstnavatele na produkty spoření na
 * stáří) v roce přechodu: příspěvky převzatých měsíců čerpají limit stejně jako vstupy
 * počítané MyÚčtem. Měsíc převzatý dvakrát (převod i import hlášení) se počítá jednou,
 * jiný rok a jiný koš se nezapočítají. Syntetická data.
 */
#[Group('integration')]
final class PayrollTakeoverOldAgeSavingsBasketTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollMigrationReferenceTotalsWriter $writer;
    private PayrollInputRepository $inputs;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->writer = $container->get(PayrollMigrationReferenceTotalsWriter::class);
            $this->inputs = $container->get(PayrollInputRepository::class);
        } catch (\Throwable $exception) {
            self::markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($source === 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
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

    public function testTakenOverContributionsDrawTheAnnualBasket(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Spořící Syntetický", "employee", 1)')
            ->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date, actual_start_date,
                monthly_gross_minor, is_legacy_projection, is_primary) VALUES (?, ?, "SP-1", "employment", "active", "2025-01-01", "2025-01-01", 4000000, 0, 1)')
            ->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();

        $month = fn (string $period, ?int $contribution): PayrollMigrationReferenceTotals => PayrollMigrationReferenceTotals::fromAmounts(
            $period, 'employee:' . $employeeId, 'employment:' . $employmentId, $employeeId, $employmentId,
            ['gross' => 40_000, 'net' => 30_000, 'social_base' => 40_000, 'health_base' => 40_000, 'employee_social' => 2_840,
                'employee_health' => 1_800, 'employer_social' => 9_920, 'employer_health' => 3_600, 'advance_tax' => 3_430,
                'withholding_tax' => 0, 'tax_bonus' => 0],
            new PayrollMigrationTakeoverFacts(oldAgeSavingsContributionMinor: $contribution),
        );
        $this->writer->store($this->supplierId, 'pamica', [$month('2026-01', 100_000), $month('2026-02', 150_000), $month('2026-03', null), $month('2025-12', 900_000)]);
        // Tentýž měsíc z importu přijatého hlášení se nezapočítá podruhé.
        $this->writer->store($this->supplierId, 'jmhz', [$month('2026-02', 150_000)]);

        self::assertSame(250_000, $this->inputs->annualBasketTotal($this->supplierId, $employeeId, PayrollBenefitExemptionBasket::OldAgeSavings, 2026));
        self::assertSame(900_000, $this->inputs->annualBasketTotal($this->supplierId, $employeeId, PayrollBenefitExemptionBasket::OldAgeSavings, 2025));
        self::assertSame(0, $this->inputs->annualBasketTotal($this->supplierId, $employeeId, PayrollBenefitExemptionBasket::NonCashLeisure, 2026));
    }
}
