<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollTestContainer;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura importuje brigádníky šablonou Vzor MyÚčto v4 s údaji pro registraci
 * ČSSZ. Hodnota mimo číselník (CZ-ISCO, druh činnosti, obec, stát) se musí
 * ukázat už v náhledu importu jako problém řádku s názvem sloupce a nesmí se
 * tiše zapsat ani tiše zahodit až při založení. Název obce ze šablony („Praha“)
 * se má dohledat v číselníku obcí, jinak hlášení JMHZ skončí na neověřeném
 * pracovišti.
 */
#[Group('integration')]
final class PayrollE2eDppAttendanceCodebookTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const HEADERS = [
        'A' => 'Osobní číslo', 'B' => 'Příjmení a jméno', 'C' => 'Druh vztahu', 'D' => 'Nástup',
        'E' => 'Ulice', 'F' => 'Číslo domu', 'G' => 'Obec', 'H' => 'PSČ', 'I' => 'Stát',
        'J' => 'Státní občanství', 'K' => 'CZ-ISCO', 'L' => 'Místo výkonu práce', 'M' => 'Daňová rezidence',
        'N' => 'Prohlášení poplatníka', 'O' => 'Prohlášení od', 'P' => 'Druh činnosti', 'Q' => 'Odpracováno (hod)', 'R' => 'Zdravotní pojišťovna', 'S' => 'Rodné číslo',
    ];

    private Connection $db;
    private AttendanceImportService $service;
    private PayrollRunReadinessService $readiness;
    private int $supplierId;
    private int $userId;
    private int $profileId;

    protected function setUp(): void
    {
        $container = PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(AttendanceImportService::class);
        $this->readiness = $container->get(PayrollRunReadinessService::class);
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

    /**
     * Kódy, které mají správný tvar, ale v číselníku nejsou: CZ-ISCO 99999,
     * druh činnosti „QQ“, obec 999999, stát a občanství „ZZ“. Náhled je
     * vyjmenuje u řádku s názvem sloupce, do evidence se nezapíšou.
     */
    public function testCodesOutsideCodebooksAreReportedInPreviewAndNotWritten(): void
    {
        $file = $this->file([
            'I' => 'ZZ', 'J' => 'ZZ', 'K' => '99999', 'L' => '999999', 'M' => 'ZZ', 'P' => 'QQ',
        ]);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        $warnings = implode("\n", $person['warnings']);
        foreach (['Stát', 'Státní občanství', 'CZ-ISCO', 'Místo výkonu práce', 'Daňová rezidence', 'Druh činnosti'] as $column) {
            self::assertStringContainsString("{$column}:", $warnings, "Náhled nehlásí sloupec {$column}. Varování: {$warnings}");
        }
        self::assertStringContainsString('99999', $warnings);
        self::assertStringContainsString('ZZ', $warnings);
        self::assertSame(
            [],
            array_values(array_intersect(
                ['address', 'citizenship', 'cz_isco_code', 'activity_code', 'workplace', 'tax_residence'],
                array_column($person['registration'], 'key'),
            )),
            'Náhled slibuje zápis hodnoty mimo číselník: ' . json_encode($person['registration'], JSON_UNESCAPED_UNICODE),
        );

        $result = $this->create($person['key'], $file);
        self::assertSame('created', $result['status'], (string) $result['message']);
        $terms = $this->terms((int) $result['employment_id']);
        self::assertNull($terms['cz_isco_code']);
        self::assertNull($terms['jmhz_workplace_municipality_code']);
        self::assertNotSame('QQ', $terms['activity_code']);
        self::assertSame(0, (int) $this->one(
            'SELECT COUNT(*) FROM payroll_person_identity_history WHERE supplier_id = ? AND employee_id = ? AND citizenship_country_code = "ZZ"',
            [$this->supplierId, (int) $result['employee_id']],
        ));
        self::assertSame(0, (int) $this->one(
            'SELECT COUNT(*) FROM payroll_person_addresses WHERE supplier_id = ? AND employee_id = ? AND country_code = "ZZ"',
            [$this->supplierId, (int) $result['employee_id']],
        ));
    }

    /**
     * Ukázková hodnota šablony „Praha“ vede ke kódu obce 554782 a úřednímu
     * názvu, takže pracoviště je pro JMHZ ověřitelné. Název, který v číselníku
     * jednoznačně není, zůstane jen textem a náhled řekne, že je třeba kód.
     */
    public function testWorkplaceNameFromTemplateResolvesToMunicipalityCode(): void
    {
        $file = $this->file(['L' => 'Praha']);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertSame([], $person['warnings'], implode("\n", $person['warnings']));
        $result = $this->create($person['key'], $file);
        self::assertSame('created', $result['status'], (string) $result['message']);
        $terms = $this->terms((int) $result['employment_id']);
        self::assertSame('554782', $terms['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $terms['jmhz_workplace_country_code']);
        self::assertSame('Hlavní město Praha', $terms['work_place']);

        $unknown = $this->file(['L' => 'Neexistující Obec Nad Tichou']);
        $warnings = implode("\n", $this->service->preview($this->supplierId, self::PERIOD, [$unknown], null, $this->profileId)['persons'][0]['warnings']);
        self::assertStringContainsString('Místo výkonu práce:', $warnings);
        self::assertStringContainsString('kód obce', $warnings);
    }

    /**
     * Neexistující kód pojišťovny (123) osobu nezaloží a výsledek řekne proč
     * a jaké kódy platí. Do evidence se nic nezapíše, takže kód neprojde
     * až do podání.
     */
    public function testUnknownHealthInsurerStopsCreationWithGuidance(): void
    {
        $file = $this->file(['R' => '123']);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $result = $this->create($preview['persons'][0]['key'], $file);
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('Kód zdravotní pojišťovny 123 neexistuje', (string) $result['message']);
        self::assertStringContainsString('111 VZP', (string) $result['message']);
        self::assertSame(0, (int) $this->one(
            'SELECT COUNT(*) FROM payroll_person_health_coverage_history WHERE supplier_id = ?',
            [$this->supplierId],
        ));
    }

    /**
     * Brigádník s nástupem 6. 6. dostane z importu všechny údaje vedené po
     * celých měsících od 1. 6.: daňovou rezidenci, prohlášení, pojišťovnu
     * i jurisdikci. Dřív se rezidence nezapsala („se zadává po celých
     * měsících“) a osoba zůstala bez rezidence.
     */
    public function testMidMonthStartWritesMonthlyStatutoryEvidence(): void
    {
        $file = $this->file(['D' => '6. 6. 2026']);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $result = $this->create($preview['persons'][0]['key'], $file, '2026-06-06');
        self::assertSame('created', $result['status'], (string) $result['message']);
        $said = (string) $result['message'] . ' ' . json_encode($result['notes'] ?? [], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('po celých měsících', $said);
        $employeeId = (int) $result['employee_id'];
        self::assertSame(
            ['czech-resident', '2026-06-01'],
            array_values((array) $this->db->pdo()->query(
                "SELECT residence, effective_from FROM payroll_person_tax_residences WHERE supplier_id = {$this->supplierId} AND employee_id = {$employeeId}",
            )->fetch(PDO::FETCH_NUM)),
        );
        self::assertSame('signed', $this->one(
            'SELECT status FROM payroll_person_tax_declarations WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1',
            [$this->supplierId, $employeeId],
        ));
        self::assertSame('111', $this->one(
            'SELECT insurer_code FROM payroll_person_health_coverage_history WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from DESC LIMIT 1',
            [$this->supplierId, $employeeId],
        ));
        // Kontrola před během za červen: osobě nesmí chybět daňová rezidence
        // ani jiný údaj vedený po měsících (bez rezidence se daň nespočítá).
        $gaps = [];
        foreach ($this->readiness->inspect($this->supplierId, '2026-06-01', '2026-07-14')['findings'] as $finding) {
            foreach ($finding['code'] === 'person_data_gap' ? $finding['entities'] : [] as $entity) {
                if ((int) $entity['entity_id'] === $employeeId) {
                    $gaps = $entity['gaps'];
                }
            }
        }
        self::assertSame(['identifier'], $gaps);
    }

    /**
     * Neznámý CZ-ISCO (tvarově platný 12345) se ohlásí v náhledu a nezapíše,
     * ale nesmí s sebou vzít ostatní správné podmínky (pracoviště 554782).
     */
    public function testUnknownCzIscoDoesNotDropTheValidWorkplace(): void
    {
        $file = $this->file(['K' => '12345', 'L' => '554782']);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertStringContainsString('CZ-ISCO: kód 12345', implode("\n", $person['warnings']));
        self::assertContains('workplace', array_column($person['registration'], 'key'));
        $result = $this->create($person['key'], $file);
        self::assertSame('created', $result['status'], (string) $result['message']);
        $terms = $this->terms((int) $result['employment_id']);
        self::assertNull($terms['cz_isco_code']);
        self::assertSame('554782', $terms['jmhz_workplace_municipality_code']);
    }

    /**
     * Neplatné rodné číslo (kontrolní číslice) osobu nezaloží; náhled to
     * musí říct předem jako chybu řádku, ne jen upozorněním „k párování
     * se nepoužilo“. Platné rodné číslo chybu nenese.
     */
    public function testInvalidBirthNumberIsAnErrorOfThePreviewRow(): void
    {
        $file = $this->file(['S' => '9001011235']);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$file], null, $this->profileId);
        $person = $preview['persons'][0];
        self::assertCount(1, $person['errors']);
        self::assertStringContainsString('Rodné číslo', $person['errors'][0]);
        self::assertStringContainsString('aplikace nezaloží', $person['errors'][0]);
        self::assertStringNotContainsString('Rodné číslo', implode("\n", $person['warnings']));
        $result = $this->create($person['key'], $file);
        self::assertSame('failed', $result['status']);

        $valid = $this->file(['S' => AttendanceFixture::birthNumber('1990-01-01', 'female', 1)]);
        $preview = $this->service->preview($this->supplierId, self::PERIOD, [$valid], null, $this->profileId);
        self::assertSame([], $preview['persons'][0]['errors']);
    }

    /** @param array<string,string> $overrides */
    private function file(array $overrides): array
    {
        return AttendanceFixture::file('agentura.xlsx', AttendanceFixture::xlsx([
            AttendanceMyUctoSampleProfile::SHEET => ['rows' => [
                1 => self::HEADERS,
                2 => $overrides + [
                    'A' => 'C001', 'B' => 'Číselníková Jana', 'C' => 'DPP', 'D' => '1. 6. 2026',
                    'E' => 'Ukázková', 'F' => '12', 'G' => 'Praha', 'H' => '110 00', 'I' => 'CZ',
                    'J' => 'CZ', 'K' => '43111', 'L' => '554782', 'M' => 'CZ',
                    'N' => 'ano', 'O' => '1. 6. 2026', 'P' => '', 'Q' => 10, 'R' => '111',
                ],
            ]],
        ]));
    }

    /** @return array<string,mixed> */
    private function create(string $personKey, array $file, string $startOn = '2026-06-01'): array
    {
        return $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => $personKey,
            'full_name' => 'Jana Číselníková',
            'first_name' => 'Jana',
            'last_name' => 'Číselníková',
            'relation_type' => 'dpp',
            'planned_start_on' => $startOn,
            'personal_number' => 'C001',
            'activate' => true,
        ]], $this->userId, null, null, [$file], null, $this->profileId)['results'][0];
    }

    /** @return array<string,mixed> */
    private function terms(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cz_isco_code, activity_code, work_place, jmhz_workplace_municipality_code, jmhz_workplace_country_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<mixed> $params */
    private function one(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
