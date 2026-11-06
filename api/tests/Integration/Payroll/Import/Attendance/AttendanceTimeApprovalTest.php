<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Action\Payroll\PayrollAttendanceImportAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeInputMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Souhrn práce z importního souhrnu (S3) a hromadné schválení importovaných
 * měsíců (S4) nad izolovanou firmou v transakci, kterou tearDown vrací.
 *
 * Červenec 2026: fond plného úvazku 176 h (svátek 6. 7. v pondělí).
 */
#[Group('integration')]
final class AttendanceTimeApprovalTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const JULY = '2026-07-01';
    private const FILE = 'dochazka.xlsx';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollTimeImportApprovalService $approvals;
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
        foreach ([
            'payroll_time_month_import_summaries',
            'payroll_attendance_imports',
            'payroll_jmhz_work_month_revisions',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $this->approvals = $this->service(PayrollTimeImportApprovalService::class);
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

    public function testCleanMonthIsApprovedFromImportSummaryWithoutTimeEntries(): void
    {
        $employmentId = $this->employment('ZAM-1', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $result = $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);

        self::assertSame(1, $result['approved'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $result['written']);
        self::assertSame([], $result['exceptions']);
        self::assertSame(['worked_days_not_provided'], array_column($result['warnings'], 'code'));
        self::assertSame('approved', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['status'] ?? null);
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM payroll_time_entries WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ), 'Souhrn se na časové záznamy nerozkládá.');

        $revision = $this->time->jmhzWorkSummaryRevision($this->supplierId, $employmentId, self::JULY);
        self::assertNotNull($revision);
        self::assertSame('jmhz-work-month.v8', $revision['derivation_version']);
        self::assertNull($revision['worked_days'], 'Dny podklady nenesou a nedopočítávají se.');
        self::assertSame(160_500, $revision['worked_millihours']);
        // 10259 i 10260 počítají svátek 6. 7. jako pracovní den (pokyny MPSV
        // k 10259: „včetně svátků v jinak pracovní dny") — stejně jako u
        // souhrnu ze směn.
        self::assertSame(184_000, $revision['standard_fund_millihours']);
        self::assertSame(184_000, $revision['agreed_fund_millihours']);
        self::assertSame(4_000, $revision['weekly_work_centihours']);
        self::assertSame(31, $revision['evidence_days']);
        self::assertSame(8_000, $revision['vacation_millihours']);
        self::assertSame(8_000, $revision['unworked_total_millihours']);
        self::assertSame(8_000, $revision['unworked_paid_millihours']);
        self::assertSame(1, $revision['unworked_hours_occurred']);
        self::assertSame("Schváleno hromadně z dávky importu #{$importId}", $revision['confirmation_note']);
        self::assertSame('not_provided_by_import', $revision['provenance']['attributes']['10267'] ?? null);
        self::assertSame('import_bulk_confirmation', $revision['provenance']['attributes']['10268'] ?? null);
        self::assertSame('import_bulk_confirmation', $revision['provenance']['confirmation_kind'] ?? null);

        $source = json_decode((string) $this->scalar(
            'SELECT source_snapshot_json FROM payroll_jmhz_work_month_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $revision['id']],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($source);
        self::assertArrayNotHasKey('time_entries', $source);
        self::assertSame($importId, $source['import_summary']['attendance_import_id'] ?? null);
        self::assertSame(self::FILE . '!List1!C3', $source['import_summary']['sources']['worked_hours']['ref'] ?? null);
    }

    public function testThreeEmploymentsApproveOnlyTheCleanOne(): void
    {
        $clean = $this->employment('ZAM-2', '40.00');
        $sick = $this->employment('ZAM-3', '40.00');
        $withoutTerms = $this->employment('ZAM-4', null);
        $importId = $this->batch([
            $this->hoursRows($clean),
            $this->hoursRows($sick, ['sick_hours' => 16_000]),
            $this->hoursRows($withoutTerms),
        ]);

        $result = $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);

        self::assertSame(1, $result['approved'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(
            [$sick => 'absence_hours_without_dates', $withoutTerms => 'weekly_hours_missing'],
            array_column($result['exceptions'], 'code', 'employment_id'),
        );
        $sickException = $result['exceptions'][0];
        self::assertSame('ZAM-3', $sickException['employment_code']);
        self::assertSame('Syntetická osoba ZAM-3', $sickException['name']);
        self::assertStringContainsString('nemoc', $sickException['message']);
        self::assertSame('approved', $this->time->monthState($this->supplierId, $clean, self::JULY)['status'] ?? null);
        self::assertSame('open', $this->time->monthState($this->supplierId, $sick, self::JULY)['status'] ?? null);
        self::assertSame('open', $this->time->monthState($this->supplierId, $withoutTerms, self::JULY)['status'] ?? null);
        self::assertNull($this->time->jmhzWorkSummaryRevision($this->supplierId, $sick, self::JULY));
        self::assertNotContains(
            'weekly_hours_missing',
            array_column($result['warnings'], 'code'),
            'Chybějící úvazek je výjimka, ne zároveň varování.',
        );
    }

    /**
     * v6 smí mít dny NEUVEDENÉ, v4/v5 dál ne. Obě vložení míří na tutéž
     * schválenou revizi měsíce bez souhrnu, takže je rozliší jen CHECK.
     */
    public function testWorkedDaysMayBeMissingOnlyInImportSummaryVersion(): void
    {
        $employmentId = $this->employment('ZAM-5', '40.00');
        $this->approvals->applyBatch($this->supplierId, $this->batch([$this->hoursRows($employmentId)]), true, $this->userId);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        $reopened = $this->time->reopenMonth(
            $this->supplierId,
            $employmentId,
            self::JULY,
            (int) ($month['row_version'] ?? 0),
            'Syntetická kontrola omezení',
            $this->userId,
        );
        $this->time->approveMonth(
            $this->supplierId,
            $employmentId,
            self::JULY,
            (int) $reopened['row_version'],
            null,
            $this->userId,
        );
        $pdo = $this->db->pdo();

        $pdo->exec('SAVEPOINT v6_probe');
        self::assertSame(1, $this->copyRevision($employmentId, 'jmhz-work-month.v6'));
        $pdo->exec('ROLLBACK TO SAVEPOINT v6_probe');

        $pdo->exec('SAVEPOINT v5_probe');
        try {
            $this->copyRevision($employmentId, 'jmhz-work-month.v5');
            self::fail('Souhrn v5 bez odpracovaných dnů se nesmí uložit.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('chk_payroll_jmhz_work_month_worked_breakdown', $e->getMessage());
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT v5_probe');
        }
    }

    public function testRepeatedApprovalOfTheSameBatchIsIdempotent(): void
    {
        $employmentId = $this->employment('ZAM-6', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);
        $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);

        $second = $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);

        self::assertSame(0, $second['approved']);
        self::assertSame(1, $second['already_approved']);
        self::assertSame([], $second['exceptions']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_jmhz_work_month_revisions WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    public function testMonthApprovedFromAnotherSourceIsAnException(): void
    {
        $employmentId = $this->employment('ZAM-7', '40.00');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, approved_by, approved_at, last_changed_by)
             VALUES (?, ?, ?, "approved", ?, NOW(), ?)',
        )->execute([$this->supplierId, $employmentId, self::JULY, $this->userId, $this->userId]);
        $importId = $this->batch([$this->hoursRows($employmentId)]);

        $result = $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);

        self::assertSame(0, $result['approved']);
        self::assertSame(['month_approved_other_source'], array_column($result['exceptions'], 'code'));
    }

    /**
     * Příplatky měsíce ze souhrnu importu přicházejí jako částky z docházkového
     * systému. Kdyby se do měsíce dostal časový záznam mimo repozitář,
     * materializace z docházky by týž nárok vyplatila podruhé.
     */
    public function testSurchargeMaterializationIsSkippedForImportSummaryMonth(): void
    {
        $employmentId = $this->employment('ZAM-8', '40.00');
        $this->approvals->applyBatch($this->supplierId, $this->batch([$this->hoursRows($employmentId)]), true, $this->userId);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, created_by)
             VALUES (?, ?, ?, 1, 'night', '2026-07-07 20:00:00', '2026-07-08 04:00:00',
                     'Europe/Prague', 0, 'manual', ?, ?)",
        )->execute([$this->supplierId, $employmentId, bin2hex(random_bytes(16)), random_bytes(32), $this->userId]);

        $report = $this->service(PayrollSurchargeInputMaterializer::class)
            ->materialize($this->supplierId, $employmentId, self::JULY, $this->userId);

        self::assertSame('import_summary', $report['skipped_reason'] ?? null);
        self::assertSame(0, $report['written_count']);
        self::assertSame(0, $this->countRows(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ? AND source_kind = "time"',
            [$this->supplierId, $employmentId],
        ));
    }

    public function testApprovalOptionRequiresWritingTheSummary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vyžaduje zápis souhrnu');
        $this->service(AttendanceImportService::class)->apply(
            $this->supplierId,
            AttendanceFixture::PERIOD,
            AttendanceFixture::scenario(),
            null,
            [],
            false,
            false,
            $this->userId,
            approveCleanTimeMonths: true,
        );
    }

    public function testApplyWithApprovalReturnsTimeApprovalAndInputCounts(): void
    {
        $jana = $this->employment('ZAM-J', '40.00', 'Jana Testovací');
        $petr = $this->employment('ZAM-P', '40.00', 'Petr Zkušební');

        $result = $this->service(AttendanceImportService::class)->apply(
            $this->supplierId,
            AttendanceFixture::PERIOD,
            AttendanceFixture::scenario(),
            null,
            [
                ['person_key' => 'jana testovaci', 'employment_id' => $jana],
                ['person_key' => 'petr zkusebni', 'employment_id' => $petr],
            ],
            false,
            false,
            $this->userId,
            writeTimeSummary: true,
            approveCleanTimeMonths: true,
        );

        self::assertSame(['updated', 'overridden'], array_values(array_intersect(
            ['updated', 'overridden'],
            array_keys($result['inputs']),
        )));
        $approval = $result['time_approval'];
        self::assertIsArray($approval);
        self::assertSame(2, $result['time_summary']['written'] ?? null);
        self::assertSame(
            'absence_hours_without_dates',
            array_column($approval['exceptions'], 'code', 'employment_id')[$petr] ?? null,
            'Petr má v podkladech nemoc bez dat.',
        );
        self::assertSame(2, $approval['approved'] + count($approval['exceptions']));
    }

    public function testEndpointApprovesBatchAndReportsMissingOne(): void
    {
        $employmentId = $this->employment('ZAM-9', '40.00');
        $importId = $this->batch([$this->hoursRows($employmentId)]);
        $action = $this->service(PayrollAttendanceImportAction::class);

        $response = $action->approveTimeMonths(
            $this->request("/api/payroll/time/imports/attendance/{$importId}/apply"),
            new Response(),
            ['id' => (string) $importId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->json($response)['time_approval']['approved'] ?? null);
        $missing = $action->approveTimeMonths(
            $this->request('/api/payroll/time/imports/attendance/999999999/apply'),
            new Response(),
            ['id' => '999999999'],
        );
        self::assertSame(404, $missing->getStatusCode());
    }

    /**
     * Schválení měsíců při použití dávky je totéž co dodatečné schválení,
     * takže samotné právo na vstupy nestačí.
     */
    public function testApplyWithMonthApprovalRequiresApprovePermission(): void
    {
        $action = $this->service(PayrollAttendanceImportAction::class);
        $inputsOnly = new EffectiveRole(45, 'Jen vstupy', 'staff', true, [
            'payroll.inputs.write' => AccessLevel::WRITE->value,
        ]);
        $request = $this->request('/api/payroll/imports/attendance/apply')
            ->withAttribute('auth.effective_role', $inputsOnly);

        $denied = $action->apply(
            $request->withParsedBody(['period' => '2026-07', 'approve_clean_time_months' => true]),
            new Response(),
        );
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());

        $withoutApproval = $action->apply($request->withParsedBody(['period' => '2026-07']), new Response());
        self::assertNotSame(403, $withoutApproval->getStatusCode(), (string) $withoutApproval->getBody());
    }

    /**
     * Výjimka hromadného schválení → účetní opraví podklady → opravná dávka.
     * Opravný souhrn otevřeného měsíce vznikne v nové revizi měsíce (původní
     * zůstane jako auditní stopa) a čistý měsíc se hromadně schválí.
     */
    public function testCorrectedBatchReplacesOpenMonthSummaryAndIsApproved(): void
    {
        $employmentId = $this->employment('ZAM-10', '40.00');
        $first = $this->batch([$this->hoursRows($employmentId, ['sick_hours' => 16_000])]);
        $initial = $this->approvals->applyBatch($this->supplierId, $first, true, $this->userId);
        self::assertSame(['absence_hours_without_dates'], array_column($initial['exceptions'], 'code'));
        self::assertStringContainsString('importujte znovu', $initial['exceptions'][0]['message']);
        self::assertStringContainsString('schvalte ručně', $initial['exceptions'][0]['message']);

        $corrected = $this->batch([$this->hoursRows($employmentId)]);
        $result = $this->approvals->applyBatch($this->supplierId, $corrected, true, $this->userId);

        self::assertSame(1, $result['written'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(1, $result['approved']);
        self::assertSame([], $result['exceptions']);
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('approved', $month['status'] ?? null);
        self::assertSame('import_summary', $month['work_source'] ?? null);
        self::assertSame(2, (int) ($month['revision_no'] ?? 0), 'Opravný souhrn patří do nové revize měsíce.');
        self::assertSame($corrected, $this->time->importSummary($this->supplierId, $employmentId, self::JULY)['attendance_import_id'] ?? null);
        self::assertSame(
            [[1, $first], [2, $corrected]],
            array_map(
                static fn (array $row): array => [(int) $row['time_month_revision_no'], (int) $row['attendance_import_id']],
                $this->rows(
                    'SELECT time_month_revision_no, attendance_import_id FROM payroll_time_month_import_summaries
                      WHERE supplier_id = ? AND employment_id = ? ORDER BY time_month_revision_no',
                    [$this->supplierId, $employmentId],
                ),
            ),
            'Původní souhrn zůstává jako auditní stopa.',
        );
        self::assertStringContainsString(
            "č. {$corrected}",
            (string) $this->scalar(
                'SELECT reason FROM payroll_time_month_events
                  WHERE supplier_id = ? AND time_month_id = ? AND revision_no = 2 AND action = "changed"
                  ORDER BY id LIMIT 1',
                [$this->supplierId, (int) $month['id']],
            ),
        );
        $revision = $this->time->jmhzWorkSummaryRevision($this->supplierId, $employmentId, self::JULY);
        self::assertNotNull($revision);
        self::assertSame(2, $revision['time_month_revision_no']);
        $source = json_decode((string) $this->scalar(
            'SELECT source_snapshot_json FROM payroll_jmhz_work_month_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $revision['id']],
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($corrected, $source['import_summary']['attendance_import_id'] ?? null);
    }

    public function testCorrectedBatchIsApprovedThroughTheApplyEndpoint(): void
    {
        $employmentId = $this->employment('ZAM-11', '40.00');
        $this->approvals->applyBatch(
            $this->supplierId,
            $this->batch([$this->hoursRows($employmentId, ['sick_hours' => 16_000])]),
            true,
            $this->userId,
        );
        $corrected = $this->batch([$this->hoursRows($employmentId)]);

        $response = $this->service(PayrollAttendanceImportAction::class)->approveTimeMonths(
            $this->request("/api/payroll/time/imports/attendance/{$corrected}/apply"),
            new Response(),
            ['id' => (string) $corrected],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $approval = $this->json($response)['time_approval'] ?? [];
        self::assertSame(1, $approval['approved'] ?? null, (string) json_encode($approval, JSON_UNESCAPED_UNICODE));
        self::assertSame('approved', $this->time->monthState($this->supplierId, $employmentId, self::JULY)['status'] ?? null);
    }

    public function testCorrectedBatchDoesNotOverwriteApprovedMonth(): void
    {
        $employmentId = $this->employment('ZAM-12', '40.00');
        $first = $this->batch([$this->hoursRows($employmentId)]);
        $this->approvals->applyBatch($this->supplierId, $first, true, $this->userId);
        $revisionBefore = $this->time->jmhzWorkSummaryRevision($this->supplierId, $employmentId, self::JULY);

        $result = $this->approvals->applyBatch(
            $this->supplierId,
            $this->batch([$this->hoursRows($employmentId, ['overtime_hours' => 2_000])]),
            true,
            $this->userId,
        );

        self::assertSame(0, $result['written']);
        self::assertSame(0, $result['approved']);
        self::assertSame(['month_approved_other_source'], array_column($result['exceptions'], 'code'));
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('approved', $month['status'] ?? null);
        self::assertSame(1, (int) ($month['revision_no'] ?? 0));
        self::assertSame($first, $this->time->importSummary($this->supplierId, $employmentId, self::JULY)['attendance_import_id'] ?? null);
        self::assertSame($revisionBefore, $this->time->jmhzWorkSummaryRevision($this->supplierId, $employmentId, self::JULY));
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_time_month_import_summaries WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
    }

    /**
     * Časový záznam, který se do měsíce ze souhrnu dostal mimo repozitář, je
     * druhý zdroj téže doby. Opravný souhrn ho nepřepíše ani s ním nesloučí.
     */
    public function testCorrectedBatchDoesNotOverwriteMonthWithTimeEntries(): void
    {
        $employmentId = $this->employment('ZAM-13', '40.00');
        $first = $this->batch([$this->hoursRows($employmentId, ['sick_hours' => 16_000])]);
        $this->approvals->applyBatch($this->supplierId, $first, true, $this->userId);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, revision_no, category,
                 starts_at_utc, ends_at_utc, timezone_name, break_minutes,
                 source_kind, source_hash, created_by)
             VALUES (?, ?, ?, 1, 'regular', '2026-07-07 04:00:00', '2026-07-07 12:00:00',
                     'Europe/Prague', 0, 'manual', ?, ?)",
        )->execute([$this->supplierId, $employmentId, bin2hex(random_bytes(16)), random_bytes(32), $this->userId]);

        $result = $this->approvals->applyBatch(
            $this->supplierId,
            $this->batch([$this->hoursRows($employmentId)]),
            true,
            $this->userId,
        );

        self::assertSame(0, $result['written']);
        self::assertSame(0, $result['approved']);
        self::assertSame(['time_entries_present'], array_column($result['exceptions'], 'code'));
        $month = $this->time->monthState($this->supplierId, $employmentId, self::JULY);
        self::assertSame('open', $month['status'] ?? null);
        self::assertSame(1, (int) ($month['revision_no'] ?? 0));
        self::assertSame($first, $this->time->importSummary($this->supplierId, $employmentId, self::JULY)['attendance_import_id'] ?? null);
    }

    /** Opakované použití starší dávky nesmí vrátit měsíc k podkladům, které oprava nahradila. */
    public function testOlderBatchDoesNotReplaceNewerSummary(): void
    {
        $employmentId = $this->employment('ZAM-14', '40.00');
        $older = $this->batch([$this->hoursRows($employmentId, ['sick_hours' => 16_000])]);
        $this->approvals->applyBatch($this->supplierId, $older, true, $this->userId);
        $newer = $this->batch([$this->hoursRows($employmentId, ['sick_hours' => 8_000])]);
        self::assertSame(1, $this->approvals->applyBatch($this->supplierId, $newer, true, $this->userId)['written']);

        $result = $this->approvals->applyBatch($this->supplierId, $older, true, $this->userId);

        self::assertSame(0, $result['written']);
        self::assertSame(['summary_not_written'], array_column($result['exceptions'], 'code'));
        self::assertStringContainsString("č. {$newer}", $result['exceptions'][0]['message']);
        self::assertSame($newer, $this->time->importSummary($this->supplierId, $employmentId, self::JULY)['attendance_import_id'] ?? null);
        self::assertSame(2, (int) ($this->time->monthState($this->supplierId, $employmentId, self::JULY)['revision_no'] ?? 0));
    }

    private function copyRevision(int $employmentId, string $version): int
    {
        $columns = 'supplier_id, employment_id, time_month_id, period_start, spec_package_id,
            spec_manifest_sha256, scenario_catalog_key, scenario_manifest_sha256,
            control_catalog_key, control_manifest_sha256, source_snapshot_json,
            source_snapshot_sha256, standard_fund_millihours, agreed_fund_millihours,
            weekly_work_centihours, evidence_days, worked_millihours, worked_days,
            overtime_millihours, conditional_blocks_confirmed, unworked_hours_occurred,
            work_obstacles_occurred, unworked_total_millihours, unworked_paid_millihours,
            dpn_without_employer_compensation_millihours, dpn_with_employer_compensation_millihours,
            vacation_millihours, care_millihours, employee_obstacle_paid_millihours,
            employer_obstacle_millihours, maternity_millihours, paternity_millihours,
            parental_millihours, unpaid_leave_millihours, unexcused_millihours,
            compensatory_time_off_millihours, confirmation_note, provenance_json,
            summary_sha256, approved_by, approved_at';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_jmhz_work_month_revisions
                ({$columns}, time_month_revision_no, derivation_version)
             SELECT {$columns}, time_month_revision_no + 1, ?
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ? AND time_month_revision_no = 1",
        );
        $stmt->execute([$version, $this->supplierId, $employmentId]);

        return $stmt->rowCount();
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

    private function employment(string $code, ?string $weeklyHours, ?string $name = null): int
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
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code]);
        $employmentId = (int) $pdo->lastInsertId();
        if ($weeklyHours !== null) {
            $pdo->prepare(
                'INSERT INTO payroll_employment_terms
                    (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours)
                 VALUES (?, ?, "2026-01-01", "2026-01-01", ?)',
            )->execute([$this->supplierId, $employmentId, $weeklyHours]);
        }

        return $employmentId;
    }

    /**
     * Syntetické řádky dávky: odpracováno 160,5 h, dovolená 8 h, fond 176 h
     * a peněžní složka, která do souhrnu hodin nepatří.
     *
     * @param array<string,int> $extraHours
     * @return list<array{employment_id:int,meaning:string,component_code:string,quantity_millihours:?int,amount_minor:?int,source_ref:string}>
     */
    private function hoursRows(int $employmentId, array $extraHours = []): array
    {
        $rows = [];
        $column = 'C';
        foreach (['worked_hours' => 160_500, 'vacation_hours' => 8_000, 'fund_hours' => 176_000] + $extraHours as $meaning => $millihours) {
            $rows[] = [
                'employment_id' => $employmentId,
                'meaning' => $meaning,
                'component_code' => '',
                'quantity_millihours' => $millihours,
                'amount_minor' => null,
                'source_ref' => self::FILE . "!List1!{$column}3",
            ];
            $column = str_increment($column);
        }
        $rows[] = [
            'employment_id' => $employmentId,
            'meaning' => 'component',
            'component_code' => 'ODMENA',
            'quantity_millihours' => null,
            'amount_minor' => 150_000,
            'source_ref' => self::FILE . "!List1!{$column}3",
        ];

        return $rows;
    }

    /** @param list<list<array<string,mixed>>> $rowGroups */
    private function batch(array $rowGroups): int
    {
        $importId = $this->imports->insertBatch(
            $this->supplierId,
            self::JULY,
            'attendance',
            random_bytes(32),
            [['name' => self::FILE, 'sha256' => hash('sha256', 'syntetický soubor docházky')]],
            [],
            count($rowGroups),
            array_merge(...$rowGroups),
            $this->userId,
        );
        self::assertNotNull($importId);

        return $importId;
    }

    private function request(string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        return (int) $this->scalar($sql, $params);
    }
}
