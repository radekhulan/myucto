<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Import docházky a otevřená DPP osoby: náhled ji u osoby bez vztahu v období
 * ukáže a založení osoby ji na volbu použije místo nové dohody.
 */
#[Group('integration')]
final class AttendanceOpenAgreementTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollPersonCreateService $people;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AttendanceImportService::class);
        $this->people = $container->get(PayrollPersonCreateService::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
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

    public function testPreviewShowsOpenAgreementThatStartsAfterPeriod(): void
    {
        $this->dpp('B001', 'Eva', 'Ukázková', '2026-07-01', '2026-12-31');
        $profileId = (int) $this->service->restoreSampleProfile($this->supplierId, $this->userId, 'myucto')['profile']['id'];

        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$this->file()], null, $profileId);

        $person = $preview['persons'][0];
        self::assertSame('not_found', $person['match']['status']);
        self::assertIsArray($person['open_agreement'] ?? null, 'Náhled otevřenou dohodu osoby neukázal.');
        self::assertSame('B001', $person['open_agreement']['code']);
        self::assertFalse($person['open_agreement']['reusable']);
        self::assertStringContainsString('otevřenou DPP B001', implode(' ', $person['warnings']));
    }

    public function testPersonCreationReusesChosenOpenAgreementAndExtendsItsEnd(): void
    {
        $employmentId = $this->dpp('B001', 'Eva', 'Ukázková', '2026-06-01', '2026-06-20');

        $result = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => 'eva ukazkova',
            'full_name' => 'Eva Ukázková',
            'first_name' => 'Eva',
            'last_name' => 'Ukázková',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-06-01',
            'fixed_term_end_on' => '31. 12. 2026',
            'personal_number' => 'B001',
            'use_open_agreement' => $employmentId,
        ]], $this->userId, null, null);

        self::assertSame('reused', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        self::assertSame($employmentId, $result['results'][0]['employment_id']);
        self::assertSame(1, $this->employeeCount(), 'Import založil druhou osobu místo použití otevřené dohody.');
        $stmt = $this->db->pdo()->prepare('SELECT end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);
        self::assertSame('2026-12-31', $stmt->fetchColumn());
    }

    private function file(): array
    {
        return AttendanceFixture::file('cerven.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Druh vztahu', 'D' => 'Odpracováno (hod)'],
                2 => ['A' => 'B001', 'B' => 'Ukázková Eva', 'C' => 'DPP', 'D' => 10],
            ]],
        ]));
    }

    private function dpp(string $code, string $firstName, string $lastName, string $start, string $end): int
    {
        $person = $this->people->create($this->supplierId, [
            'full_name' => "{$firstName} {$lastName}",
            'first_name' => $firstName,
            'last_name' => $lastName,
            'relation_type' => 'dpp',
            'planned_start_on' => $start,
            'fixed_term_end_on' => $end,
            'employment_code' => $code,
        ], $this->userId, null, null);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
        $stmt->execute([$this->supplierId, (int) $person['id']]);

        return (int) $stmt->fetchColumn();
    }

    private function employeeCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }
}
