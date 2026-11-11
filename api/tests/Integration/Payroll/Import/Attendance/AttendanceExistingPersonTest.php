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
 * Brigádník, jehož lednová DPP skončila, přijde v únorových podkladech znovu:
 * založení mu přidá novou DPP, nová karta osoby (ani druhé rodné číslo) nevznikne.
 */
#[Group('integration')]
final class AttendanceExistingPersonTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-02';

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollPersonCreateService $people;
    private int $supplierId;
    private int $userId;
    private int $profileId;

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
        $this->profileId = (int) $this->service->restoreSampleProfile($this->supplierId, $this->userId, 'myucto')['profile']['id'];
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

    public function testEndedJanuaryDppGetsNewDppOnSameEmployeeInFebruary(): void
    {
        // Anna má osobní číslo, Bára jen rodné číslo; obě lednové DPP skončily 31. 1.
        [$anna] = $this->januaryDpp('Anna', 'Lednová', 'B030', null);
        [$bara] = $this->januaryDpp('Bára', 'Lednová', null, '905515/0005');
        $file = AttendanceFixture::file('unor.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Rodné číslo', 'D' => 'Druh vztahu', 'E' => 'Odpracováno (hod)'],
                2 => ['A' => 'B030', 'B' => 'Lednová Anna', 'D' => 'DPP', 'E' => 8],
                3 => ['B' => 'Lednová Bára', 'C' => '9055150005', 'E' => 6],
            ]],
        ]));

        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $persons = array_column($preview['persons'], null, 'key');
        foreach (['anna lednova' => $anna, 'bara lednova' => $bara] as $key => $employeeId) {
            self::assertSame('not_found', $persons[$key]['match']['status']);
            self::assertSame($employeeId, $persons[$key]['existing_person']['employee_id'] ?? null, "Osoba {$key} se v evidenci nenašla.");
            self::assertNull($persons[$key]['open_agreement']);
            self::assertStringContainsString('nová karta osoby nevznikne', implode(' ', $persons[$key]['warnings']));
        }
        self::assertSame('dpp', $persons['bara lednova']['existing_person']['relation_type']);

        $items = [];
        foreach ($persons as $key => $person) {
            $items[] = [
                'person_key' => $key,
                'full_name' => $person['display_name'],
                'first_name' => explode(' ', $person['display_name'])[1],
                'last_name' => explode(' ', $person['display_name'])[0],
                // Bez druhu v podkladech: server vezme druh posledního vztahu osoby.
                'relation_type' => $key === 'anna lednova' ? 'dpp' : null,
                'planned_start_on' => '2026-02-01',
                'personal_number' => $person['personal_number'],
                'activate' => true,
            ];
        }
        $results = array_column(
            $this->service->persons($this->supplierId, self::PERIOD, $items, $this->userId, null, null, [$file], null, $this->profileId)['results'],
            null,
            'person_key',
        );
        foreach (['anna lednova' => $anna, 'bara lednova' => $bara] as $key => $employeeId) {
            self::assertSame('employment_created', $results[$key]['status'], (string) $results[$key]['message']);
            self::assertSame($employeeId, $results[$key]['employee_id']);
            self::assertStringContainsString('nová karta osoby nevznikla', (string) $results[$key]['message']);
        }
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?'));
        self::assertSame(
            [['dpp', '2026-01-05'], ['dpp', '2026-02-01']],
            $this->employments($anna),
        );
        self::assertSame([['dpp', '2026-01-05'], ['dpp', '2026-02-01']], $this->employments($bara));

        $again = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        self::assertSame(2, $again['summary']['matched']);
    }

    /** @return array{0:int,1:int} osoba, vztah */
    private function januaryDpp(string $first, string $last, ?string $code, ?string $birthNumber): array
    {
        $person = $this->people->create($this->supplierId, array_filter([
            'full_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-01-05',
            'fixed_term_end_on' => '2026-01-31',
            'employment_code' => $code,
            'birth_number' => $birthNumber,
        ], static fn (mixed $value): bool => $value !== null), $this->userId, null, null);
        $employeeId = (int) $person['id'];
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "active", actual_start_date = start_date WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);

        return [$employeeId, 0];
    }

    /** @return list<array{0:string,1:string}> */
    private function employments(int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type, start_date FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY start_date',
        );
        $stmt->execute([$this->supplierId, $employeeId]);

        return array_map(static fn (array $row): array => [(string) $row['relation_type'], (string) $row['start_date']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
