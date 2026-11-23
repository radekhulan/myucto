<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollImportProfileRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceSampleProfile;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Měsíční mzda z podkladů docházky do sjednaných podmínek existujících
 * vztahů a verzování ukázkového profilu. Izolovaná firma v transakci,
 * syntetická jména i částky; osoby vznikají stejnou cestou jako z importu,
 * takže mají skutečnou verzi podmínek od 1. 1. 2026.
 */
#[Group('integration')]
final class AttendanceWageAdoptionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const HOURLY = 'MZDA_HODINOVA_DOCH';

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
        foreach (['payroll_attendance_imports', 'payroll_import_profiles', 'payroll_employment_terms', 'payroll_runs'] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
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
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:attendance-wage")',
        )->execute([$this->supplierId, $officeId]);
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

    /**
     * Chybějící mzda je doplnění údaje, ne změna podmínek: opraví se platná
     * verze, nová nevznikne. Opakované použití nic dalšího nezapíše.
     */
    public function testWageFillsMissingTermsAsCorrection(): void
    {
        $jana = $this->createPerson('Jana Mzdová', null);
        $files = $this->files(['Jana Mzdová' => ['42 000']]);

        $changes = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null)['wage_changes'];
        self::assertCount(1, $changes);
        self::assertSame($jana['employment_id'], $changes[0]['employment_id']);
        self::assertNull($changes[0]['current_minor']);
        self::assertSame(4_200_000, $changes[0]['imported_minor']);
        self::assertSame('correct', $changes[0]['mode']);
        self::assertNull($changes[0]['reason']);

        $result = $this->apply($files);
        self::assertSame(1, $result['monthly_wages_adopted'], json_encode($result['wage_conflicts'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([['2026-01-01', null, 4_200_000]], $this->terms($jana['employment_id']));
        self::assertSame(4_200_000, $this->employmentGross($jana['employment_id']));
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_employment_events WHERE supplier_id = ? AND employment_id = ? AND event_type = "terms_corrected"',
            [$this->supplierId, $jana['employment_id']],
        ));

        $again = $this->apply($files);
        self::assertTrue($again['replayed']);
        self::assertSame(0, $again['monthly_wages_adopted']);
        self::assertSame([], $again['wage_conflicts']);
        self::assertSame([['2026-01-01', null, 4_200_000]], $this->terms($jana['employment_id']));
        self::assertSame([], $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null)['wage_changes']);
    }

    /**
     * Jiná mzda je změna podmínek: nová verze od 1. dne období, předchozí
     * končí den předem. Bez výslovného souhlasu se nezapíše nic a rozpracovaný
     * běh období se vrátí k přepočtu.
     */
    public function testChangedWageCreatesVersionFromPeriodStart(): void
    {
        $petr = $this->createPerson('Petr Mzdový', 30000);
        $runId = $this->insertRun('2026-06-01', 'calculated', 'calculated', [$petr]);
        $files = $this->files(['Petr Mzdový' => ['35000']]);

        $changes = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null)['wage_changes'];
        self::assertCount(1, $changes);
        self::assertSame('add', $changes[0]['mode']);
        self::assertSame(3_000_000, $changes[0]['current_minor']);
        self::assertSame(3_500_000, $changes[0]['imported_minor']);
        self::assertNull($changes[0]['reason']);
        self::assertSame([$runId], array_column($changes[0]['runs_needing_refresh'], 'run_id'));

        $silent = $this->service->apply($this->supplierId, self::PERIOD, $files, $this->rules(), [], false, false, $this->userId);
        self::assertSame(0, $silent['monthly_wages_adopted']);
        self::assertSame([['2026-01-01', null, 3_000_000]], $this->terms($petr['employment_id']));

        $result = $this->apply($files);
        self::assertSame(1, $result['monthly_wages_adopted'], json_encode($result['wage_conflicts'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(
            [['2026-01-01', '2026-05-31', 3_000_000], ['2026-06-01', null, 3_500_000]],
            $this->terms($petr['employment_id']),
        );
        self::assertSame(3_500_000, $this->employmentGross($petr['employment_id']));
        self::assertSame([['run_id' => $runId, 'period' => '2026-06', 'status' => 'calculated']], $result['runs_needing_refresh']);
    }

    /**
     * Do zaúčtovaného ani schváleného měsíce import nezapíše; a nová verze
     * podmínek nesmí do zúčtovaného měsíce ani přímo přes repozitář.
     */
    public function testWageNotWrittenIntoSettledPeriod(): void
    {
        $eva = $this->createPerson('Eva Mzdová', 30000);
        $ota = $this->createPerson('Ota Mzdový', 30000);
        $this->insertRun('2026-06-01', 'posted', 'approved', [$eva]);
        $this->insertRun('2026-07-01', 'approved', 'approved', [$ota]);
        $files = $this->files(['Eva Mzdová' => ['36000'], 'Ota Mzdový' => ['36000']]);

        $changes = array_column(
            $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null)['wage_changes'],
            null,
            'display_name',
        );
        self::assertStringContainsString('zaúčtovaná nebo vyplacená', (string) $changes['Eva Mzdová']['reason']);
        self::assertStringContainsString('schválený', (string) $changes['Ota Mzdový']['reason']);

        $result = $this->apply($files);
        self::assertSame(0, $result['monthly_wages_adopted']);
        self::assertCount(2, $result['wage_conflicts']);
        self::assertSame([['2026-01-01', null, 3_000_000]], $this->terms($eva['employment_id']));
        self::assertSame([['2026-01-01', null, 3_000_000]], $this->terms($ota['employment_id']));

        $employments = $this->container->get(PayrollEmploymentRepository::class);
        $validator = $this->container->get(PayrollEmploymentValidator::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        self::assertInstanceOf(PayrollEmploymentValidator::class, $validator);
        $body = RegistrationImportWriter::termsBody(
            $employments->currentTerms($this->supplierId, $eva['employment_id']) ?? [],
            'Syntetická změna do zúčtovaného měsíce.',
        );
        $body['effective_from'] = '2026-06-01';
        $terms = $validator->terms(
            $body,
            $employments->currentCzIscoCode($this->supplierId, $eva['employment_id']),
            $employments->currentOtherWithholdingEligibility($this->supplierId, $eva['employment_id']),
            $employments->currentRelationType($this->supplierId, $eva['employment_id']),
        );
        $this->expectException(PayrollTermsSettledException::class);
        $employments->addTerms(
            $this->supplierId,
            $eva['employment_id'],
            $terms,
            $this->employmentVersion($eva['employment_id']),
            $this->userId,
            null,
            null,
            true,
            3_600_000,
        );
    }

    /**
     * Hodinová mzda z docházky je základ; měsíční mzda z podkladů by vedle
     * ní v podmínkách platila jako druhý základ, proto se nenabídne.
     */
    public function testWageNotOfferedWithHourlyComponents(): void
    {
        $hana = $this->createPerson('Hana Hodinová', null);
        $files = $this->files(['Hana Hodinová' => ['42000', 23520]]);
        $components = [['code' => self::HOURLY, 'name' => 'Hodinová mzda podle docházky', 'kind' => 'hourly_wage']];

        $changes = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null, $components)['wage_changes'];
        self::assertCount(1, $changes);
        self::assertStringContainsString(self::HOURLY, (string) $changes[0]['reason']);

        $result = $this->apply($files, $components);
        self::assertSame(0, $result['monthly_wages_adopted']);
        self::assertSame(['Hana Hodinová'], array_column($result['wage_conflicts'], 'display_name'));
        self::assertSame([['2026-01-01', null, null]], $this->terms($hana['employment_id']));
    }

    /**
     * Hodinově odměňovanému vztahu (i jen od poloviny měsíce) náhled převzetí
     * měsíční mzdy nenabídne jako proveditelné a řekne proč.
     */
    public function testWageNotOfferedToHourlyRemuneration(): void
    {
        $hana = $this->createPerson('Hana Sazbová', 30000);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET effective_to = "2026-06-14" WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $hana['employment_id']]);
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis, weekly_hours, workload_basis_points) VALUES (?, ?, "2026-06-15", "2026-01-01", "hourly", 20000, "contract", "Syntetické podmínky", 40, 10000)')
            ->execute([$this->supplierId, $hana['employment_id']]);
        $files = $this->files(['Hana Sazbová' => ['42000']]);

        $changes = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules(), null)['wage_changes'];
        self::assertCount(1, $changes);
        self::assertStringContainsString('hodinově', (string) $changes[0]['reason']);

        $result = $this->apply($files);
        self::assertSame(0, $result['monthly_wages_adopted']);
        self::assertSame(['Hana Sazbová'], array_column($result['wage_conflicts'], 'display_name'));
    }

    /**
     * Vzor, na který nikdo nesáhl, se převede na aktuální verzi sám. Otisk
     * zapsaný aplikací musí sedět s výrazem, kterým ho doplňuje migrace 1836.
     */
    public function testStaleUntouchedSampleIsUpgraded(): void
    {
        $id = $this->insertStaleSample();
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_import_profiles
              WHERE id = ? AND sample_rules_sha256 = SHA2(CONCAT(rules_json, CHAR(10), COALESCE(components_json, "")), 256)',
            [$id],
        ), 'Otisk z PHP a z SQL migrace se musí shodovat.');

        $profiles = array_column($this->service->profiles($this->supplierId), null, 'id');

        self::assertSame(AttendanceSampleProfile::rules(), $profiles[$id]['rules']);
        self::assertSame(AttendanceSampleProfile::components(), $profiles[$id]['components']);
        self::assertSame(AttendanceSampleProfile::VERSION, $profiles[$id]['sample_version']);
        self::assertFalse($profiles[$id]['upgrade_available']);
        self::assertNull(
            $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['upgrade_available'],
        );
    }

    /** Upravený vzor zůstává, jak si ho účetní nastavila; nová verze se jen nabídne. */
    public function testModifiedSampleIsNotOverwritten(): void
    {
        $id = $this->insertStaleSample();
        $custom = [...self::staleRules(), ['sheet' => 'mzdy*', 'header' => 'plat', 'meaning' => 'monthly_wage', 'unit' => null, 'component_code' => null]];
        $this->service->saveProfile($this->supplierId, $id, AttendanceSampleProfile::NAME, $custom, $this->userId, AttendanceSampleProfile::components());

        $profiles = array_column($this->service->profiles($this->supplierId), null, 'id');

        self::assertSame($custom, $profiles[$id]['rules']);
        self::assertSame(1, $profiles[$id]['sample_version']);
        self::assertTrue($profiles[$id]['upgrade_available']);
        $upgrade = $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['upgrade_available'];
        self::assertIsArray($upgrade);
        self::assertSame($id, $upgrade['profile_id']);
        self::assertSame(1, $upgrade['version']);
        self::assertSame(AttendanceSampleProfile::VERSION, $upgrade['latest_version']);

        // Převzetí nabídky běžným uložením profilu vzor označí jako aktuální.
        $this->service->saveProfile(
            $this->supplierId,
            $id,
            AttendanceSampleProfile::NAME,
            $upgrade['rules'],
            $this->userId,
            $upgrade['components'],
        );
        $profiles = array_column($this->service->profiles($this->supplierId), null, 'id');
        self::assertSame(AttendanceSampleProfile::VERSION, $profiles[$id]['sample_version']);
        self::assertFalse($profiles[$id]['upgrade_available']);
    }

    /** Vzor ve starší podobě (bez mapování měsíční mzdy), jak ho aplikace kdysi založila. */
    private function insertStaleSample(): int
    {
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_attendance_sample_seeded_at = NOW() WHERE id = ?')
            ->execute([$this->supplierId]);
        $profiles = $this->container->get(PayrollImportProfileRepository::class);
        self::assertInstanceOf(PayrollImportProfileRepository::class, $profiles);
        $profile = $profiles->save(
            $this->supplierId,
            AttendanceMeaning::SOURCE_SYSTEM,
            null,
            AttendanceSampleProfile::NAME,
            self::staleRules(),
            null,
            AttendanceSampleProfile::components(),
            true,
            1,
        );
        self::assertNotNull($profile);

        return $profile['id'];
    }

    /** @return list<array<string,mixed>> */
    private static function staleRules(): array
    {
        return array_values(array_filter(
            AttendanceSampleProfile::rules(),
            static fn (array $rule): bool => $rule['meaning'] !== 'monthly_wage',
        ));
    }

    /**
     * @param array<string,array{0:string,1?:int}> $persons jméno → [měsíční mzda, hodinová mzda]
     * @return list<array{name:string,content:string,sha256:string,extension:string}>
     */
    private function files(array $persons): array
    {
        $rows = [1 => ['A' => 'Jméno a příjmení', 'B' => 'MV', 'C' => 'Suma hodinovky']];
        $row = 2;
        foreach ($persons as $name => $values) {
            $rows[$row++] = ['A' => $name, 'B' => $values[0], 'C' => $values[1] ?? null];
        }

        return [AttendanceFixture::file('mzdy.xlsx', AttendanceFixture::xlsx(['mzdy 06-26' => ['rows' => $rows]]))];
    }

    /** @return list<array<string,mixed>> */
    private function rules(): array
    {
        return [
            ['sheet' => 'mzdy*', 'header' => 'jméno*', 'meaning' => 'person_name', 'unit' => null, 'component_code' => null],
            ['sheet' => 'mzdy*', 'header' => 'mv', 'meaning' => 'monthly_wage', 'unit' => null, 'component_code' => null],
            ['sheet' => 'mzdy*', 'header' => 'suma hodinovky*', 'meaning' => 'component', 'unit' => 'amount', 'component_code' => self::HOURLY],
            ['sheet' => '*', 'header' => '*', 'meaning' => 'ignore', 'unit' => null, 'component_code' => null],
        ];
    }

    /**
     * @param list<array<string,mixed>> $files
     * @param list<array<string,mixed>>|null $components
     * @return array<string,mixed>
     */
    private function apply(array $files, ?array $components = null): array
    {
        return $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            $this->rules(),
            [],
            false,
            false,
            $this->userId,
            $components,
            adoptMonthlyWage: true,
        );
    }

    /** @return array{employee_id:int,employment_id:int} */
    private function createPerson(string $name, ?int $monthlyGross): array
    {
        [$first, $last] = explode(' ', $name, 2);
        $result = $this->service->persons($this->supplierId, self::PERIOD, [[
            'person_key' => mb_strtolower($name),
            'full_name' => $name,
            'first_name' => $first,
            'last_name' => $last,
            'birth_number' => null,
            'relation_type' => 'employment',
            'weekly_hours' => '40',
            'planned_start_on' => '2026-01-01',
            'monthly_gross' => $monthlyGross,
            'activate' => true,
        ]], $this->userId, null, null)['results'][0];
        self::assertSame('created', $result['status'], (string) $result['message']);

        return ['employee_id' => (int) $result['employee_id'], 'employment_id' => (int) $result['employment_id']];
    }

    /** @param list<array{employee_id:int,employment_id:int}> $employments */
    private function insertRun(string $periodStart, string $runStatus, string $revisionStatus, array $employments): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, ?, 1, 1)',
        )->execute([$this->supplierId, $periodStart, $periodStart, $runStatus]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version, ruleset_manifest_hash,
                  input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, ?, 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, $runId, $revisionStatus, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $revisionId = (int) $pdo->lastInsertId();
        foreach ($employments as $employment) {
            $pdo->prepare(
                "INSERT INTO payroll_run_employments (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
                 VALUES (?, ?, ?, ?, '{}', ?)",
            )->execute([$this->supplierId, $revisionId, $employment['employee_id'], $employment['employment_id'], str_repeat('c', 64)]);
        }

        return $runId;
    }

    /** @return list<array{0:string,1:?string,2:?int}> */
    private function terms(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, effective_to, monthly_gross_minor FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from, id',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return array_map(static fn (array $row): array => [
            (string) $row['effective_from'],
            $row['effective_to'] === null ? null : (string) $row['effective_to'],
            $row['monthly_gross_minor'] === null ? null : (int) $row['monthly_gross_minor'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function employmentGross(int $employmentId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT monthly_gross_minor FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    private function employmentVersion(int $employmentId): int
    {
        return $this->countRows('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]);
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
