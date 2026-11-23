<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vlastní sešit firmy s vlastním profilem mapování: údaje pro registraci ČSSZ
 * se namapují stejně jako ve Vzoru MyÚčto a nové osobě se zapíšou. Sloupec
 * pojmenovaný jako v šabloně profil navrhne sám.
 */
#[Group('integration')]
final class AttendanceCustomProfileRegistrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private AttendanceImportService $service;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AttendanceImportService::class);
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

    public function testCustomProfileMapsRegistrationColumnsAndCreationWritesThem(): void
    {
        $rule = static fn (string $header, string $meaning, ?string $unit = 'text'): array => [
            'sheet' => null, 'header' => $header, 'meaning' => $meaning, 'unit' => $unit, 'component_code' => null,
        ];
        $profile = $this->service->saveProfile($this->supplierId, null, 'Agentura vlastní', [
            $rule('pracovník', 'person_name'),
            $rule('os. č. agentury', 'personal_number'),
            $rule('národnost', 'citizenship'),
            $rule('kód profese', 'cz_isco_code'),
            $rule('hodiny v měsíci', 'worked_hours', null),
        ], $this->userId);
        $profileId = (int) $profile['id'];

        $file = AttendanceFixture::file('agentura.xlsx', AttendanceFixture::xlsx([
            'Brigády' => ['rows' => [
                1 => ['A' => 'Pracovník', 'B' => 'Os. č. agentury', 'C' => 'Národnost', 'D' => 'Kód profese', 'E' => 'Místo výkonu práce', 'F' => 'Hodiny v měsíci'],
                2 => ['A' => 'Vlastní Olga', 'B' => 'AG01', 'C' => 'sk', 'D' => '43111', 'E' => 'Brno', 'F' => 12],
            ]],
        ]));
        $preview = $this->service->preview($this->supplierId, '2026-06', [$file], null, $profileId);
        $columns = array_column($preview['sheets'][0]['columns'], null, 'header');
        self::assertSame('citizenship', $columns['Národnost']['meaning']);
        self::assertSame('cz_isco_code', $columns['Kód profese']['meaning']);
        // Hlavička jako v šabloně Vzoru MyÚčto: profil ji nezná, návrh ji pozná.
        self::assertSame(['workplace', 'suggested'], [$columns['Místo výkonu práce']['meaning'], $columns['Místo výkonu práce']['rule_source']]);
        $person = $preview['persons'][0];
        self::assertSame(
            ['citizenship' => 'SK', 'cz_isco_code' => '43111', 'workplace' => 'Brno'],
            array_column($person['registration'], 'value', 'key'),
        );

        $created = $this->service->persons($this->supplierId, '2026-06', [[
            'person_key' => $person['key'],
            'full_name' => 'Olga Vlastní',
            'first_name' => 'Olga',
            'last_name' => 'Vlastní',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-06-01',
            'personal_number' => 'AG01',
            'activate' => true,
        ]], $this->userId, null, null, [$file], null, $profileId)['results'][0];
        self::assertSame('created', $created['status'], (string) $created['message']);

        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT citizenship_country_code FROM payroll_person_identity_history
                      WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1),
                    (SELECT CONCAT(cz_isco_code, "|", work_place) FROM payroll_employment_terms
                      WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1)',
        );
        $stmt->execute([$this->supplierId, $created['employee_id'], $this->supplierId, $created['employment_id']]);
        self::assertSame(['SK', '43111|Brno'], $stmt->fetch(\PDO::FETCH_NUM));
    }
}
