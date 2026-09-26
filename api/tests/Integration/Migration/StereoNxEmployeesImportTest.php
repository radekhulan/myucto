<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\StereoNx\StereoNxEmployees;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxEmployeesImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxEmployees $employees;
    private int $sourceSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->employees = $container->get(StereoNxEmployees::class);
        foreach (['stereo_nx_import_map', 'payroll_employees', 'payroll_employments', 'payroll_person_identifiers'] as $table) {
            if (!$this->db->hasTable($table)) self::markTestSkipped("Chybí tabulka {$table}.");
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId <= 0 || $this->userId <= 0) self::markTestSkipped('Chybí základní testovací data.');
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testWritesEncryptedPersonAndEmploymentWithoutPostingAndRepeatsIdempotently(): void
    {
        $supplierId = $this->payrollSupplier();
        $plan = StereoNxEmployees::fromTables($this->tables(), ['ico' => '12345679'], 1);
        $beforeJournal = $this->rows('journal_entries', $supplierId);

        $first = $this->employees->write($plan, $supplierId, $this->userId);
        self::assertSame(1, $first['counts']['employees_created']);
        self::assertSame(1, $first['counts']['employments_created']);
        self::assertSame([], $first['warnings']);
        self::assertSame(1, $this->rows('payroll_employees', $supplierId));
        self::assertSame(1, $this->rows('payroll_employments', $supplierId));
        self::assertSame(1, $this->rows('payroll_person_identifiers', $supplierId));
        self::assertSame(2, $this->rows('stereo_nx_import_map', $supplierId));
        self::assertSame($beforeJournal, $this->rows('journal_entries', $supplierId));
        self::assertSame(1, $this->rows('payroll_person_contacts', $supplierId));
        self::assertSame(1, $this->rows('payroll_person_tax_declarations', $supplierId));

        $relation = $this->db->pdo()->prepare(
            'SELECT e.code, e.relation_type, e.status, e.start_date, e.actual_start_date,
                    t.weekly_hours, t.monthly_gross_minor
               FROM payroll_employments e JOIN payroll_employment_terms t
                 ON t.supplier_id = e.supplier_id AND t.employment_id = e.id
              WHERE e.supplier_id = ?'
        );
        $relation->execute([$supplierId]);
        self::assertSame([
            'code' => 'TEST-1',
            'relation_type' => 'employment',
            'status' => 'active',
            'start_date' => '2025-01-01',
            'actual_start_date' => '2025-01-01',
            'weekly_hours' => '40.00',
            'monthly_gross_minor' => 2800000,
        ], array_map(static fn (mixed $v): mixed => is_string($v) && ctype_digit($v) ? (int) $v : $v,
            $relation->fetch(\PDO::FETCH_ASSOC)));

        $repeat = $this->employees->write($plan, $supplierId, $this->userId);
        self::assertSame(1, $repeat['counts']['employees_existing']);
        self::assertSame(0, $repeat['counts']['employees_created']);
        self::assertSame(1, $this->rows('payroll_employees', $supplierId));

        $changed = $this->tables();
        $changed['MZAMEST'][0]['MesTarif'] = 29_000.0;
        $changedPlan = StereoNxEmployees::fromTables($changed, ['ico' => '12345679'], 1);
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Zdrojová karta se od předchozího převodu změnila.');
        $this->employees->write($changedPlan, $supplierId, $this->userId);
    }

    public function testMissingPayrollModuleSkipsCardsExplicitly(): void
    {
        $supplierId = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$supplierId]);
        $plan = StereoNxEmployees::fromTables($this->tables(), ['ico' => '12345679'], 1);

        $result = $this->employees->write($plan, $supplierId, $this->userId);
        self::assertSame(1, $result['counts']['employees_skipped']);
        self::assertSame(['payroll_prerequisite_missing'], array_column($result['warnings'], 'code'));
        self::assertSame(0, $this->rows('payroll_employees', $supplierId));
        self::assertSame(0, $this->rows('stereo_nx_import_map', $supplierId));
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare('INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
            VALUES (?, "setup", "2026-01-01", ?, NOW())')->execute([$supplierId, $this->userId]);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
            VALUES (?, "SNX", "Syntetická účtárna", "1234567890", 1)')->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_office_registration_versions
            (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
            VALUES (?, ?, "2025-01-01", "1234567890", "synthetic:stereo-nx")')->execute([$supplierId, $officeId]);
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
            VALUES (?, ?, "P")')->execute([$supplierId, $officeId]);
        return $supplierId;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function tables(): array
    {
        return [
            'MZAMEST' => [[
                'Prac' => 'TEST-1', 'KrestniJmeno' => 'Jana', 'Prijmeni' => 'Vzorová',
                'Narozeni' => '1992-06-20', 'RC' => '920620/0102',
                'DatumNastupu' => '2025-01-01', 'DatumUkonceni' => null,
                'PracPravVztah' => 'P', 'Odvod' => 'HPP', 'Zamestnanec' => true,
                'StatOrg' => false, 'Vyrazen' => false, 'TydUvazHod' => 40.0,
                'MesTarif' => 28_000.0, 'HodTarif' => 0.0, 'Pojistovna' => 'synthetic-insurer-key',
                'Email' => 'jana.vzorova@example.test',
            ]],
            'MMzdy' => [[
                'Klic' => 1, 'Rok' => 2025, 'Mesic' => 1, 'Prac' => 'TEST-1',
                'HrubaMzda' => 28_000.0, 'Prohlaseni' => true,
            ]],
            'MPOJIST' => [['Pojistovna' => 'synthetic-insurer-key', 'KodZP' => '111']],
            'MDeti' => [],
            'MOpNezdC' => [],
            'MDovol' => [],
            'MPRVYD' => [],
        ];
    }

    private function rows(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
