<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agentura s vlastním profilem mapování a měsíčním výkazem brigádníků na DPP,
 * druhý měsíc importu:
 *
 *  - Adam měl lednovou DPP do 31. 1. → v únoru dostane novou DPP na téže kartě;
 *  - Běla má otevřenou DPP → spáruje se a nic nového nevzniká;
 *  - Cyril je nový → založí se s údaji pro registraci ČSSZ;
 *  - hodiny a hodinová mzda projdou jako koncepty vstupů, bez kolize
 *    s hodinovým výpočtem (vztahy zůstávají bez hodinového odměňování).
 */
#[Group('integration')]
final class AttendanceSecondMonthFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const HEADERS = [
        'A' => 'Brigádník', 'B' => 'Č. brigádníka', 'C' => 'Smlouva', 'D' => 'Konec smlouvy',
        'E' => 'Hodiny', 'F' => 'Výdělek za hodiny', 'G' => 'Státní občanství', 'H' => 'Místo výkonu práce',
    ];

    private Connection $db;
    private AttendanceImportService $service;
    private int $supplierId;
    private int $userId;
    private int $profileId;

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

        $rule = static fn (string $header, string $meaning, ?string $unit = 'text', ?string $component = null): array => [
            'sheet' => null, 'header' => $header, 'meaning' => $meaning, 'unit' => $unit, 'component_code' => $component,
        ];
        $this->profileId = (int) $this->service->saveProfile($this->supplierId, null, 'Agentura', [
            $rule('brigádník', 'person_name'),
            $rule('č. brigádníka', 'personal_number'),
            $rule('smlouva', 'relation_label'),
            $rule('konec smlouvy', 'end_date'),
            $rule('hodiny', 'worked_hours', null),
            $rule('výdělek za hodiny', 'component', 'amount', 'MZDA_HODINOVA_DOCH'),
        ], $this->userId, [['code' => 'MZDA_HODINOVA_DOCH', 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage']])['id'];
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

    public function testSecondMonthMatchesReusesAndCreatesWithoutCollision(): void
    {
        $january = $this->file('leden.xlsx', [
            ['Ukázkový Adam', 'A01', 'DPP', '31. 1. 2026', 10, 1800, null, null],
            ['Vzorová Běla', 'A02', 'DPP', null, 20, 3600, null, null],
        ]);
        $created = $this->createAll('2026-01', $january);
        $adam = $created['adam ukazkovy']['employee_id'];
        $bela = $created['bela vzorova']['employment_id'];
        $this->applyMonth('2026-01', $january);

        $february = $this->file('unor.xlsx', [
            ['Ukázkový Adam', 'A01', 'DPP', null, 12, 2160, null, null],
            ['Vzorová Běla', 'A02', 'DPP', null, 15, 2700, null, null],
            ['Nový Cyril', 'A03', 'DPP', null, 8, 1600, 'SK', 'Brno'],
        ]);
        $preview = $this->service->preview($this->supplierId, '2026-02', [$february], null, $this->profileId);
        $persons = array_column($preview['persons'], null, 'key');
        self::assertSame('not_found', $persons['adam ukazkovy']['match']['status']);
        self::assertSame($adam, $persons['adam ukazkovy']['existing_person']['employee_id']);
        self::assertContains($persons['bela vzorova']['match']['status'], ['linked', 'matched']);
        self::assertSame($bela, $persons['bela vzorova']['match']['employment_id']);
        self::assertNull($persons['cyril novy']['existing_person']);
        self::assertSame(['citizenship' => 'SK', 'workplace' => 'Brno'], array_column($persons['cyril novy']['registration'], 'value', 'key'));
        self::assertSame(20_000, $persons['cyril novy']['probable_earning']['hourly_minor']);

        $results = $this->createAll('2026-02', $february);
        self::assertSame('employment_created', $results['adam ukazkovy']['status'], (string) $results['adam ukazkovy']['message']);
        self::assertSame($adam, $results['adam ukazkovy']['employee_id']);
        self::assertSame('created', $results['cyril novy']['status'], (string) $results['cyril novy']['message']);
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?'));

        $result = $this->applyMonth('2026-02', $february);
        self::assertSame(3, $result['batch']['person_count']);
        self::assertSame([], $result['skipped_persons']);
        self::assertSame([], $result['inputs']['errors']);
        // Cyril dostal údaje pro registraci už při založení, doplňovat není co.
        self::assertSame(['filled' => 0, 'notes' => []], $result['registration']);
        $inputs = $this->february();
        self::assertSame([
            [$bela, 270_000, 'draft'],
            [$results['adam ukazkovy']['employment_id'], 216_000, 'draft'],
            [$results['cyril novy']['employment_id'], 160_000, 'draft'],
        ], $inputs);
        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_terms WHERE supplier_id = ? AND remuneration_type = "hourly"',
        ));
    }

    /**
     * @param list<array{0:string,1:string,2:?string,3:?string,4:int,5:int,6:?string,7:?string}> $rows
     * @return array{name:string,content:string,sha256:string,extension:string}
     */
    private function file(string $name, array $rows): array
    {
        $sheet = [1 => self::HEADERS];
        foreach ($rows as $index => $row) {
            $sheet[$index + 2] = array_filter(
                array_combine(array_keys(self::HEADERS), $row),
                static fn (mixed $value): bool => $value !== null,
            );
        }

        return AttendanceFixture::file($name, AttendanceFixture::xlsx(['Výkaz' => ['rows' => $sheet]]));
    }

    /**
     * Založí všechny osoby bez vztahu tak, jak to udělá automatické založení při použití.
     *
     * @param array{name:string,content:string,sha256:string,extension:string} $file
     * @return array<string,array<string,mixed>>
     */
    private function createAll(string $period, array $file): array
    {
        $preview = $this->service->preview($this->supplierId, $period, [$file], null, $this->profileId);
        $items = [];
        foreach ($preview['persons'] as $person) {
            if ($person['match']['status'] !== 'not_found') {
                continue;
            }
            [$last, $first] = explode(' ', $person['display_name']);
            $items[] = [
                'person_key' => $person['key'],
                'full_name' => "{$first} {$last}",
                'first_name' => $first,
                'last_name' => $last,
                'relation_type' => 'dpp',
                'planned_start_on' => $period . '-01',
                'fixed_term_end_on' => $person['end_date'],
                'personal_number' => $person['personal_number'],
                'activate' => true,
            ];
        }
        $results = $this->service->persons($this->supplierId, $period, $items, $this->userId, null, null, [$file], null, $this->profileId)['results'];

        return array_column($results, null, 'person_key');
    }

    /**
     * @param array{name:string,content:string,sha256:string,extension:string} $file
     * @return array<string,mixed>
     */
    private function applyMonth(string $period, array $file): array
    {
        return $this->service->apply(
            $this->supplierId,
            $period,
            [$file],
            null,
            [],
            true,
            true,
            $this->userId,
            createComponents: true,
            profileId: $this->profileId,
            adoptPersonalNumbers: true,
            fillRegistrationData: true,
            adoptProbableEarnings: true,
        );
    }

    /** @return list<array{0:int,1:int,2:string}> */
    private function february(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.employment_id, input.amount_minor, input.status
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.period_start = "2026-02-01" AND component.code = "MZDA_HODINOVA_DOCH"
              ORDER BY input.amount_minor DESC',
        );
        $stmt->execute([$this->supplierId]);

        return array_map(
            static fn (array $row): array => [(int) $row['employment_id'], (int) $row['amount_minor'], (string) $row['status']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
