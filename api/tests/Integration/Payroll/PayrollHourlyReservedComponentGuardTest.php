<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\PayrollHourlyWageComponents;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollHourlyReservedComponentGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollInputRepository $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->inputs = $container->get(PayrollInputRepository::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetická klasifikace hodin", "employee", 1)')->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, "SYN-CLASSIFICATION", "employment", "active", "2026-01-01")')->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $container->get(PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function incompatibleClassifications(): iterable
    {
        foreach ([
            'value_kind' => 'non_monetary',
            'tax_treatment' => 'exempt',
            'social_participation_treatment' => 'excluded',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'excluded',
            'average_earning_treatment' => 'excluded',
            'enforcement_treatment' => 'excluded',
            'jmhz_treatment' => 'excluded',
            'statistics_treatment' => 'excluded',
        ] as $field => $value) {
            yield $field => [PayrollHourlyWageComponents::ORDINARY_BASE, $field, $value];
        }
        yield 'dosažený přesčas' => [PayrollHourlyWageComponents::ACHIEVED_OVERTIME, 'social_treatment', 'excluded'];
        yield 'náhrada nesmí vstoupit do průměru' => [PayrollHourlyWageComponents::HOLIDAY_TIME_OFF, 'average_earning_treatment', 'included'];
        yield 'doplatek minima' => [PayrollHourlyWageComponents::MINIMUM_TOP_UP, 'health_treatment', 'excluded'];
    }

    #[DataProvider('incompatibleClassifications')]
    public function testReservedCodeRejectsIncompatibleCustomClassification(string $code, string $field, string $value): void
    {
        $pdo = $this->db->pdo();
        $componentId = $this->componentId($code);
        if ($field === 'jmhz_treatment') {
            $pdo->prepare('UPDATE payroll_component_jmhz_mappings SET is_active = 0, disabled_at = NOW() WHERE supplier_id = ? AND component_definition_id = ?')->execute([$this->supplierId, $componentId]);
        }
        // Název sloupce pochází výhradně z pevného provideru, hodnoty jsou parametry.
        $pdo->prepare('UPDATE payroll_component_definitions SET ' . $field . ' = ?' . ($field === 'tax_treatment' ? ', exemption_basis = "statutory_exempt"' : '') . ' WHERE supplier_id = ? AND id = ?')->execute([$value, $this->supplierId, $componentId]);
        $before = $this->componentRow($componentId);
        $failure = null;
        try {
            $this->inputs->createApprovedHourlyBatch($this->supplierId, '2026-06-01', [$this->input($code)], $this->userId);
        } catch (\InvalidArgumentException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure, 'Automatický nárok nesmí použít odlišnou klasifikaci stejnojmenné vlastní složky.');
        self::assertSame('Chráněná složka hodinové mzdy nemá platnou klasifikaci.', $failure->getMessage());
        self::assertSame($before, $this->componentRow($componentId), 'Rozhodnutí účetní se nesmí přepsat.');
        $count = $pdo->prepare('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?');
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testNameFrequencyAndLegacyPremiumClassificationRemainEditable(): void
    {
        $pdo = $this->db->pdo();
        $id = $this->componentId(PayrollHourlyWageComponents::ORDINARY_BASE);
        $pdo->prepare('UPDATE payroll_component_definitions SET name = "Vlastní syntetický název", frequency_kind = "one_off" WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $id]);
        $premiumId = $this->componentId('PRIPLATEK_NOCNI');
        $pdo->prepare('UPDATE payroll_component_definitions SET statistics_treatment = "excluded" WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $premiumId]);
        $ids = $this->inputs->createApprovedHourlyBatch($this->supplierId, '2026-06-01', [
            $this->input(PayrollHourlyWageComponents::ORDINARY_BASE), $this->input('PRIPLATEK_NOCNI'),
        ], $this->userId);
        self::assertCount(2, $ids);
        $rows = $pdo->prepare('SELECT status, component_snapshot_json FROM payroll_inputs WHERE supplier_id = ? ORDER BY id');
        $rows->execute([$this->supplierId]);
        $snapshots = $rows->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['approved', 'approved'], array_column($snapshots, 'status'));
        self::assertSame('Vlastní syntetický název', $this->componentRow($id)['name']);
        self::assertSame('one_off', $this->componentRow($id)['frequency_kind']);
        self::assertSame('excluded', $this->componentRow($premiumId)['statistics_treatment']);
    }

    private function componentId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? AND valid_from = "2026-01-01"');
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id);
        return $id;
    }

    /** @return array<string,mixed> */
    private function componentRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM payroll_component_definitions WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function input(string $code): array
    {
        return ['employee_id' => $this->employeeId, 'employment_id' => $this->employmentId,
            'component_code' => $code, 'source_period_start' => '2026-06-01', 'amount_minor' => 100000,
            'external_id' => 'hourly-wage:synthetic-guard-' . $code, 'source_snapshot_json' => '{}'];
    }
}
