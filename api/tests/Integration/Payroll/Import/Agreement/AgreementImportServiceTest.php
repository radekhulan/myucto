<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Agreement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportColumns;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportService;
use MyInvoice\Service\Payroll\Import\Agreement\AgreementImportTemplate;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Import dohod bez docházky: existující osobě jen nový vztah, nové osobě
 * karta i vztah, víc dohod jedné osoby v souboru, shoda bez rodného čísla
 * jen s potvrzením a stejný soubor podruhé nic nezaloží.
 */
#[Group('integration')]
final class AgreementImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const HEADERS = [
        'A' => 'Příjmení', 'B' => 'Jméno', 'C' => 'Rodné číslo', 'D' => 'Datum narození', 'E' => 'Druh vztahu',
        'F' => 'Nástup', 'G' => 'Konec dohody', 'H' => 'Hodinová sazba (Kč/h)', 'I' => 'Zdravotní pojišťovna',
        'J' => 'Osobní číslo', 'K' => 'CZ-ISCO',
    ];

    private Connection $db;
    private AgreementImportService $service;
    private PayrollPersonCreateService $people;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AgreementImportService::class);
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

    public function testTemplateRoundTripCreatesPersonsAndSecondImportCreatesNothing(): void
    {
        $template = (new AgreementImportTemplate())->build();
        $file = AttendanceFixture::file(AgreementImportTemplate::FILENAME, $template['bytes']);

        $preview = $this->service->preview($this->supplierId, [$file]);
        self::assertSame(2, $preview['summary']['persons'], json_encode($preview, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(2, $preview['summary']['new_persons']);
        self::assertSame(2, $preview['summary']['create']);
        self::assertSame(0, $preview['summary']['errors'], json_encode($preview, JSON_UNESCAPED_UNICODE) ?: '');
        $json = json_encode($preview, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringNotContainsString('0153150008', $json, 'Rodné číslo nesmí jít klientovi celé.');
        self::assertStringNotContainsString('1000000005', $json);

        $applied = $this->service->apply($this->supplierId, [$file], [], null, $this->userId, null, null);
        self::assertSame(['created' => 2, 'existing' => 0, 'skipped' => 0, 'failed' => 0], $applied['counts'], json_encode($applied, JSON_UNESCAPED_UNICODE) ?: '');
        $byName = array_column($applied['results'], null, 'display_name');
        self::assertSame(
            [['dpp', '2026-12-01', '2026-12-31']],
            $this->employments((int) $byName['Ukázková Eva']['employee_id']),
        );
        self::assertSame(
            [['dpc', '2026-12-15', null]],
            $this->employments((int) $byName['Příkladný Adam']['employee_id']),
        );

        $again = $this->service->preview($this->supplierId, [$file]);
        self::assertSame(0, $again['summary']['new_persons']);
        self::assertSame(2, $again['summary']['exists'], json_encode($again, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(0, $again['summary']['create']);
        $second = $this->service->apply($this->supplierId, [$file], [], null, $this->userId, null, null);
        self::assertSame(['created' => 0, 'existing' => 2, 'skipped' => 0, 'failed' => 0], $second['counts']);
        self::assertSame(2, $this->rowCount('payroll_employees'));
        self::assertSame(2, $this->rowCount('payroll_employments'));
    }

    public function testExistingPersonGetsOnlyNewAgreementsAndSeveralRowsOfOnePerson(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('1999-04-20', 'female', 3);
        $existing = (int) $this->people->create($this->supplierId, [
            'full_name' => 'Jana Stálá',
            'first_name' => 'Jana',
            'last_name' => 'Stálá',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-10-01',
            'fixed_term_end_on' => '2026-10-31',
            'birth_number' => $birthNumber,
        ], $this->userId, null, null)['id'];
        $newBirthNumber = AttendanceFixture::birthNumber('2002-02-02', 'male', 4);

        $file = $this->file([
            ['A' => 'Stálá', 'B' => 'Jana', 'C' => $birthNumber, 'E' => 'DPP', 'F' => '1. 10. 2026', 'G' => '31. 10. 2026'],
            ['A' => 'Stálá', 'B' => 'Jana', 'C' => $birthNumber, 'E' => 'DPP', 'F' => '1. 12. 2026', 'G' => '31. 12. 2026', 'H' => '180'],
            ['A' => 'Nový', 'B' => 'Petr', 'C' => $newBirthNumber, 'E' => 'DPP', 'F' => '1. 12. 2026', 'G' => '15. 12. 2026', 'I' => '111'],
            ['A' => 'Nový', 'B' => 'Petr', 'C' => $newBirthNumber, 'E' => 'DPČ', 'F' => '1. 12. 2026', 'I' => '111'],
            ['A' => 'Nový', 'B' => 'Petr', 'C' => $newBirthNumber, 'E' => 'DPP', 'F' => '5. 12. 2026', 'G' => '10. 12. 2026'],
            ['A' => 'Chybná', 'B' => 'Dohoda', 'E' => 'smlouva', 'F' => 'zítra'],
        ]);
        $preview = $this->service->preview($this->supplierId, [$file]);
        $persons = array_column($preview['persons'], null, 'display_name');
        self::assertSame($existing, $persons['Stálá Jana']['existing_person']['employee_id']);
        self::assertSame('birth_number', $persons['Stálá Jana']['existing_person']['matched_by']);
        self::assertSame(['exists', 'create'], array_column($persons['Stálá Jana']['agreements'], 'status'));
        self::assertNull($persons['Nový Petr']['existing_person']);
        self::assertSame(['create', 'create', 'duplicate'], array_column($persons['Nový Petr']['agreements'], 'status'));
        self::assertSame('error', $persons['Chybná Dohoda']['agreements'][0]['status']);
        self::assertCount(2, $persons['Chybná Dohoda']['agreements'][0]['errors']);

        $applied = $this->service->apply($this->supplierId, [$file], [], null, $this->userId, null, null);
        self::assertSame(['created' => 3, 'existing' => 1, 'skipped' => 2, 'failed' => 0], $applied['counts'], json_encode($applied, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(
            [['dpp', '2026-10-01', '2026-10-31'], ['dpp', '2026-12-01', '2026-12-31']],
            $this->employments($existing),
        );
        self::assertSame(2, $this->rowCount('payroll_employees'), 'Existující osoba nesmí dostat druhou kartu.');
        $petr = null;
        foreach ($applied['results'] as $result) {
            if ($result['display_name'] === 'Nový Petr' && $result['outcome'] === 'created') {
                $petr ??= (int) $result['employee_id'];
                self::assertSame($petr, (int) $result['employee_id'], 'Dvě dohody jedné nové osoby patří jedné kartě.');
            }
        }
        self::assertSame([['dpp', '2026-12-01', '2026-12-15'], ['dpc', '2026-12-01', null]], $this->employments((int) $petr));
    }

    public function testMatchByNameAndBirthDateRequiresConfirmation(): void
    {
        $employeeId = (int) $this->people->create($this->supplierId, [
            'full_name' => 'Olga Cizinka',
            'first_name' => 'Olga',
            'last_name' => 'Cizinka',
            'relation_type' => 'dpp',
            'planned_start_on' => '2026-09-01',
            'fixed_term_end_on' => '2026-09-30',
            'birth_date' => '1995-07-07',
        ], $this->userId, null, null)['id'];
        $file = $this->file([
            ['A' => 'Cizinka', 'B' => 'Olga', 'D' => '7. 7. 1995', 'E' => 'DPP', 'F' => '1. 12. 2026', 'G' => '31. 12. 2026'],
        ]);
        $preview = $this->service->preview($this->supplierId, [$file]);
        $person = $preview['persons'][0];
        self::assertTrue($person['existing_person']['requires_confirmation']);
        self::assertSame(1, $preview['summary']['confirm']);

        $unconfirmed = $this->service->apply($this->supplierId, [$file], [], null, $this->userId, null, null);
        self::assertSame(1, $unconfirmed['counts']['skipped']);
        self::assertSame(1, $this->rowCount('payroll_employments'));

        $confirmed = $this->service->apply($this->supplierId, [$file], [$person['key'] => $employeeId], [$person['key']], $this->userId, null, null);
        self::assertSame(1, $confirmed['counts']['created'], json_encode($confirmed, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($employeeId, (int) $confirmed['results'][0]['employee_id']);
        self::assertSame(1, $this->rowCount('payroll_employees'));
    }

    public function testHeaderIsRecognisedOnlyWithEnoughColumns(): void
    {
        self::assertNull(AgreementImportColumns::header([1 => [1 => 'Poznámka', 2 => 'Jméno']]));
        self::assertSame(
            ['row' => 2, 'columns' => [1 => 'last_name', 2 => 'first_name', 3 => 'relation_label', 4 => 'start_date']],
            AgreementImportColumns::header([1 => [1 => 'Seznam dohod'], 2 => [1 => 'Příjmení', 2 => 'Jméno', 3 => 'Druh vztahu', 4 => 'Nástup']]),
        );
    }

    /**
     * @param list<array<string,string>> $rows
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function file(array $rows): array
    {
        $sheetRows = [1 => self::HEADERS];
        foreach ($rows as $index => $row) {
            $sheetRows[$index + 2] = $row;
        }

        return AttendanceFixture::file('dohody.xlsx', AttendanceFixture::xlsx([
            AgreementImportColumns::SHEET => ['rows' => $sheetRows],
        ]));
    }

    /** @return list<array{0:string,1:string,2:?string}> */
    private function employments(int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type, COALESCE(actual_start_date, start_date) AS start_on, end_date
               FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY start_on, relation_type',
        );
        $stmt->execute([$this->supplierId, $employeeId]);

        return array_map(
            static fn (array $row): array => [(string) $row['relation_type'], (string) $row['start_on'], $row['end_date'] === null ? null : (string) $row['end_date']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
