<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Service\Payroll\Absence\ImportAbsenceCompensationRates;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportSummaryWriter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Náhrady mzdy a krácení měsíční mzdy z měsíčních součtů importu docházky
 * nad izolovanou firmou v transakci, kterou tearDown vrací.
 *
 * Červenec 2026 (3. čtvrtletí): fond plného úvazku 22 × 8 h = 10 560 minut.
 * Schválený průměr 250 Kč/h. Dovolená 16 h = 4 000 Kč, lékař 2,5 h = 625 Kč,
 * překážka zaměstnavatele 8 h × 80 % = 1 600 Kč. Nemoc 24 h se nepočítá.
 */
#[Group('integration')]
final class AttendanceAbsenceCompensationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const JULY = '2026-07-01';
    private const FILE = 'dochazka.xlsx';
    private const MONTHLY_GROSS = 4_200_000;

    private Connection $db;
    private ContainerInterface $container;
    private PayrollTimeImportSummaryWriter $writer;
    private PayrollImportAbsenceCompensationMaterializer $materializer;
    private PayrollAttendanceImportRepository $imports;
    private int $supplierId;
    private int $userId;
    private int $sourceSupplierId;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $this->container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Připojení k databázi není dostupné.');
        }
        $this->db = $db;
        foreach (['payroll_time_month_import_summaries', 'payroll_attendance_imports', 'payroll_average_earning_snapshots'] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->writer = $this->service(PayrollTimeImportSummaryWriter::class);
        $this->materializer = $this->service(PayrollImportAbsenceCompensationMaterializer::class);
        $this->imports = $this->service(PayrollAttendanceImportRepository::class);

        $pdo = $db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->payrollSupplier();
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

    public function testDraftCompensationsFromSummaryHoursAndSkippedSickness(): void
    {
        $employmentId = $this->employment('NAH-1');
        $average = $this->approvedAverage($employmentId);
        $importId = $this->writtenBatch($employmentId, $this->hours());

        $report = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(3, $report['created'], (string) json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['unchanged']);
        self::assertSame(['sick_hours'], array_column($report['skipped'], 'meaning'));
        self::assertStringContainsString('DPN', $report['skipped'][0]['reason']);
        self::assertSame(['doctor_hours'], array_column($report['warnings'], 'meaning'));

        $inputs = $this->inputs($employmentId);
        self::assertSame([
            // Vlastní složky: v hlášení kolonky 10341 a 10340, ne jen úhrn 10337.
            'doctor_hours' => ['NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', 62_500, 2_500, 'draft'],
            'obstacle_employer_hours' => ['NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', 160_000, 8_000, 'draft'],
            'vacation_hours' => ['NAHRADA_MZDY_DOVOLENA', 400_000, 16_000, 'draft'],
        ], array_map(
            static fn (array $row): array => [$row['component_code'], (int) $row['amount_minor'], (int) $row['quantity_milliunits'], $row['status']],
            $inputs,
        ));
        $trace = json_decode((string) $inputs['obstacle_employer_hours']['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('import_absence_compensation.v1', $trace['kind']);
        self::assertSame($importId, $trace['attendance_import_id']);
        self::assertSame($average, $trace['average_snapshot_id']);
        self::assertSame(25_000, $trace['average_hourly_minor']);
        self::assertSame(80, $trace['rate_percent']);
        self::assertSame(480, $trace['minutes']);
        self::assertSame(self::FILE . '!List1!G3', $trace['source']['ref'] ?? null);
        self::assertSame('absence', $inputs['vacation_hours']['source_kind']);
        self::assertSame('leave:attendance:2026-07:' . $employmentId . ':vacation_hours', $inputs['vacation_hours']['external_id']);
    }

    public function testRerunIsIdempotent(): void
    {
        $employmentId = $this->employment('NAH-2');
        $this->approvedAverage($employmentId);
        $importId = $this->writtenBatch($employmentId, $this->hours());
        $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);

        $second = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $second['created']);
        self::assertSame(3, $second['unchanged']);
        self::assertCount(3, $this->inputs($employmentId));
        self::assertSame(3, $this->countRows(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ), 'Opakování nic nezruší ani nezaloží.');
    }

    /**
     * Opravná dávka: změněný koncept se nahradí novým s novou stopou, schválený
     * vstup import nepřepíše a jen ohlásí, zmizelé hodiny koncept zruší.
     */
    public function testCorrectiveBatchUpdatesDraftsReportsApprovedAndCancelsRemoved(): void
    {
        $employmentId = $this->employment('NAH-3');
        $this->approvedAverage($employmentId);
        $first = $this->writtenBatch($employmentId, $this->hours());
        $this->materializer->materializeFromBatch($this->supplierId, $first, $this->userId);
        $vacation = $this->inputs($employmentId)['vacation_hours'];
        $inputs = $this->service(PayrollInputRepository::class);
        $approved = $inputs->approve($this->supplierId, (int) $vacation['id'], (int) $vacation['row_version'], $this->userId);
        self::assertSame('approved', $approved['status'] ?? null);

        $hours = $this->hours();
        $hours['vacation_hours'] = 24_000;
        $hours['doctor_hours'] = 4_000;
        unset($hours['obstacle_employer_hours']);
        $second = $this->writtenBatch($employmentId, $hours);

        $report = $this->materializer->materializeFromBatch($this->supplierId, $second, $this->userId);

        self::assertSame(0, $report['created']);
        self::assertSame(1, $report['updated'], (string) json_encode($report, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $report['cancelled']);
        $vacationSkip = array_values(array_filter($report['skipped'], static fn (array $item): bool => $item['meaning'] === 'vacation_hours'));
        self::assertCount(1, $vacationSkip);
        self::assertStringContainsString('schválený', $vacationSkip[0]['reason']);

        $live = $this->inputs($employmentId);
        self::assertSame(['doctor_hours', 'vacation_hours'], array_keys($live));
        self::assertSame(100_000, (int) $live['doctor_hours']['amount_minor'], '4 h × 250 Kč.');
        self::assertSame(400_000, (int) $live['vacation_hours']['amount_minor'], 'Schválený vstup zůstal beze změny.');
        $trace = json_decode((string) $live['doctor_hours']['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($second, $trace['attendance_import_id']);
        self::assertSame(2, $this->countRows(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ? AND status = "cancelled"',
            [$this->supplierId, $employmentId],
        ), 'Původní koncept lékaře a zmizelá překážka zůstaly jako zrušené.');
    }

    public function testWithoutApprovedAverageNothingIsCreated(): void
    {
        $employmentId = $this->employment('NAH-4');
        $importId = $this->writtenBatch($employmentId, $this->hours());

        $report = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $report['created']);
        self::assertSame([], $this->inputs($employmentId));
        $averageSkips = array_filter($report['skipped'], static fn (array $item): bool => str_contains($item['reason'], 'průměrný výdělek'));
        self::assertCount(3, $averageSkips);
        self::assertStringContainsString('3. čtvrtletí 2026', array_values($averageSkips)[0]['reason']);
    }

    public function testConfigurableEmployerObstacleRate(): void
    {
        $employmentId = $this->employment('NAH-5');
        $this->approvedAverage($employmentId);
        $importId = $this->writtenBatch($employmentId, $this->hours());

        $this->materializer->materializeFromBatch(
            $this->supplierId,
            $importId,
            $this->userId,
            ImportAbsenceCompensationRates::fromMap(['obstacle_employer_hours' => 60]),
        );

        self::assertSame(120_000, (int) $this->inputs($employmentId)['obstacle_employer_hours']['amount_minor']);
    }

    public function testSummaryNotWrittenByThisBatchIsSkipped(): void
    {
        $employmentId = $this->employment('NAH-6');
        $this->approvedAverage($employmentId);
        $importId = $this->batch($employmentId, $this->hours());

        $report = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $report['created']);
        self::assertSame([null], array_column($report['skipped'], 'meaning'));
    }

    /**
     * Červenec 2026: odpracovává se 176 h, měsíční mzda ale kryje i svátek 6. 7.
     * (§ 115 odst. 3 ZP), takže se krátí z fondu 184 h = 11 040 minut.
     * 42 000 Kč × (11 040 − 3 030) / 11 040 = 30 472,83 Kč → 30 473 Kč.
     * Nahrazeno: dovolená 960, lékař + překážka 630, nemoc 1 440 minut.
     */
    public function testQuickInputBaseIsProratedFromImportSummary(): void
    {
        $employmentId = $this->employment('NAH-7');
        $this->writtenBatch($employmentId, $this->hours());

        $row = $this->quickRow($employmentId);

        self::assertFalse($row['base_requires_entry']);
        self::assertSame(3_047_300, $row['base_amount_minor']);
        self::assertSame([
            'fund_minutes' => 11_040,
            'replaced_minutes' => 3_030,
            'replaced_minutes_by_title' => ['vacation' => 960, 'sickness_compensation' => 1_440, 'paid_obstacle' => 630],
            'amount_minor' => 3_047_300,
        ], $row['base_proration']);
    }

    public function testQuickInputFailsClosedWhenAbsenceExceedsFund(): void
    {
        $employmentId = $this->employment('NAH-8');
        $hours = $this->hours();
        $hours['sick_hours'] = 170_000;
        $this->writtenBatch($employmentId, $hours);

        $row = $this->quickRow($employmentId);

        self::assertTrue($row['base_requires_entry']);
        self::assertSame(0, $row['base_amount_minor']);
        self::assertContains('absence_month_base_required', $row['blockers']);
        self::assertSame('absence_exceeds_work_fund', $row['base_proration_unsupported_reason']);
    }

    public function testQuickInputFailsClosedWhenImportedFundDiffersFromCalendar(): void
    {
        $employmentId = $this->employment('NAH-9');
        $hours = $this->hours();
        $hours['fund_hours'] = 192_000;
        $this->writtenBatch($employmentId, $hours);

        $row = $this->quickRow($employmentId);

        self::assertTrue($row['base_requires_entry']);
        self::assertSame('import_fund_mismatch', $row['base_proration_unsupported_reason']);
    }

    /**
     * Fond z podkladů se svátkem v jinak pracovní den (6. 7. 2026): 176 + 8 = 184 h,
     * stejně jako fond měsíčního hlášení. Je to týž rozvrh, krácení proto proběhne
     * a počítá se z téhož fondu včetně svátku jako u souhrnu, který svátek neuvádí.
     */
    public function testQuickInputAcceptsImportedFundIncludingHoliday(): void
    {
        $employmentId = $this->employment('NAH-9B');
        $hours = $this->hours();
        $hours['fund_hours'] = 184_000;
        $hours['holiday_hours'] = 8_000;
        $this->writtenBatch($employmentId, $hours);

        $row = $this->quickRow($employmentId);

        self::assertFalse($row['base_requires_entry'], (string) ($row['base_proration_unsupported_reason'] ?? ''));
        self::assertSame(3_047_300, $row['base_amount_minor']);
        self::assertSame(11_040, $row['base_proration']['fund_minutes']);
    }

    /**
     * Svátek u mzdy za hodiny: náhrada ve výši průměru (§ 115 odst. 3 ZP), 8 h × 250 Kč.
     * Jen na výslovnou žádost volajícího a jen vztahu bez předpisu měsíční mzdy.
     */
    public function testHolidayCompensationOnlyWithoutMonthlyWageAndOnlyWhenAsked(): void
    {
        $hourly = $this->employment('NAH-SV1');
        $monthly = $this->employment('NAH-SV2');
        $this->approvedAverage($hourly);
        $this->approvedAverage($monthly);
        $this->monthlyWageRecurring($monthly);
        $hours = ['worked_hours' => 168_000, 'fund_hours' => 184_000, 'holiday_hours' => 8_000];

        $plain = $this->writtenBatch($hourly, $hours);
        $this->materializer->materializeFromBatch($this->supplierId, $plain, $this->userId);
        self::assertArrayNotHasKey('holiday_hours', $this->inputs($hourly), 'Bez žádosti volajícího se za svátek nic nepočítá.');

        $this->materializer->materializeFromBatch($this->supplierId, $plain, $this->userId, holidayWithoutMonthlyWage: true);
        $holiday = $this->inputs($hourly)['holiday_hours'] ?? null;
        self::assertIsArray($holiday);
        self::assertSame('NAHRADA_MZDY_SVATEK', $holiday['component_code']);
        self::assertSame(200_000, (int) $holiday['amount_minor']);

        $monthlyBatch = $this->writtenBatch($monthly, $hours);
        $this->materializer->materializeFromBatch($this->supplierId, $monthlyBatch, $this->userId, holidayWithoutMonthlyWage: true);
        self::assertArrayNotHasKey('holiday_hours', $this->inputs($monthly), 'Měsíční mzda se za svátek nekrátí, náhrada nevzniká.');
    }

    /**
     * Placené volno (§ 199 ZP) jen na výslovnou žádost volajícího, jehož zdroj hodiny vede
     * jako placené: 6 h × 250 Kč × 100 %. Bez ní zůstává mezi hodinami bez náhrady.
     */
    public function testPaidEmployeeObstacleOnlyWhenAsked(): void
    {
        $employmentId = $this->employment('NAH-PV1');
        $this->approvedAverage($employmentId);
        $importId = $this->writtenBatch($employmentId, ['worked_hours' => 162_000, 'fund_hours' => 168_000, 'obstacle_employee_hours' => 6_000]);

        $plain = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId);
        self::assertSame(['obstacle_employee_hours'], array_column($plain['skipped'], 'meaning'));
        self::assertSame([], $this->inputs($employmentId));

        $paid = $this->materializer->materializeFromBatch($this->supplierId, $importId, $this->userId, paidEmployeeObstacle: true);
        self::assertSame([], $paid['skipped'], (string) json_encode($paid, JSON_UNESCAPED_UNICODE));
        $input = $this->inputs($employmentId)['obstacle_employee_hours'] ?? null;
        self::assertIsArray($input);
        self::assertSame('NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', $input['component_code']);
        self::assertSame(150_000, (int) $input['amount_minor']);
        $trace = json_decode((string) $input['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(100, $trace['rate_percent']);
        self::assertSame('zp-199-1+nv-590-2006', $trace['entitlement_basis']);
    }

    private function monthlyWageRecurring(int $employmentId): void
    {
        $this->service(PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind, amount_minor, valid_from, allocation_rule, is_active, created_by)
             SELECT ?, ?, id, 'fixed_amount', ?, '2026-01-01', 'calendar_days', 1, ?
               FROM payroll_component_definitions WHERE supplier_id = ? AND component_kind = 'base_wage' ORDER BY id LIMIT 1",
        );
        $insert->execute([$this->supplierId, $employmentId, self::MONTHLY_GROSS, $this->userId, $this->supplierId]);
        self::assertSame(1, $insert->rowCount(), 'Předpis měsíční mzdy se nezaložil.');
    }

    public function testQuickInputImportMonthWithoutAbsenceKeepsAgreedWage(): void
    {
        $employmentId = $this->employment('NAH-10');
        $this->writtenBatch($employmentId, ['worked_hours' => 176_000, 'fund_hours' => 176_000]);

        $row = $this->quickRow($employmentId);

        self::assertSame(self::MONTHLY_GROSS, $row['base_amount_minor']);
        self::assertNull($row['base_proration']);
    }

    public function testApplyRequiresSummaryForCompensations(): void
    {
        $service = $this->service(AttendanceImportService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('náhrad mzdy');
        $service->apply(
            $this->supplierId,
            AttendanceFixture::PERIOD,
            AttendanceFixture::scenario(),
            null,
            [],
            false,
            false,
            $this->userId,
            materializeAbsenceCompensations: true,
        );
    }

    public function testApplyReturnsTheCompensationReportOnlyWhenAsked(): void
    {
        $jana = $this->employment('ZAM-J', 'Jana Testovací');
        $petr = $this->employment('ZAM-P', 'Petr Zkušební');
        $service = $this->service(AttendanceImportService::class);
        $files = AttendanceFixture::scenario();
        $links = [
            ['person_key' => 'jana testovaci', 'employment_id' => $jana],
            ['person_key' => 'petr zkusebni', 'employment_id' => $petr],
        ];

        $plain = $service->apply($this->supplierId, AttendanceFixture::PERIOD, $files, null, $links, false, false, $this->userId, writeTimeSummary: true);
        self::assertNull($plain['absence_compensation']);

        $withCompensations = $service->apply(
            $this->supplierId,
            AttendanceFixture::PERIOD,
            $files,
            null,
            $links,
            false,
            false,
            $this->userId,
            writeTimeSummary: true,
            materializeAbsenceCompensations: true,
        );

        self::assertIsArray($withCompensations['absence_compensation']);
        self::assertSame(
            ['created', 'updated', 'unchanged', 'cancelled', 'rates', 'skipped', 'warnings'],
            array_keys($withCompensations['absence_compensation']),
        );
    }

    /** @return array<string,int> */
    private function hours(): array
    {
        return [
            'worked_hours' => 125_500,
            'fund_hours' => 176_000,
            'vacation_hours' => 16_000,
            'doctor_hours' => 2_500,
            'obstacle_employer_hours' => 8_000,
            'sick_hours' => 24_000,
            'holiday_hours' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function quickRow(int $employmentId): array
    {
        $month = $this->service(PayrollQuickInputRepository::class)->month($this->supplierId, '2026-07', employmentId: $employmentId);
        self::assertCount(1, $month['items']);

        return $month['items'][0];
    }

    /**
     * Živé (nezrušené) vstupy náhrad podle významu.
     *
     * @return array<string,array<string,mixed>>
     */
    private function inputs(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.*, component.code AS component_code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.status <> "cancelled"
                AND input.external_id LIKE ?
              ORDER BY input.external_id',
        );
        $stmt->execute([$this->supplierId, $employmentId, PayrollImportAbsenceCompensationMaterializer::EXTERNAL_ID_PREFIX . '%']);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[substr((string) $row['external_id'], strrpos((string) $row['external_id'], ':') + 1)] = $row;
        }
        ksort($rows);

        return $rows;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \RuntimeException("Služba {$class} není dostupná.");
        }

        return $service;
    }

    private function payrollSupplier(): int
    {
        $pdo = $this->db->pdo();
        $supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$supplierId, $this->userId]);

        return $supplierId;
    }

    private function employment(string $code, ?string $name = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)',
        )->execute([$this->supplierId, $name ?? "Syntetická osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", ?, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code, self::MONTHLY_GROSS]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours)
             VALUES (?, ?, "2026-01-01", "2026-01-01", "40.00")',
        )->execute([$this->supplierId, $employmentId]);

        return $employmentId;
    }

    private function approvedAverage(int $employmentId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace, approved_by, approved_at)
             VALUES (?, ?, 2026, 3, 1, "actual", "2026-04-01", "2026-06-30",
                     6000000, 0, 60000, 63, 25000,
                     "supported", "approved", "cz-2026-average-earning", ?,
                     UNHEX(SHA2("synthetic", 256)), ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $employmentId,
            str_repeat('b', 64),
            json_encode(['rule' => 'synthetic-test-fixture'], JSON_THROW_ON_ERROR),
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,int> $hours */
    private function writtenBatch(int $employmentId, array $hours): int
    {
        $importId = $this->batch($employmentId, $hours);
        $written = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        self::assertSame([], $written['exceptions'], (string) json_encode($written, JSON_UNESCAPED_UNICODE));

        return $importId;
    }

    /** @param array<string,int> $hours */
    private function batch(int $employmentId, array $hours): int
    {
        $rows = [];
        $column = ord('C');
        foreach ($hours as $meaning => $millihours) {
            $rows[] = [
                'employment_id' => $employmentId,
                'meaning' => $meaning,
                'component_code' => '',
                'quantity_millihours' => $millihours,
                'amount_minor' => null,
                'source_ref' => self::FILE . '!List1!' . chr($column++) . '3',
            ];
        }
        $importId = $this->imports->insertBatch(
            $this->supplierId,
            self::JULY,
            'attendance',
            random_bytes(32),
            [['name' => self::FILE, 'sha256' => hash('sha256', 'syntetický soubor docházky')]],
            [],
            1,
            $rows,
            $this->userId,
        );
        self::assertNotNull($importId);

        return $importId;
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
