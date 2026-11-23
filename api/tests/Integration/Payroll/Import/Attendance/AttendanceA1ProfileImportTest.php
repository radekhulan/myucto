<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1DefaultsService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Šablona se sloupci přihlášky A1 (režim práce, nepřetržitý provoz, obec
 * pracoviště, pozice, vedoucí pozice, rodné příjmení, místo narození, číslo
 * domu zvlášť): nová osoba má po založení ověřený profil A1 bez ručního
 * vyplňování karty.
 */
#[Group('integration')]
final class AttendanceA1ProfileImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const HEADERS = [
        'A' => 'Příjmení a jméno', 'B' => 'Rodné číslo', 'C' => 'Druh vztahu', 'D' => 'Nástup',
        'E' => 'Zdravotní pojišťovna', 'F' => 'Ulice', 'G' => 'Číslo domu', 'H' => 'Obec', 'I' => 'PSČ',
        'J' => 'Státní občanství', 'K' => 'CZ-ISCO', 'L' => 'Místo výkonu práce', 'M' => 'Rodné příjmení',
        'N' => 'Místo narození', 'O' => 'Stát narození', 'P' => 'Režim práce', 'Q' => 'Nepřetržitý provoz',
        'R' => 'Název pozice', 'S' => 'Vedoucí pozice', 'T' => 'Konec dohody',
    ];

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollRegistrationIdentityService $identities;
    private PayrollRegistrationA1DefaultsService $defaults;
    private int $supplierId;
    private int $userId;
    private int $profileId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AttendanceImportService::class);
        $this->identities = $container->get(PayrollRegistrationIdentityService::class);
        $this->defaults = $container->get(PayrollRegistrationA1DefaultsService::class);
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

    public function testNewDppPersonHasVerifiedA1ProfileAfterImport(): void
    {
        $file = AttendanceFixture::file('a1.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => self::HEADERS,
                2 => [
                    'A' => 'Registrační Hana', 'B' => '0153150008', 'C' => 'DPP', 'D' => '1. 6. 2026',
                    'E' => '111', 'F' => 'Ukázková', 'G' => '12/3', 'H' => 'Praha', 'I' => '110 00',
                    'J' => 'CZ', 'K' => '43111', 'L' => 'Praha', 'M' => 'Nováková', 'N' => 'Brno', 'O' => 'CZ',
                    'P' => 'jednosměnný', 'Q' => 'ne', 'R' => 'Promotérka', 'S' => 'ne', 'T' => '30. 6. 2026',
                ],
            ]],
        ]));
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertSame([], $person['errors'], json_encode($person, JSON_UNESCAPED_UNICODE) ?: '');
        $keys = array_column($person['registration'], 'key');
        foreach (['birth', 'work_mode_code', 'position_name', 'workplace_city', 'continuous_operation', 'leadership', 'house_number'] as $key) {
            self::assertContains($key, $keys);
        }

        $created = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => $person['key'],
            'full_name' => 'Hana Registrační',
            'first_name' => 'Hana',
            'last_name' => 'Registrační',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-06-01',
            'activate' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId);
        $result = $created['results'][0];
        self::assertSame('created', $result['status'], (string) $result['message']);

        $view = $this->identities->a1ProfileView($this->supplierId, (int) $result['employment_id']);
        self::assertIsArray($view['profile'], (string) $result['message']);
        self::assertSame(
            'verified',
            $view['profile']['status'],
            json_encode([$result['message'], $view['profile']['problems'] ?? null], JSON_UNESCAPED_UNICODE) ?: '',
        );
        $employment = $view['profile']['employment'];
        self::assertSame('1', $employment['work_mode_code']);
        self::assertFalse($employment['continuous_operation']);
        self::assertSame('Promotérka', $employment['position_name']);
        self::assertFalse($employment['leadership']);
        self::assertSame('Praha', $employment['workplace_city']);
        self::assertSame('12', $view['profile']['permanent_address']['house_number']);
        self::assertSame('3', $view['profile']['permanent_address']['orientation_number']);
    }

    /**
     * Podklady bez sloupců A1 a s číslem domu v ulici: hromadné výchozí údaje
     * A1 (režim, provoz, pozice, obec pracoviště) a rozdělení čísla domu
     * dovedou profil do ověřeného stavu; vyplněné hodnoty bez přepsání zůstanou.
     */
    public function testBulkDefaultsCompleteProfilesWithoutA1Columns(): void
    {
        $rows = [1 => ['A' => 'Příjmení a jméno', 'B' => 'Rodné číslo', 'C' => 'Druh vztahu', 'D' => 'Nástup',
            'E' => 'Zdravotní pojišťovna', 'F' => 'Ulice', 'H' => 'Obec', 'I' => 'PSČ', 'J' => 'Státní občanství',
            'K' => 'CZ-ISCO', 'L' => 'Místo výkonu práce', 'M' => 'Rodné příjmení', 'N' => 'Místo narození', 'O' => 'Stát narození',
            'R' => 'Název pozice']];
        $people = [['Brigádník Jan', '9901010009', ''], ['Registrační Hana', '0153150008', 'Hosteska']];
        foreach ($people as $index => [$name, $birthNumber, $position]) {
            $rows[$index + 2] = [
                'A' => $name, 'B' => $birthNumber, 'C' => 'DPP', 'D' => '1. 6. 2026', 'E' => '111',
                'F' => 'Dlouhá 12/3', 'H' => 'Praha', 'I' => '110 00', 'J' => 'CZ', 'K' => '43111', 'L' => '554782',
                'M' => 'Rodná', 'N' => 'Brno', 'O' => 'CZ', 'R' => $position,
            ];
        }
        $file = AttendanceFixture::file('bez-a1.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => $rows],
        ]));
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $items = [];
        foreach ($preview['persons'] as $person) {
            [$last, $first] = explode(' ', $person['display_name']);
            $items[] = [
                'person_key' => $person['key'], 'full_name' => "{$first} {$last}", 'first_name' => $first,
                'last_name' => $last, 'relation_type' => 'dpp', 'planned_start_on' => '2026-06-01', 'activate' => true,
            ];
        }
        $created = $this->service->persons($this->supplierId, self::PERIOD, $items, $this->userId, null, null, [$file], null, $this->profileId);
        $ids = [];
        foreach ($created['results'] as $result) {
            self::assertSame('created', $result['status'], (string) $result['message']);
            $ids[] = (int) $result['employment_id'];
        }

        $result = $this->defaults->apply($this->supplierId, $ids, [
            'work_mode_code' => '1',
            'continuous_operation' => false,
            'position_name' => 'Promotér',
            'leadership' => false,
            'workplace_city' => 'Praha',
        ], false, true, $this->userId);
        self::assertSame(['verified' => 2, 'draft' => 0, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        $positions = [];
        foreach ($ids as $id) {
            $profile = $this->identities->a1ProfileView($this->supplierId, $id)['profile'];
            self::assertSame('Dlouhá', $profile['permanent_address']['street']);
            self::assertSame('12', $profile['permanent_address']['house_number']);
            self::assertSame('3', $profile['permanent_address']['orientation_number']);
            $positions[] = $profile['employment']['position_name'];
        }
        sort($positions);
        self::assertSame(['Hosteska', 'Promotér'], $positions, 'Pozice z podkladů se bez přepsání nemění.');

        $again = $this->defaults->apply($this->supplierId, $ids, ['position_name' => 'Promotér'], true, false, $this->userId);
        self::assertSame(2, $again['counts']['verified']);
        self::assertSame('Promotér', $this->identities->a1ProfileView($this->supplierId, $ids[1])['profile']['employment']['position_name']);
    }
}
