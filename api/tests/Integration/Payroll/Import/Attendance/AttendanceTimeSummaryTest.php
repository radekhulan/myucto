<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Time\PayrollEmploymentCalendarProvisioner;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportSummaryWriter;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Kalendář podle úvazku (S1) a souhrn pracovního měsíce z dávky importu
 * docházky (S2) nad izolovanou firmou v transakci, kterou tearDown vrací.
 *
 * Červenec 2026: 23 pracovních dnů Po–Pá, svátky 5. 7. (neděle) a 6. 7.
 * (pondělí) — fond plného úvazku je proto 22 × 8 h = 176 h, ne 184 h.
 */
#[Group('integration')]
final class AttendanceTimeSummaryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const JULY = '2026-07-01';
    private const FILE = 'dochazka.xlsx';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollEmploymentCalendarProvisioner $provisioner;
    private PayrollTimeImportSummaryWriter $writer;
    private PayrollTimeRepository $time;
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
        foreach (['payroll_time_month_import_summaries', 'payroll_attendance_imports', 'payroll_work_calendars'] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->provisioner = $this->service(PayrollEmploymentCalendarProvisioner::class);
        $this->writer = $this->service(PayrollTimeImportSummaryWriter::class);
        $this->time = $this->service(PayrollTimeRepository::class);
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

    public function testCalendarFromWeeklyHoursCarriesJulyFundWithHolidays(): void
    {
        $employmentId = $this->employment('ZAM-1', 'employment', '2026-01-01', '40.00');

        $result = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertTrue($result['created']);
        self::assertNull($result['issue']);
        self::assertSame(22 * 480, $result['fund_minutes'], 'Svátek 6. 7. v pondělí fond krátí o 8 h.');
        $calendar = $this->time->calendar($this->supplierId, $employmentId, self::JULY);
        self::assertNotNull($calendar);
        self::assertSame(self::JULY, $calendar['valid_from']);
        self::assertNull($calendar['valid_to']);
        self::assertSame(2400, $calendar['weekly_minutes']);
        self::assertSame([1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0], $calendar['week_pattern']);
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM payroll_calendar_days WHERE supplier_id = ? AND calendar_id = ?',
            [$this->supplierId, $calendar['id']],
        ), 'Svátky se neukládají jako výjimky, dopočítá je fond.');
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('open', $month['status'] ?? null);
        self::assertSame('entries', $month['work_source'] ?? null);
    }

    /** Nástup v průběhu měsíce a zkrácený úvazek: kalendář začíná dnem nástupu. */
    public function testPartTimeMidMonthStartAndFundCheck(): void
    {
        $employmentId = $this->employment('ZAM-2', 'employment', '2026-07-15', '20.00');

        $result = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertTrue($result['created']);
        // 15.–31. 7. je 13 pracovních dnů po 4 h.
        self::assertSame(13 * 240, $result['fund_minutes']);
        self::assertSame('2026-07-15', $this->time->calendar($this->supplierId, $employmentId, '2026-07-15')['valid_from'] ?? null);
        self::assertNull($this->provisioner->fundCheck($this->supplierId, $employmentId, self::JULY, 52_000));
        self::assertNull(
            $this->provisioner->fundCheck($this->supplierId, $employmentId, self::JULY, 88_000),
            'Fond celého měsíce (22 dnů po 4 h) je týž rozvrh, jen bez omezení na dny trvání vztahu.',
        );
        $mismatch = $this->provisioner->fundCheck($this->supplierId, $employmentId, self::JULY, 70_000);
        self::assertSame('import_fund_mismatch', $mismatch['code'] ?? null);
        self::assertStringContainsString('70.00 h', (string) $mismatch['message']);
        self::assertStringContainsString('52.00 h', (string) $mismatch['message']);
    }

    public function testAgreementGetsNoCalendar(): void
    {
        $employmentId = $this->employment('DPP-1', 'dpp', '2026-01-01', null);

        $result = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertFalse($result['created']);
        self::assertNull($result['issue']);
        self::assertSame('agreement', $result['skipped']);
        self::assertSame(0, $this->calendarCount($employmentId));
    }

    public function testMissingWeeklyHoursIsAnIssueAndCreatesNothing(): void
    {
        $employmentId = $this->employment('ZAM-3', 'employment', '2026-01-01', null);

        $result = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertFalse($result['created']);
        self::assertSame('weekly_hours_missing', $result['issue']);
        self::assertSame(0, $this->calendarCount($employmentId));
        self::assertNull($this->time->monthState($this->supplierId, $employmentId, self::JULY));
    }

    public function testAlreadyCoveredDayDoesNothing(): void
    {
        $employmentId = $this->employment('ZAM-4', 'employment', '2026-01-01', '40.00');
        $june = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, '2026-06-01', $this->userId);
        self::assertTrue($june['created']);

        $july = $this->provisioner->ensureForPeriod($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertFalse($july['created']);
        self::assertNull($july['issue']);
        self::assertSame($june['calendar_id'], $july['calendar_id']);
        self::assertSame(1, $this->calendarCount($employmentId));
        self::assertSame(22 * 480, $july['fund_minutes']);
    }

    public function testSummaryCarriesMillihoursAndSourcesUnchanged(): void
    {
        $employmentId = $this->employment('ZAM-5', 'employment', '2026-01-01', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $result = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(1, $result['written']);
        self::assertSame(0, $result['replayed']);
        self::assertSame(1, $result['calendars_created']);
        self::assertSame([], $result['exceptions']);
        self::assertSame([], $result['warnings'], 'Fond 176 h z podkladů sedí s kalendářem.');
        $summary = $this->time->importSummary($this->supplierId, $employmentId, self::JULY);
        self::assertNotNull($summary);
        self::assertSame(['fund_hours' => 176_000, 'vacation_hours' => 8_000, 'worked_hours' => 160_500], $summary['values']);
        self::assertNull($summary['worked_days'], 'Podklady dny nenesou.');
        self::assertSame($importId, $summary['attendance_import_id']);
        self::assertSame(
            ['file_sha256' => self::fileSha(), 'ref' => self::FILE . '!List1!C3'],
            $summary['sources']['worked_hours'],
        );
        self::assertArrayNotHasKey('component', $summary['sources'], 'Peníze do souhrnu hodin nepatří.');
        self::assertSame(64, strlen($summary['content_sha256']));
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('import_summary', $month['work_source'] ?? null);
        self::assertSame('open', $month['status'] ?? null, 'Souhrn měsíc neschvaluje.');
    }

    public function testManualReplacementRejectsImplicitImportAndCanRestoreOriginalImport(): void
    {
        $employmentId = $this->employment('ZAM-MANUAL', 'employment', '2026-01-01', '40.00');
        $this->makeHourly($employmentId);
        $importId = $this->batch([$this->hoursRows($employmentId, 160_501)]);
        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        $original = $this->time->importSummary($this->supplierId, $employmentId, self::JULY);
        self::assertNotNull($original);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        $service = $this->service(PayrollTimeService::class);
        $initial = $service->overview($this->supplierId, '2026-07', false, employmentId: $employmentId)['items'][0];
        self::assertSame('import_summary', $initial['summary']['work_source']);
        self::assertSame(481503, $initial['summary']['worked_duration_units']);
        self::assertNull($initial['summary']['actual_minutes']);
        $input = [
            'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
            'reason' => 'Syntetická oprava', 'replace_source' => false,
            'segments' => [['date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                'worked_duration_units' => 3000, 'overtime_duration_units' => null, 'worked_days' => null]],
        ];
        try {
            $service->saveManualSummary($this->supplierId, '2026-07', $input, $this->userId);
            self::fail('Nahrazení importu bez potvrzení se musí odmítnout.');
        } catch (\InvalidArgumentException) {
            self::assertSame('import_summary', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['work_source']);
        }
        $input['replace_source'] = true;
        $manual = $service->saveManualSummary($this->supplierId, '2026-07', $input, $this->userId);
        self::assertSame('manual_summary', $manual['month']['work_source']);
        // Opuštěný souhrn importu patří předchozí revizi měsíce.
        self::assertSame((int) $month['revision_no'] + 1, (int) $manual['month']['revision_no']);
        self::assertNull($this->time->importSummary($this->supplierId, $employmentId, self::JULY));
        self::assertSame(3000, $service->overview($this->supplierId, '2026-07', false, employmentId: $employmentId)['items'][0]['summary']['worked_duration_units']);
        try {
            $this->time->saveImportSummary($this->supplierId, $employmentId, self::JULY, $importId, $original['values'], $original['sources'], $original['content_sha256'], $this->userId);
            self::fail('Import nesmí ruční zdroj tiše přepsat.');
        } catch (\InvalidArgumentException) {
            self::assertSame('manual_summary', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['work_source']);
        }
        $restored = $service->restoreEntries($this->supplierId, '2026-07', [
            'employment_id' => $employmentId, 'row_version' => (int) $manual['month']['row_version'],
            'reason' => 'Návrat k importu', 'replace_source' => true, 'source' => 'import_summary',
        ], $this->userId);
        self::assertSame('import_summary', $restored['month']['work_source']);
        $recovered = $this->time->importSummary($this->supplierId, $employmentId, self::JULY);
        self::assertSame($original['values'], $recovered['values']);
        self::assertSame($original['content_sha256'], $recovered['content_sha256']);
        self::assertSame($original['attendance_import_id'], $recovered['attendance_import_id']);
        $history = $service->history($this->supplierId, $employmentId, '2026-07', '2026-07')['items'][0];
        self::assertSame('import_summary', $history['summary']['work_source']);
        self::assertSame(481503, $history['summary']['worked_duration_units']);
        self::assertNull($history['summary']['actual_minutes']);
    }

    public function testMonthlyImportMonthKeepsOriginalSummaryAndRefusesSourceChange(): void
    {
        $employmentId = $this->employment('ZAM-MESICNI', 'employment', '2026-01-01', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId, 160_501)]);
        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        $service = $this->service(PayrollTimeService::class);
        $item = $service->overview($this->supplierId, '2026-07', false, employmentId: $employmentId)['items'][0];
        self::assertFalse($item['hourly_remuneration_in_period']);
        self::assertSame(0, $item['summary']['actual_minutes']);
        self::assertSame([], array_filter($item['summary']['category_minutes']));
        $history = $service->history($this->supplierId, $employmentId, '2026-07', '2026-07')['items'][0];
        self::assertFalse($history['hourly_remuneration_in_period']);
        self::assertSame(0, $history['summary']['actual_minutes']);
        foreach ([
            fn () => $service->saveManualSummary($this->supplierId, '2026-07', [
                'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
                'reason' => 'Syntetická oprava', 'replace_source' => true,
                'segments' => [['date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                    'worked_duration_units' => 3000, 'overtime_duration_units' => null, 'worked_days' => null]],
            ], $this->userId),
            fn () => $service->restoreEntries($this->supplierId, '2026-07', [
                'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
                'reason' => 'Syntetický návrat', 'replace_source' => true, 'source' => 'entries',
            ], $this->userId),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Měsíčnímu vztahu se zdroj hodin importovaného měsíce měnit nesmí.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('hodinovým odměňováním', $e->getMessage());
            }
        }
        $after = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('import_summary', $after['work_source']);
        self::assertSame($month['revision_no'], $after['revision_no']);
        self::assertNotNull($this->time->importSummary($this->supplierId, $employmentId, self::JULY));
    }

    public function testHourlyImportWithActiveImportInputsRefusesSourceChange(): void
    {
        $employmentId = $this->employment('ZAM-HOD-VSTUP', 'employment', '2026-01-01', '40.00');
        $this->makeHourly($employmentId);
        $importId = $this->batch([$this->hoursRows($employmentId, 160_501)]);
        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        $component = $this->componentId('NAHRADA_MZDY_DOVOLENA');
        $employee = $this->db->pdo()->prepare('SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $employee->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor, status, source_kind, external_id, created_by)
             VALUES (?, ?, ?, ?, ?, 120000, "draft", "absence", ?, ?)',
        )->execute([$this->supplierId, (int) $employee->fetchColumn(), $employmentId, $component, self::JULY,
            "leave:attendance:2026-07:{$employmentId}:vacation_hours", $this->userId]);
        $inputId = (int) $this->db->pdo()->lastInsertId();
        $service = $this->service(PayrollTimeService::class);
        $manual = [
            'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
            'reason' => 'Syntetická oprava', 'replace_source' => true,
            'segments' => [['date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                'worked_duration_units' => 3000, 'overtime_duration_units' => null, 'worked_days' => null]],
        ];
        $restore = [
            'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
            'reason' => 'Syntetický návrat', 'replace_source' => true, 'source' => 'entries',
        ];
        foreach ([
            fn () => $service->saveManualSummary($this->supplierId, '2026-07', $manual, $this->userId),
            fn () => $service->restoreEntries($this->supplierId, '2026-07', $restore, $this->userId),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Zdroj s aktivními vstupy z importu se měnit nesmí.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('importu docházky', $e->getMessage());
            }
        }
        self::assertSame('import_summary', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['work_source']);
        $this->db->pdo()->prepare('UPDATE payroll_inputs SET status = "cancelled" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $inputId]);
        $restored = $service->restoreEntries($this->supplierId, '2026-07', $restore, $this->userId);
        self::assertTrue($restored['changed']);
        self::assertSame('entries', $restored['month']['work_source']);
        self::assertSame((int) $month['revision_no'] + 1, (int) $restored['month']['revision_no']);
        self::assertNull($this->time->importSummary($this->supplierId, $employmentId, self::JULY));
        $again = $service->restoreEntries($this->supplierId, '2026-07', $restore, $this->userId);
        self::assertFalse($again['changed']);
        self::assertSame($restored['month']['row_version'], $again['month']['row_version']);
    }

    public function testRestoringImportIsRefusedWhenMonthHasDailyEntries(): void
    {
        $employmentId = $this->employment('ZAM-HOD-ZAZNAM', 'employment', '2026-01-01', '40.00');
        $this->makeHourly($employmentId);
        $importId = $this->batch([$this->hoursRows($employmentId, 160_501)]);
        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        $service = $this->service(PayrollTimeService::class);
        $entries = $service->restoreEntries($this->supplierId, '2026-07', [
            'employment_id' => $employmentId, 'row_version' => (int) $month['row_version'],
            'reason' => 'Syntetický návrat', 'replace_source' => true, 'source' => 'entries',
        ], $this->userId);
        $saved = $service->saveEntry($this->supplierId, [
            'employment_id' => $employmentId, 'starts_at' => '2026-07-06T08:00:00+02:00', 'ends_at' => '2026-07-06T16:00:00+02:00',
            'timezone' => 'Europe/Prague', 'category' => 'regular', 'break_minutes' => 30, 'row_version' => 0,
            'month_row_version' => (int) $entries['month']['row_version'], 'supersedes_id' => null,
        ], $this->userId);
        try {
            $service->restoreEntries($this->supplierId, '2026-07', [
                'employment_id' => $employmentId, 'row_version' => (int) $saved['month']['row_version'],
                'reason' => 'Syntetický návrat k importu', 'replace_source' => true, 'source' => 'import_summary',
            ], $this->userId);
            self::fail('Souhrn importu nesmí vedle denních záznamů vzniknout jako druhý zdroj.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('časové záznamy', $e->getMessage());
        }
        self::assertSame('entries', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['work_source']);
    }

    public function testReplayDoesNotDuplicate(): void
    {
        $employmentId = $this->employment('ZAM-6', 'employment', '2026-01-01', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        $second = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $second['written']);
        self::assertSame(1, $second['replayed']);
        self::assertSame([], $second['exceptions']);
        self::assertSame(1, $this->summaryCount($employmentId));
        self::assertSame(1, $this->calendarCount($employmentId));
    }

    /**
     * Opravná dávka do otevřeného měsíce: souhrn se nepřepíše, vznikne nová
     * revize měsíce s novým souhrnem a původní zůstane jako auditní stopa.
     */
    public function testSecondBatchReplacesTheSummaryInANewMonthRevision(): void
    {
        $employmentId = $this->employment('ZAM-7', 'employment', '2026-01-01', '40.00');
        $first = $this->batch([$this->hoursRows($employmentId)]);
        $this->writer->writeFromBatch($this->supplierId, $first, $this->userId);
        $second = $this->batch([$this->hoursRows($employmentId, 150_000)]);

        $result = $this->writer->writeFromBatch($this->supplierId, $second, $this->userId);

        self::assertSame(1, $result['written'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $result['exceptions']);
        $summary = $this->time->importSummary($this->supplierId, $employmentId, self::JULY);
        self::assertSame($second, $summary['attendance_import_id'] ?? null);
        self::assertSame(150_000, $summary['values']['worked_hours'] ?? null);
        self::assertSame(2, $summary['time_month_revision_no'] ?? null);
        self::assertSame(2, $this->summaryCount($employmentId));
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('open', $month['status'] ?? null, 'Opravný souhrn měsíc neschvaluje.');
        self::assertSame(2, (int) ($month['revision_no'] ?? 0));
    }

    public function testExistingTimeEntriesBlockTheSummaryAndNothingIsWritten(): void
    {
        $employmentId = $this->employment('ZAM-8', 'employment', '2026-01-01', '40.00');
        $this->time->saveEntry(
            $this->supplierId,
            $employmentId,
            self::JULY,
            'regular',
            '2026-07-07 06:00:00',
            '2026-07-07 14:00:00',
            'Europe/Prague',
            0,
            'manual',
            null,
            random_bytes(32),
            null,
            0,
            0,
            $this->userId,
        );
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $result = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $result['written']);
        self::assertSame($employmentId, $result['exceptions'][0]['employment_id'] ?? null);
        self::assertStringContainsString('druhým zdrojem', $result['exceptions'][0]['message'] ?? '');
        self::assertSame(0, $this->summaryCount($employmentId));
        self::assertSame(0, $this->calendarCount($employmentId), 'Kalendář téhož vztahu se vrátil se souhrnem.');
        self::assertSame('entries', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['work_source'] ?? null);
    }

    public function testApprovedMonthIsAnException(): void
    {
        $employmentId = $this->employment('ZAM-9', 'employment', '2026-01-01', '40.00');
        $this->approvedMonth($employmentId);
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $result = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(0, $result['written']);
        self::assertStringContainsString('schválený', $result['exceptions'][0]['message'] ?? '');
        self::assertSame(0, $this->summaryCount($employmentId));
    }

    public function testOneFailingEmploymentDoesNotStopTheOthers(): void
    {
        $blocked = $this->employment('ZAM-10', 'employment', '2026-01-01', '40.00');
        $open = $this->employment('ZAM-11', 'employment', '2026-01-01', '40.00');
        $withoutTerms = $this->employment('ZAM-12', 'employment', '2026-01-01', null);
        $this->approvedMonth($blocked);
        $importId = $this->batch([
            $this->hoursRows($blocked),
            $this->hoursRows($open),
            $this->hoursRows($withoutTerms),
        ]);

        $result = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(2, $result['written']);
        self::assertSame([$blocked], array_column($result['exceptions'], 'employment_id'));
        self::assertSame(1, $this->summaryCount($open));
        self::assertSame(1, $this->summaryCount($withoutTerms), 'Chybějící úvazek souhrn nezastaví.');
        self::assertSame(
            [[$withoutTerms, 'weekly_hours_missing']],
            array_map(static fn (array $w): array => [$w['employment_id'], $w['code']], $result['warnings']),
        );
    }

    public function testFundMismatchIsOnlyAWarning(): void
    {
        $employmentId = $this->employment('ZAM-13', 'employment', '2026-01-01', '40.00');
        // Červenec 2026: kalendář 176 h, se svátkem 6. 7. 184 h (to je týž rozvrh,
        // kontrola ho uzná). Skutečný nesoulad je fond, který nesedí ani se svátkem.
        $importId = $this->batch([$this->hoursRows($employmentId, 160_500, 192_000)]);

        $result = $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);

        self::assertSame(1, $result['written']);
        self::assertSame(['import_fund_mismatch'], array_column($result['warnings'], 'code'));
    }

    public function testTenantIsolation(): void
    {
        $employmentId = $this->employment('ZAM-14', 'employment', '2026-01-01', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);
        $other = $this->payrollSupplier();

        try {
            $this->writer->writeFromBatch($other, $importId, $this->userId);
            self::fail('Cizí firma nesmí zapsat souhrn z dávky jiné firmy.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nebyla nalezena', $e->getMessage());
        }
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM payroll_time_month_import_summaries WHERE supplier_id IN (?, ?)',
            [$this->supplierId, $other],
        ));
        self::assertNull($this->time->importSummary($other, $employmentId, self::JULY));

        $this->writer->writeFromBatch($this->supplierId, $importId, $this->userId);
        self::assertNull($this->time->importSummary($other, $employmentId, self::JULY));
        self::assertSame(1, $this->summaryCount($employmentId));
    }

    /** Opačný směr téhož pravidla: do měsíce ze souhrnu se časový záznam nepřidá. */
    public function testTimeEntryIsRefusedInImportSummaryMonth(): void
    {
        $employmentId = $this->employment('ZAM-15', 'employment', '2026-01-01', '40.00');
        $this->writer->writeFromBatch($this->supplierId, $this->batch([$this->hoursRows($employmentId)]), $this->userId);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('souhrnu importu');
        $this->time->saveEntry(
            $this->supplierId,
            $employmentId,
            self::JULY,
            'regular',
            '2026-07-07 06:00:00',
            '2026-07-07 14:00:00',
            'Europe/Prague',
            0,
            'manual',
            null,
            random_bytes(32),
            null,
            0,
            (int) ($month['row_version'] ?? 0),
            $this->userId,
        );
    }

    public function testSummaryIsImmutable(): void
    {
        $employmentId = $this->employment('ZAM-16', 'employment', '2026-01-01', '40.00');
        $this->writer->writeFromBatch($this->supplierId, $this->batch([$this->hoursRows($employmentId)]), $this->userId);
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT immutable_probe');

        try {
            $pdo->prepare('UPDATE payroll_time_month_import_summaries SET worked_days = 1 WHERE supplier_id = ?')
                ->execute([$this->supplierId]);
            self::fail('Souhrn se nesmí přepsat.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT immutable_probe');
        }
    }

    public function testApplyWritesTheSummaryOnlyWhenAsked(): void
    {
        $jana = $this->employment('ZAM-J', 'employment', '2026-01-01', '40.00', 'Jana Testovací');
        $petr = $this->employment('ZAM-P', 'employment', '2026-01-01', '40.00', 'Petr Zkušební');
        $service = $this->service(AttendanceImportService::class);
        $files = AttendanceFixture::scenario();
        $links = [
            ['person_key' => 'jana testovaci', 'employment_id' => $jana],
            ['person_key' => 'petr zkusebni', 'employment_id' => $petr],
        ];

        $plain = $service->apply($this->supplierId, AttendanceFixture::PERIOD, $files, null, $links, false, false, $this->userId);
        self::assertNull($plain['time_summary']);
        self::assertSame(0, $this->summaryCount($jana));

        $withSummary = $service->apply(
            $this->supplierId,
            AttendanceFixture::PERIOD,
            $files,
            null,
            $links,
            false,
            false,
            $this->userId,
            writeTimeSummary: true,
        );

        self::assertTrue($withSummary['replayed'], 'Volba souhrnu dávku nemění, jen ji doplní.');
        self::assertSame(2, $withSummary['time_summary']['written'] ?? null);
        $summary = $this->time->importSummary($this->supplierId, $jana, AttendanceFixture::PERIOD . '-01');
        self::assertSame(168_000, $summary['values']['worked_hours'] ?? null);
        self::assertSame('provoz.xlsx!vstup!B3', $summary['sources']['worked_hours']['ref'] ?? null);
        self::assertSame(
            array_column($files, 'sha256', 'name')['provoz.xlsx'],
            $summary['sources']['worked_hours']['file_sha256'] ?? null,
        );
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

    private function employment(
        string $code,
        string $relationType,
        string $startDate,
        ?string $weeklyHours,
        ?string $name = null,
    ): int {
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
             VALUES (?, ?, ?, ?, "active", ?, ?, 4200000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code, $relationType, $startDate, $startDate]);
        $employmentId = (int) $pdo->lastInsertId();
        if ($weeklyHours !== null) {
            $pdo->prepare(
                'INSERT INTO payroll_employment_terms
                    (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours)
                 VALUES (?, ?, ?, ?, ?)',
            )->execute([$this->supplierId, $employmentId, $startDate, $startDate, $weeklyHours]);
        }

        return $employmentId;
    }

    private function makeHourly(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET remuneration_type = "hourly", hourly_rate_minor = 20000, monthly_gross_minor = NULL,
                    remuneration_basis_kind = "wage_statement", remuneration_basis = "Syntetický výměr"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
    }

    private function componentId(string $code): int
    {
        $this->service(\MyInvoice\Repository\Payroll\PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    private function approvedMonth(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, approved_by, approved_at, last_changed_by)
             VALUES (?, ?, ?, "approved", ?, NOW(), ?)',
        )->execute([$this->supplierId, $employmentId, self::JULY, $this->userId, $this->userId]);
    }

    /**
     * Syntetické řádky dávky: hodiny tří významů a jedna peněžní složka,
     * která do souhrnu hodin patřit nesmí.
     *
     * @return list<array{employment_id:int,meaning:string,component_code:string,quantity_millihours:?int,amount_minor:?int,source_ref:string}>
     */
    private function hoursRows(int $employmentId, int $worked = 160_500, int $fund = 176_000): array
    {
        return [
            ['employment_id' => $employmentId, 'meaning' => 'worked_hours', 'component_code' => '',
                'quantity_millihours' => $worked, 'amount_minor' => null, 'source_ref' => self::FILE . '!List1!C3'],
            ['employment_id' => $employmentId, 'meaning' => 'vacation_hours', 'component_code' => '',
                'quantity_millihours' => 8_000, 'amount_minor' => null, 'source_ref' => self::FILE . '!List1!D3'],
            ['employment_id' => $employmentId, 'meaning' => 'fund_hours', 'component_code' => '',
                'quantity_millihours' => $fund, 'amount_minor' => null, 'source_ref' => self::FILE . '!List1!E3'],
            ['employment_id' => $employmentId, 'meaning' => 'component', 'component_code' => 'ODMENA',
                'quantity_millihours' => null, 'amount_minor' => 150_000, 'source_ref' => self::FILE . '!List1!F3'],
        ];
    }

    /** @param list<list<array<string,mixed>>> $rowGroups */
    private function batch(array $rowGroups): int
    {
        $rows = array_merge(...$rowGroups);
        $importId = $this->imports->insertBatch(
            $this->supplierId,
            self::JULY,
            'attendance',
            random_bytes(32),
            [['name' => self::FILE, 'sha256' => self::fileSha()]],
            [],
            count($rowGroups),
            $rows,
            $this->userId,
        );
        self::assertNotNull($importId);

        return $importId;
    }

    private static function fileSha(): string
    {
        return hash('sha256', 'syntetický soubor docházky');
    }

    private function calendarCount(int $employmentId): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM payroll_work_calendars WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        );
    }

    private function summaryCount(int $employmentId): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM payroll_time_month_import_summaries WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        );
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
