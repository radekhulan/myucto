<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAccidentInsuranceRateRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAccidentInsuranceRate;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sazba zákonného pojištění odpovědnosti z plateb předchozího programu: platba za
 * čtvrtletí / převzaté vyměřovací základy předchozího čtvrtletí, zarovnáno na sazebník.
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
final class PayrollTakeoverAccidentInsuranceRateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTakeoverAccidentInsuranceRate $service;
    private PayrollAccidentInsuranceRateRepository $rates;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->service = $container->get(PayrollTakeoverAccidentInsuranceRate::class);
        $this->rates = $container->get(PayrollAccidentInsuranceRateRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testRateFromQuarterPaymentsSnapsToTheAnnexAndSkipsCompanyBodies(): void
    {
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $month) {
            $this->base($month, 'employment', 10_000_000);
            // Jednatel do základu zákonného pojištění nepatří.
            $this->base($month, 'statutory_body', 5_000_000);
        }
        foreach (['2026-04-01', '2026-05-01', '2026-06-01'] as $month) {
            $this->base($month, 'employment', 10_200_000);
        }
        // 2. čtvrtletí: 300 000 Kč × 5,6 ‰; 3. čtvrtletí z opraveného základu jen přibližně.
        $written = $this->service->apply($this->supplierId, [
            ['code' => 'KOOPERATIVA', 'name' => 'Kooperativa', 'due_on' => '2026-04-30', 'amount_minor' => 168_000],
            ['code' => 'KOOPERATIVA', 'name' => 'Kooperativa', 'due_on' => '2026-07-31', 'amount_minor' => 171_000],
        ], 2026, 'pamica', null);

        self::assertSame(['code' => 'KOOPERATIVA', 'rate' => '5.60', 'payments' => 2], $written);
        $rate = $this->rates->effectiveOn($this->supplierId, '2026-09-30');
        self::assertSame(['KOOPERATIVA', '5.60', '2026-01-01'], [$rate['institution_code'] ?? null, $rate['rate_per_mille'] ?? null, $rate['effective_from'] ?? null]);
    }

    public function testDisagreeingPaymentsOrExistingRateWriteNothing(): void
    {
        foreach (['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'] as $month) {
            $this->base($month, 'employment', 10_000_000);
        }
        $payments = [
            ['code' => 'KOOPERATIVA', 'name' => 'Kooperativa', 'due_on' => '2026-04-30', 'amount_minor' => 168_000],
            ['code' => 'KOOPERATIVA', 'name' => 'Kooperativa', 'due_on' => '2026-07-31', 'amount_minor' => 84_000],
        ];
        self::assertNull($this->service->apply($this->supplierId, $payments, 2026, 'pamica', null));
        self::assertSame([], $this->rates->list($this->supplierId));

        $this->rates->insert($this->supplierId, 'KOOPERATIVA', '4.20', '2026-01-01', null);
        self::assertNull($this->service->apply($this->supplierId, [$payments[0]], 2026, 'pamica', null));
        self::assertCount(1, $this->rates->list($this->supplierId));
    }

    private function base(string $periodStart, string $relationType, int $socialBaseMinor): void
    {
        $reference = 'synthetic-' . $relationType;
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                (supplier_id, source, period_start, external_person_ref,
                 external_relationship_ref, relation_type, gross_minor,
                 social_base_minor, health_base_minor)
             VALUES (?, "pamica", ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$this->supplierId, $periodStart, $reference, $reference, $relationType,
            $socialBaseMinor, $socialBaseMinor, $socialBaseMinor]);
    }
}
