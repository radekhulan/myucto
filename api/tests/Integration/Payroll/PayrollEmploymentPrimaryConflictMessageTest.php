<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odmítnutý druhý primární vztah musí říct, který vztah primární drží a jak
 * to přepnout. Brigádník mívá desítky dohod; holé „osoba už má jiný primární
 * pracovní vztah" posílalo účetní hledat naslepo.
 */
#[Group('integration')]
final class PayrollEmploymentPrimaryConflictMessageTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentRepository $employments;
    private PayrollEmploymentValidator $validator;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->validator = $container->get(PayrollEmploymentValidator::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare("UPDATE supplier SET payroll_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'HLAVNI', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, 'Syntetická brigádnice', 'employee', 1)"
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testRefusalNamesTheOtherPrimaryEmploymentAndHowToSwitch(): void
    {
        $this->create('SYN-BREZEN', '2026-03-01', '2026-03-31');

        try {
            // Překrývá se s březnovým vztahem; navazující by hlavní roli převzal.
            $this->create('SYN-DUBEN', '2026-03-15', '2026-04-30');
            self::fail('Druhý primární vztah se nesměl založit.');
        } catch (\DomainException $exception) {
            $message = $exception->getMessage();
        }

        self::assertStringContainsString('SYN-BREZEN', $message);
        self::assertStringContainsString('pracovní poměr', $message);
        self::assertStringContainsString('1. 3. 2026 – 31. 3. 2026', $message);
        self::assertStringContainsString('zrušte „Primární vztah"', $message);
    }

    private function create(string $code, string $from, string $to): void
    {
        $this->employments->create(
            $this->supplierId,
            $this->employeeId,
            $this->validator->create([
                'code' => $code,
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4_200_000,
                'terms' => [
                    'office_id' => null,
                    'effective_from' => $from,
                    'contract_signed_on' => $from,
                    'planned_start_on' => $from,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => $to,
                    'weekly_hours' => '40',
                    'workload_basis_points' => 10000,
                    'work_place' => 'Hlavní město Praha',
                    'regular_workplace' => 'Praha',
                    'jmhz_workplace_municipality_code' => '554782',
                    'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => 'no',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'no',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => null,
                    'activity_code' => '1',
                    'jmhz_relationship_detail_code' => '1',
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => true,
                    'change_reason' => 'Syntetický vztah',
                ],
            ]),
            $this->userId,
            '127.0.0.1',
            'phpunit',
        );
    }
}
