<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Action\Payroll\PayrollAttendanceImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoTemplate;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Šablona XLSX Vzoru MyÚčto staženou přes endpoint naimportuje firma bez
 * evidence: náhled dá osoby z šablony, obě se založí (DPP i DPČ) a použití
 * dávky založí hodiny, složky a srážku.
 */
#[Group('integration')]
final class AttendanceMyUctoRoundTripTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;

    private Connection $db;
    private ContainerInterface $container;
    private AttendanceImportService $service;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $this->container->get(Connection::class);
        $service = $this->container->get(AttendanceImportService::class);
        if (!$db instanceof Connection || !$service instanceof AttendanceImportService) {
            throw new \RuntimeException('Služba importu docházky není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        if (!$db->hasColumn('payroll_import_profiles', 'sample_key')) {
            self::markTestSkipped('Chybí sloupec payroll_import_profiles.sample_key.');
        }
        $pdo = $db->pdo();
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
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:attendance-myucto")',
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
        // Odměna je složka výchozího číselníku; firma ji má od zapnutí mezd.
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, "ODMENA", "Odměna", "bonus", "monetary", "one_off", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)',
        )->execute([$this->supplierId]);
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

    public function testTemplateImportsThroughMyUctoSampleIncludingNewDppPerson(): void
    {
        $file = AttendanceFixture::file(AttendanceMyUctoTemplate::FILENAME, $this->downloadTemplate());
        $profile = $this->service->restoreSampleProfile($this->supplierId, $this->userId, 'myucto')['profile'];
        $profileId = (int) $profile['id'];

        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $profileId);
        self::assertSame($profileId, $preview['profile']['id']);
        self::assertSame(2, $preview['summary']['persons']);
        self::assertSame(2, $preview['summary']['not_found']);
        $persons = array_column($preview['persons'], null, 'key');
        self::assertSame('DPP', $persons['eva ukazkova']['relation_label']);
        self::assertSame('2026-06-01', $persons['eva ukazkova']['start_on']);
        self::assertSame('DPČ', $persons['adam prikladny']['relation_label']);
        self::assertSame('180', $persons['eva ukazkova']['hourly_rate']);
        self::assertSame('2026-12-31', $persons['eva ukazkova']['agreed_end_on']);
        self::assertNull($persons['adam prikladny']['agreed_end_on']);
        self::assertIsString($persons['eva ukazkova']['bank_account_masked']);
        self::assertStringNotContainsString('1000000005', $persons['eva ukazkova']['bank_account_masked']);
        self::assertArrayNotHasKey('_bank_account', $persons['eva ukazkova'], 'Číslo účtu klient nedostane celé.');
        self::assertNull($persons['adam prikladny']['bank_account_masked']);
        $checks = array_column($preview['component_checks'], 'status', 'component_code');
        ksort($checks);
        self::assertSame(['MZDA_HODINOVA_DOCH' => 'will_create', 'ODMENA' => 'ok', 'ODMENA_MIMORADNA' => 'will_create'], $checks);

        // Druh vztahu odhadne klient z „Druhu vztahu" v podkladech; tady je zadaný přímo.
        $created = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'eva ukazkova',
                'full_name' => 'Eva Ukázková',
                'first_name' => 'Eva',
                'last_name' => 'Ukázková',
                'relation_type' => 'dpp',
                'weekly_hours' => null,
                'planned_start_on' => $persons['eva ukazkova']['start_on'],
                'hourly_rate' => $persons['eva ukazkova']['hourly_rate'],
                'fixed_term_end_on' => $persons['eva ukazkova']['end_date'],
                'personal_number' => 'B001',
                'activate' => true,
            ],
            [
                'person_key' => 'adam prikladny',
                'full_name' => 'Adam Příkladný',
                'first_name' => 'Adam',
                'last_name' => 'Příkladný',
                'relation_type' => 'dpc',
                'weekly_hours' => $persons['adam prikladny']['weekly_hours'],
                'planned_start_on' => $persons['adam prikladny']['start_on'],
                'personal_number' => 'B002',
                'activate' => true,
            ],
        ], $this->userId, null, null, [$file], null, $profileId);
        $results = array_column($created['results'], null, 'person_key');
        self::assertSame('created', $results['eva ukazkova']['status'], (string) $results['eva ukazkova']['message']);
        self::assertSame('created', $results['adam prikladny']['status'], (string) $results['adam prikladny']['message']);
        $eva = (int) $results['eva ukazkova']['employment_id'];
        $adam = (int) $results['adam prikladny']['employment_id'];
        self::assertSame(
            ['relation_type' => 'dpp', 'status' => 'active', 'actual_start_date' => '2026-06-01', 'code' => 'B001'],
            $this->employment($eva),
        );
        self::assertSame(
            ['relation_type' => 'dpc', 'status' => 'active', 'actual_start_date' => '2026-06-15', 'code' => 'B002'],
            $this->employment($adam),
        );

        // Sazba, konec a účet z šablony: sazba se u Adama nepředala, účet má jen Eva.
        self::assertSame(['probable' => 18_000, 'fixed_end' => '2026-12-31', 'accounts' => 1], $this->agreed($eva));
        self::assertSame(['probable' => null, 'fixed_end' => null, 'accounts' => 0], $this->agreed($adam));

        $again = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $profileId);
        self::assertSame(2, $again['summary']['matched']);

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
            profileId: $profileId,
            createDeductions: true,
        );
        self::assertSame(2, $result['batch']['person_count']);
        self::assertSame([], $result['inputs']['errors']);
        self::assertSame(
            [
                "{$eva}:MZDA_HODINOVA_DOCH" => 765_000,
                "{$eva}:ODMENA" => 100_000,
                "{$adam}:MZDA_HODINOVA_DOCH" => 1_080_000,
                "{$adam}:ODMENA_MIMORADNA" => 200_000,
            ],
            $this->inputs(),
        );
        self::assertSame(1, $result['deductions']['created']);

        // Existující osobě import sazbu, konec ani účet nemění.
        $changed = AttendanceFixture::file('zmena.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => ['A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Hodinová sazba (Kč/h)', 'D' => 'Konec dohody', 'E' => 'Číslo účtu', 'F' => 'Odpracováno (hod)'],
                2 => ['A' => 'B001', 'B' => 'Ukázková Eva', 'C' => 250, 'D' => '30. 9. 2026', 'E' => '19-2000145399/0800', 'F' => 10],
            ]],
        ]));
        $changedPreview = $this->service->preview($this->supplierId, self::PERIOD, [$changed], null, $profileId);
        self::assertSame('linked', $changedPreview['persons'][0]['match']['status']);
        $this->service->apply($this->supplierId, self::PERIOD, [$changed], null, [], false, true, $this->userId, profileId: $profileId);
        self::assertSame(['probable' => 18_000, 'fixed_end' => '2026-12-31', 'accounts' => 1], $this->agreed($eva));
    }

    private function downloadTemplate(): string
    {
        $action = $this->container->get(PayrollAttendanceImportAction::class);
        self::assertInstanceOf(PayrollAttendanceImportAction::class, $action);
        $response = $action->template(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/payroll/imports/attendance/template')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session'),
            new Response(),
        );
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertSame(AttendanceMyUctoTemplate::MIME, $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(AttendanceMyUctoTemplate::FILENAME, $response->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('PK', $body);

        return $body;
    }

    /** @return array{relation_type:string,status:string,actual_start_date:?string,code:string} */
    private function employment(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type, status, actual_start_date, code FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'relation_type' => (string) $row['relation_type'],
            'status' => (string) $row['status'],
            'actual_start_date' => $row['actual_start_date'] === null ? null : (string) $row['actual_start_date'],
            'code' => (string) $row['code'],
        ];
    }

    /** @return array{probable:?int,fixed_end:?string,accounts:int} */
    private function agreed(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT terms.probable_hourly_earning_minor, terms.fixed_term_end_on,
                    (SELECT COUNT(*) FROM payroll_person_accounts account
                      WHERE account.supplier_id = employment.supplier_id
                        AND account.employee_id = employment.employee_id) AS accounts
               FROM payroll_employments employment
               JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id AND terms.employment_id = employment.id
              WHERE employment.supplier_id = ? AND employment.id = ?
              ORDER BY terms.id DESC
              LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'probable' => $row['probable_hourly_earning_minor'] === null ? null : (int) $row['probable_hourly_earning_minor'],
            'fixed_end' => $row['fixed_term_end_on'] === null ? null : (string) $row['fixed_term_end_on'],
            'accounts' => (int) $row['accounts'],
        ];
    }

    /** @return array<string,int> vztah:složka => částka */
    private function inputs(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CONCAT(i.employment_id, ":", c.code) AS input_key, i.amount_minor
               FROM payroll_inputs i
               JOIN payroll_component_definitions c ON c.supplier_id = i.supplier_id AND c.id = i.component_id
              WHERE i.supplier_id = ?
              ORDER BY i.employment_id, c.code',
        );
        $stmt->execute([$this->supplierId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
