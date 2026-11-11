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
 * Pravděpodobný výdělek z importu docházky bez sloupce sazby: odměna za
 * odpracované hodiny / hodiny. Nový vztah ho dostane při založení, existující
 * na potvrzení; druh odměňování zůstává a importovaná hodinová mzda projde.
 */
#[Group('integration')]
final class AttendanceProbableEarningTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;

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

    public function testNewPersonGetsProbableEarningFromAmountAndHours(): void
    {
        $file = $this->file('B020', 'Výdělková Iva', 3, 100);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        // 100 Kč za 3 h = 33,333… Kč/h, na haléře 33,33 Kč.
        self::assertSame(
            ['source' => 'amount', 'hourly_minor' => 3_333, 'amount_minor' => 10_000, 'hours' => '3.00'],
            $person['probable_earning'] ?? null,
            'Náhled návrh pravděpodobného výdělku z částky neukázal.',
        );

        $created = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => $person['key'],
            'full_name' => 'Iva Výdělková',
            'first_name' => 'Iva',
            'last_name' => 'Výdělková',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-06-01',
            'personal_number' => 'B020',
            'activate' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId);
        self::assertSame('created', $created['results'][0]['status'], (string) $created['results'][0]['message']);
        $terms = $this->terms((int) $created['results'][0]['employment_id']);
        self::assertSame(3_333, $terms['probable']);
        self::assertStringContainsString('Odměna za odpracované hodiny podle importu', (string) $terms['rationale']);
        self::assertNull($terms['remuneration_type']);
    }

    public function testExistingEmploymentWithoutEarningGetsConfirmedProposalAndImportStillPasses(): void
    {
        $person = $this->people->create($this->supplierId, [
            'full_name' => 'Iva Výdělková',
            'first_name' => 'Iva',
            'last_name' => 'Výdělková',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-05-01',
            'employment_code' => 'B020',
        ], $this->userId, null, null);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
        $stmt->execute([$this->supplierId, (int) $person['id']]);
        $employmentId = (int) $stmt->fetchColumn();

        $file = $this->file('B020', 'Výdělková Iva', 42.5, 7650);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        self::assertSame(
            [['key' => $preview['persons'][0]['key'], 'display_name' => 'Výdělková Iva', 'employment_id' => $employmentId,
                'source' => 'amount', 'hourly_minor' => 18_000, 'amount_minor' => 765_000, 'hours' => '42.50']],
            $preview['probable_earning_changes'] ?? null,
        );

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            [$file],
            null,
            [],
            false,
            true,
            $this->userId,
            createComponents: true,
            profileId: $this->profileId,
            adoptProbableEarnings: true,
        );
        self::assertSame(['adopted' => 1, 'conflicts' => []], $result['probable_earnings']);
        self::assertSame([], $result['inputs']['errors']);
        $terms = $this->terms($employmentId);
        self::assertSame(18_000, $terms['probable']);
        self::assertNull($terms['remuneration_type'], 'Import nesmí vztah přepnout na hodinové odměňování.');

        $again = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        self::assertSame([], $again['probable_earning_changes']);
    }

    /** @return array{name:string,content:string,sha256:string,extension:string} */
    private function file(string $number, string $name, float $hours, int $amount): array
    {
        return AttendanceFixture::file('mesic.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Druh vztahu', 'D' => 'Odpracováno (hod)', 'E' => 'Hodinová mzda celkem (Kč)'],
                2 => ['A' => $number, 'B' => $name, 'C' => 'DPP', 'D' => $hours, 'E' => $amount],
            ]],
        ]));
    }

    /** @return array{probable:?int,rationale:?string,remuneration_type:?string} */
    private function terms(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT probable_hourly_earning_minor, probable_earning_rationale, remuneration_type FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'probable' => $row['probable_hourly_earning_minor'] === null ? null : (int) $row['probable_hourly_earning_minor'],
            'rationale' => $row['probable_earning_rationale'],
            'remuneration_type' => $row['remuneration_type'],
        ];
    }
}
