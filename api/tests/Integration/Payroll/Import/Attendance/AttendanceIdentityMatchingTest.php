<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bezpečné párování osob v importu docházky (agentura s DPP, data syntetická):
 * rodné číslo má přednost před osobním číslem a kódem vztahu, osobní číslo
 * sdílené víc osobami se k párování nepoužije, rozpor rodného a osobního čísla
 * řádek zastaví, rodné číslo uložené v Excelu jako číslo se doplní a bez
 * rodného čísla se existující osoba nabídne podle jména a data narození
 * k potvrzení. Odpracované dny z podkladů jdou do souhrnu pracovního měsíce.
 */
#[Group('integration')]
final class AttendanceIdentityMatchingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-04';

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

    public function testSharedPersonalNumberIsNotUsedAndBirthNumberDecides(): void
    {
        $alfa = $this->hire('Alfa', 'První', '1', AttendanceFixture::birthNumber('1990-05-01', 'female', 1));
        $beta = $this->hire('Beta', 'Druhá', '1', AttendanceFixture::birthNumber('1991-06-02', 'female', 2));
        $file = $this->file([
            ['1', 'První Alfa', $this->digits(AttendanceFixture::birthNumber('1990-05-01', 'female', 1)), 8],
            ['1', 'Druhá Beta', $this->digits(AttendanceFixture::birthNumber('1991-06-02', 'female', 2)), 6],
            ['1', 'Nová Gama', null, 4],
        ]);

        $persons = $this->preview($file);
        self::assertSame(['matched', 'birth_number', $alfa['employment_id']], $this->matchOf($persons['alfa prvni']));
        self::assertSame(['matched', 'birth_number', $beta['employment_id']], $this->matchOf($persons['beta druha']));
        self::assertSame('not_found', $persons['gama nova']['match']['status'], 'Sdílené osobní číslo nesmí najít cizí vztah.');
        foreach ($persons as $person) {
            self::assertStringContainsString('Osobní číslo „1“ má ve firmě nebo v podkladech víc osob', implode(' ', $person['warnings']));
        }

        $this->service->apply($this->supplierId, self::PERIOD, [$file], null, [], true, false, $this->userId, profileId: $this->profileId);
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_import_links WHERE supplier_id = ? AND key_kind = "personal_number"',
        ), 'Vazba podle sdíleného osobního čísla se nesmí uložit.');
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_import_links WHERE supplier_id = ? AND key_kind = "name"'));
    }

    public function testBirthNumberWinsOverStoredPersonalNumberLinkAndConflictStopsTheRow(): void
    {
        $alfaNumber = AttendanceFixture::birthNumber('1990-05-01', 'female', 1);
        $alfa = $this->hire('Alfa', 'První', 'A1', $alfaNumber);
        $gama = $this->hire('Gama', 'Třetí', 'G7', AttendanceFixture::birthNumber('1993-07-03', 'female', 3));
        $newcomer = AttendanceFixture::birthNumber('2002-02-14', 'male', 4);
        // Nový brigádník, jehož číslo v podkladech je náhodou kódem vztahu Gamy (R2).
        $file = $this->file([['G7', 'Nový Brigádník', $this->digits($newcomer), 5]]);
        // Rodné číslo Alfy, osobní číslo Gamy: rozpor (R1).
        $mixed = $this->file([['G7', 'První Alfa', $this->digits($alfaNumber), 7]]);

        foreach ([[$file, 'brigadnik novy'], [$mixed, 'alfa prvni']] as [$source, $key]) {
            $person = $this->preview($source)[$key];
            self::assertSame('conflict', $person['match']['status'], $key);
            self::assertNull($person['match']['employment_id']);
            self::assertStringContainsString('Rozpor v podkladech', (string) $person['match']['conflict']);
            self::assertSame([$person['match']['conflict']], $person['errors']);
            self::assertNull($person['existing_person']);

            $result = $this->service->apply($this->supplierId, self::PERIOD, [$source], null, [], true, false, $this->userId, profileId: $this->profileId);
            self::assertCount(1, $result['skipped_persons']);
            self::assertStringContainsString('Rozpor v podkladech', $result['skipped_persons'][0]['reason']);
        }
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM payroll_import_links WHERE supplier_id = ?'));

        $created = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => 'brigadnik novy', 'full_name' => 'Brigádník Nový', 'first_name' => 'Brigádník', 'last_name' => 'Nový',
            'relation_type' => 'dpp', 'planned_start_on' => '2026-04-01', 'personal_number' => 'G7', 'activate' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId)['results'][0];
        self::assertSame('failed', $created['status']);
        self::assertStringContainsString('Rozpor v podkladech', (string) $created['message']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?'));

        // Uložená vazba podle osobního čísla už RČ nepřebije: RČ Alfy s jejím číslem A1, vazba A1 → Gama.
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_import_links (supplier_id, source_system, key_kind, external_key, employee_id, employment_id)
             VALUES (?, "attendance", "personal_number", "A1", ?, ?)',
        )->execute([$this->supplierId, $gama['employee_id'], $gama['employment_id']]);
        $linked = $this->preview($this->file([['A1', 'První Alfa', $this->digits($alfaNumber), 7]]));
        self::assertSame(['matched', 'birth_number', $alfa['employment_id']], $this->matchOf($linked['alfa prvni']));
        $plain = $this->preview($this->file([[null, 'První Alfa', $this->digits($alfaNumber), 7]]));
        self::assertSame(['matched', 'birth_number', $alfa['employment_id']], $this->matchOf($plain['alfa prvni']));
    }

    public function testBirthNumberStoredAsExcelNumberIsPaddedAndMatched(): void
    {
        $birthNumber = AttendanceFixture::birthNumber('2001-03-01', 'male', 5);
        $epsilon = $this->hire('Epsilon', 'Pátý', 'E5', $birthNumber);
        $asNumber = (int) str_replace('/', '', $birthNumber);
        self::assertSame(9, strlen((string) $asNumber));

        $persons = $this->preview($this->file([[null, 'Pátý Epsilon', $asNumber, 9]]));

        self::assertSame(['matched', 'birth_number', $epsilon['employment_id']], $this->matchOf($persons['epsilon paty']));
        self::assertContains(AttendanceBirthNumber::PADDED_WARNING, $persons['epsilon paty']['warnings']);
        self::assertNotNull($persons['epsilon paty']['birth_number_masked']);
    }

    public function testBirthNumberContradictingBirthDateIsRowError(): void
    {
        $file = $this->file([[null, 'Rozporný Ota', $this->digits(AttendanceFixture::birthNumber('1995-01-10', 'male', 6)), 3, '1995-01-11']]);

        $person = $this->preview($file)['ota rozporny'];

        self::assertSame('not_found', $person['match']['status']);
        self::assertStringContainsString('neodpovídá datu narození 11. 1. 1995', implode(' ', $person['errors']));
    }

    public function testWithoutBirthNumberNameAndBirthDateIsOfferedForConfirmation(): void
    {
        $delta = $this->hire('Delta', 'Čtvrtá', 'D4', null, '2001-03-01', '2026-01-05', '2026-01-31');
        $this->hire('Delta', 'Čtvrtá', 'D5', null, '1999-09-09', '2026-01-05', '2026-01-31');
        $file = $this->file([[null, 'Čtvrtá Delta', null, 6, '2001-03-01']]);

        $person = $this->preview($file)['ctvrta delta'];
        self::assertSame('not_found', $person['match']['status']);
        self::assertSame($delta['employee_id'], $person['existing_person']['employee_id'] ?? null);
        self::assertSame('name_birth_date', $person['existing_person']['matched_by']);
        self::assertTrue($person['existing_person']['requires_confirmation']);
        self::assertStringContainsString('se stejným jménem a datem narození', implode(' ', $person['warnings']));

        $item = [
            'person_key' => 'ctvrta delta', 'full_name' => 'Delta Čtvrtá', 'first_name' => 'Delta', 'last_name' => 'Čtvrtá',
            'relation_type' => 'dpp', 'planned_start_on' => '2026-04-01', 'personal_number' => null, 'activate' => true,
        ];
        $create = fn (array $extra): array => $this->service->persons(
            $this->supplierId, self::PERIOD, [$item + $extra], $this->userId, null, null, [$file], null, $this->profileId,
        )['results'][0];

        $unconfirmed = $create([]);
        self::assertSame('failed', $unconfirmed['status']);
        self::assertStringContainsString('Potvrďte, že jde o ni', (string) $unconfirmed['message']);
        self::assertSame('failed', $create(['confirm_existing_person' => $delta['employee_id'] + 999])['status']);

        $confirmed = $create(['confirm_existing_person' => $delta['employee_id']]);
        self::assertSame('employment_created', $confirmed['status'], (string) $confirmed['message']);
        self::assertSame($delta['employee_id'], $confirmed['employee_id']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?'));
    }

    public function testExplicitNewPersonIgnoresNameAndBirthDateSuggestion(): void
    {
        $this->hire('Delta', 'Čtvrtá', 'D4', null, '2001-03-01', '2026-01-05', '2026-01-31');
        $file = $this->file([[null, 'Čtvrtá Delta', null, 6, '2001-03-01']]);

        $result = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => 'ctvrta delta', 'full_name' => 'Delta Čtvrtá', 'first_name' => 'Delta', 'last_name' => 'Čtvrtá',
            'relation_type' => 'dpp', 'planned_start_on' => '2026-04-01', 'personal_number' => null, 'activate' => true,
            'create_new_person' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId)['results'][0];

        self::assertSame('created', $result['status'], (string) $result['message']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?'));
    }

    public function testWorkedDaysGoToBatchAndMonthSummary(): void
    {
        $alfa = $this->hire('Alfa', 'První', 'A1', AttendanceFixture::birthNumber('1990-05-01', 'female', 1));
        $file = $this->file([['A1', 'První Alfa', null, 40, null, 6], ['X9', 'Přespříliš Dnů', null, 8, null, 31]]);

        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $persons = array_column($preview['persons'], null, 'key');
        self::assertSame(6, $persons['alfa prvni']['worked_days']);
        self::assertNull($persons['dnu presprilis']['worked_days']);
        self::assertStringContainsString('převyšují počet dnů měsíce (30)', implode(' ', $persons['dnu presprilis']['warnings']));
        self::assertSame(1, $preview['summary']['worked_days']);

        $result = $this->service->apply(
            $this->supplierId, self::PERIOD, [$file], null, [], false, false, $this->userId,
            profileId: $this->profileId, writeTimeSummary: true,
        );
        self::assertSame(1, $result['time_summary']['written']);
        self::assertSame(6000, $this->scalar(
            'SELECT quantity_millihours FROM payroll_attendance_import_rows WHERE supplier_id = ? AND meaning = "worked_days"',
        ));
        $stmt = $this->db->pdo()->prepare(
            'SELECT worked_days FROM payroll_time_month_import_summaries WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $alfa['employment_id']]);
        self::assertSame(6, (int) $stmt->fetchColumn());
    }

    public function testTemplateCarriesWorkedDaysColumn(): void
    {
        $headers = array_column(AttendanceMyUctoSampleProfile::columns(), 'meaning', 'header');
        self::assertSame('worked_days', $headers['Odpracované dny'] ?? null);
    }

    /**
     * @param list<array{0:?string,1:string,2:string|int|null,3:int|float,4?:?string,5?:?int}> $rows
     *        osobní číslo, jméno, RČ (text nebo číslo), hodiny, datum narození, odpracované dny
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function file(array $rows): array
    {
        $sheetRows = [1 => [
            'A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Rodné číslo', 'D' => 'Druh vztahu',
            'E' => 'Odpracováno (hod)', 'F' => 'Datum narození', 'G' => 'Odpracované dny',
        ]];
        foreach ($rows as $index => $row) {
            $sheetRows[$index + 2] = [
                'A' => $row[0], 'B' => $row[1], 'C' => $row[2], 'D' => 'DPP', 'E' => $row[3],
                'F' => isset($row[4]) ? (string) $row[4] : null, 'G' => $row[5] ?? null,
            ];
        }

        return AttendanceFixture::file('duben-' . md5(serialize($rows)) . '.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => $sheetRows],
        ]));
    }

    /**
     * @param array{name:string,content:string,sha256:string,extension:string} $file
     * @return array<string,array<string,mixed>>
     */
    private function preview(array $file): array
    {
        return array_column(
            $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId)['persons'],
            null,
            'key',
        );
    }

    /**
     * @param array<string,mixed> $person
     * @return array{0:string,1:?string,2:?int}
     */
    private function matchOf(array $person): array
    {
        return [$person['match']['status'], $person['match']['matched_by'], $person['match']['employment_id']];
    }

    private function digits(string $birthNumber): string
    {
        return str_replace('/', '', $birthNumber);
    }

    /** @return array{employee_id:int,employment_id:int} */
    private function hire(
        string $first,
        string $last,
        string $code,
        ?string $birthNumber,
        ?string $birthDate = null,
        string $start = '2026-01-05',
        ?string $end = null,
    ): array {
        $person = $this->people->create($this->supplierId, array_filter([
            'full_name' => "{$first} {$last}",
            'first_name' => $first,
            'last_name' => $last,
            'relation_type' => 'dpp',
            'planned_start_on' => $start,
            'fixed_term_end_on' => $end,
            'employment_code' => $code,
            'birth_number' => $birthNumber,
            'birth_date' => $birthDate,
        ], static fn (mixed $value): bool => $value !== null), $this->userId, null, null);
        $employeeId = (int) $person['id'];
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "active", actual_start_date = start_date, end_date = ?
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$end, $this->supplierId, $employeeId]);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ?');
        $stmt->execute([$this->supplierId, $employeeId]);

        return ['employee_id' => $employeeId, 'employment_id' => (int) $stmt->fetchColumn()];
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
