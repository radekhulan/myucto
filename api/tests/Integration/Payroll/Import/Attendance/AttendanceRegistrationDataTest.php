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
 * Šablona Vzoru MyÚčto s údaji pro registraci ČSSZ: nové osobě se při založení
 * zapíšou adresa, občanství, podmínky, daňová rezidence, prohlášení a profil
 * registrace; existující osobě jen to, co v evidenci chybí.
 */
#[Group('integration')]
final class AttendanceRegistrationDataTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const HEADERS = [
        'A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Druh vztahu', 'D' => 'Nástup',
        'E' => 'Ulice', 'F' => 'Číslo domu', 'G' => 'Obec', 'H' => 'PSČ', 'I' => 'Stát',
        'J' => 'Státní občanství', 'K' => 'CZ-ISCO', 'L' => 'Místo výkonu práce', 'M' => 'Daňová rezidence',
        'N' => 'Prohlášení poplatníka', 'O' => 'Prohlášení od', 'P' => 'Vzdělání', 'Q' => 'Odpracováno (hod)',
    ];

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

    public function testNewPersonGetsRegistrationDataOnCreation(): void
    {
        $file = $this->file('B010', 'Registrační Hana', '43111');
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertSame('not_found', $person['match']['status']);
        self::assertArrayNotHasKey('_registration', $person, 'Adresu z podkladů klient celou nedostane.');
        self::assertSame(
            ['address', 'citizenship', 'cz_isco_code', 'workplace', 'tax_residence', 'tax_declaration', 'house_number', 'education_code'],
            array_column($person['registration'] ?? [], 'key'),
        );
        self::assertStringNotContainsString('Ukázková', json_encode($person, JSON_UNESCAPED_UNICODE) ?: '');

        $created = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => $person['key'],
            'full_name' => 'Hana Registrační',
            'first_name' => 'Hana',
            'last_name' => 'Registrační',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-06-01',
            'personal_number' => 'B010',
            'activate' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId);
        $result = $created['results'][0];
        self::assertSame('created', $result['status'], (string) $result['message']);

        $state = $this->state((int) $result['employee_id'], (int) $result['employment_id']);
        self::assertSame(
            [
                'address' => 'Ukázková 12|Praha|11000|CZ',
                'citizenship' => 'CZ',
                'cz_isco_code' => '43111',
                'work_place' => 'Hlavní město Praha',
                'tax_residence' => 'czech-resident',
                'declaration' => 'signed',
                'a1_profile' => 1,
            ],
            $state,
            'Výsledek založení: ' . (string) $result['message'],
        );
    }

    public function testExistingPersonGetsOnlyMissingDataWithoutOverwriting(): void
    {
        $person = $this->people->create($this->supplierId, [
            'full_name' => 'Hana Registrační',
            'first_name' => 'Hana',
            'last_name' => 'Registrační',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-05-01',
            'employment_code' => 'B010',
        ], $this->userId, null, null);
        $employeeId = (int) $person['id'];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
        $stmt->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $stmt->fetchColumn();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET cz_isco_code = "43111" WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);

        // Podklady uvádějí jiné CZ-ISCO: to zůstane, doplní se jen chybějící údaje.
        $file = $this->file('B010', 'Registrační Hana', '93290');
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $matched = $preview['persons'][0];
        self::assertContains($matched['match']['status'], ['linked', 'matched']);
        $keys = array_column($matched['registration'], 'key');
        self::assertNotContains('cz_isco_code', $keys);
        self::assertContains('address', $keys);
        self::assertSame(1, $preview['summary']['registration_gaps']);

        $applied = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            [$file],
            null,
            [],
            false,
            false,
            $this->userId,
            profileId: $this->profileId,
            fillRegistrationData: true,
        );
        self::assertSame(1, $applied['registration']['filled'], json_encode($applied['registration'], JSON_UNESCAPED_UNICODE) ?: '');
        $state = $this->state($employeeId, $employmentId);
        self::assertSame('43111', $state['cz_isco_code'], 'Import přepsal CZ-ISCO, které už v evidenci bylo.');
        self::assertSame('Ukázková 12|Praha|11000|CZ', $state['address']);
        self::assertSame('Hlavní město Praha', $state['work_place']);
        self::assertSame('czech-resident', $state['tax_residence']);

        // Opakované použití už nemá co doplnit.
        $again = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        self::assertSame(0, $again['summary']['registration_gaps'], json_encode($again['persons'][0]['registration'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @return array{name:string,content:string,sha256:string,extension:string} */
    private function file(string $number, string $name, string $isco): array
    {
        return AttendanceFixture::file('registrace.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => self::HEADERS,
                2 => [
                    'A' => $number, 'B' => $name, 'C' => 'DPP', 'D' => '1. 6. 2026',
                    'E' => 'Ukázková', 'F' => '12', 'G' => 'Praha', 'H' => '110 00', 'I' => 'CZ',
                    'J' => 'CZ', 'K' => $isco, 'L' => 'Praha', 'M' => 'CZ',
                    'N' => 'ano', 'O' => '1. 6. 2026', 'P' => 'M', 'Q' => 10,
                ],
            ]],
        ]));
    }

    /** @return array<string,mixed> */
    private function state(int $employeeId, int $employmentId): array
    {
        $one = function (string $sql, array $params): mixed {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchColumn();
        };
        $terms = $this->db->pdo()->prepare(
            'SELECT cz_isco_code, work_place FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $terms->execute([$this->supplierId, $employmentId]);
        $row = $terms->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'address' => $one(
                'SELECT CONCAT_WS("|", street_line, city, postal_code, country_code) FROM payroll_person_addresses
                  WHERE supplier_id = ? AND employee_id = ? AND address_type = "residence"',
                [$this->supplierId, $employeeId],
            ),
            'citizenship' => $one(
                'SELECT citizenship_country_code FROM payroll_person_identity_history
                  WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1',
                [$this->supplierId, $employeeId],
            ),
            'cz_isco_code' => $row['cz_isco_code'] ?? null,
            'work_place' => $row['work_place'] ?? null,
            'tax_residence' => $one(
                'SELECT residence FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1',
                [$this->supplierId, $employeeId],
            ),
            'declaration' => $one(
                'SELECT status FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1',
                [$this->supplierId, $employeeId],
            ),
            'a1_profile' => (int) $one(
                'SELECT COUNT(*) FROM payroll_registration_a1_profiles WHERE supplier_id = ? AND employment_id = ?',
                [$this->supplierId, $employmentId],
            ),
        ];
    }
}
