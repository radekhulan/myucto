<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollTimeApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollTimeAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollTimeAction::class);
        foreach ([
            'payroll_work_calendars',
            'payroll_shifts',
            'payroll_time_entries',
            'payroll_time_months',
            'payroll_time_imports',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace MZ-06 neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, 42000, 0, 1)'
        )->execute([
            $this->supplierId,
            'Syntetická zaměstnankyně',
            'employee',
            'hpp',
        ]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'SYN-TIME-1', 'employment', 'active',
                     '2026-01-01', 4200000, 0)"
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testCalendarShiftAndActualTimeBuildMonthlySummary(): void
    {
        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode());
        $calendarId = (int) $this->json($calendar)['calendar']['id'];

        $shift = $this->action->shift(
            $this->request('POST', '/api/payroll/time/shifts')->withParsedBody([
                'employment_id' => $this->employmentId,
                'calendar_id' => $calendarId,
                'starts_at' => '2026-05-04T08:00:00+02:00',
                'ends_at' => '2026-05-04T16:00:00+02:00',
                'timezone' => 'Europe/Prague',
                'break_minutes' => 30,
                'remote_work' => true,
                'standby_minutes' => 0,
                'publish' => true,
                'row_version' => 0,
                'month_row_version' => 0,
                'supersedes_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $shift->getStatusCode());
        self::assertSame(2, $this->json($shift)['month']['row_version']);

        $entry = $this->saveEntry(monthVersion: 2);
        self::assertSame(201, $entry->getStatusCode());

        $month = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(200, $month->getStatusCode());
        $items = $this->json($month)['items'];
        self::assertCount(1, $items);
        self::assertSame(450, $items[0]['summary']['planned_minutes']);
        self::assertSame(450, $items[0]['summary']['actual_minutes']);
        self::assertSame(0, $items[0]['summary']['difference_minutes']);
        self::assertSame(19 * 480, $items[0]['summary']['fund_minutes']);
        self::assertTrue($items[0]['shifts'][0]['remote_work']);
    }

    public function testApprovedMonthRejectsChangesAndReopenCreatesRevision(): void
    {
        $entryResponse = $this->saveEntry(monthVersion: 0);
        $entry = $this->json($entryResponse)['entry'];
        $month = $this->json($entryResponse)['month'];

        $approved = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $month['row_version'],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $approved->getStatusCode());
        $approvedMonth = $this->json($approved)['month'];
        self::assertSame('approved', $approvedMonth['status']);

        $locked = $this->saveEntry(
            monthVersion: (int) $approvedMonth['row_version'],
            startsAt: '2026-05-05T08:00:00+02:00',
            endsAt: '2026-05-05T16:00:00+02:00',
        );
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame('payroll_time_locked', $this->json($locked)['error']['code']);

        $lockedCalendarPayload = $this->calendarPayload();
        $lockedCalendarPayload['valid_from'] = '2026-05-01';
        $lockedCalendarPayload['month_row_version'] = $approvedMonth['row_version'];
        $lockedCalendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($lockedCalendarPayload),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(409, $lockedCalendar->getStatusCode());
        self::assertSame(
            'payroll_time_locked',
            $this->json($lockedCalendar)['error']['code'],
        );

        $reopened = $this->action->reopen(
            $this->request('POST', '/api/payroll/time/months/2026-05/reopen')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $approvedMonth['row_version'],
                    'reason' => 'Oprava syntetického záznamu',
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $reopened->getStatusCode());
        $openMonth = $this->json($reopened)['month'];
        self::assertSame(2, $openMonth['revision_no']);

        $correction = $this->saveEntry(
            monthVersion: (int) $openMonth['row_version'],
            startsAt: '2026-05-04T08:00:00+02:00',
            endsAt: '2026-05-04T15:30:00+02:00',
            supersedesId: (int) $entry['id'],
            rowVersion: (int) $entry['row_version'],
        );
        self::assertSame(201, $correction->getStatusCode());
        self::assertSame(2, $this->json($correction)['entry']['revision_no']);

        $events = $this->db->pdo()->prepare(
            'SELECT action FROM payroll_time_month_events
              WHERE supplier_id = ? ORDER BY id'
        );
        $events->execute([$this->supplierId]);
        self::assertSame(
            ['created', 'changed', 'approved', 'reopened', 'changed'],
            $events->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testApprovalCanFreezeExactJmhzCoreWorkSummary(): void
    {
        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode());
        $entry = $this->saveEntry(monthVersion: 0);
        self::assertSame(201, $entry->getStatusCode());
        $monthVersion = (int) $this->json($entry)['month']['row_version'];

        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode());
        $preview = $this->json($overview)['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('168', $preview['suggestions']['agreed_fund_hours']);
        self::assertSame('7.5', $preview['suggestions']['worked_hours']);
        self::assertSame(31, $preview['suggestions']['evidence_days']);

        $approved = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
                        'standard_fund_hours' => '168',
                        'agreed_fund_hours' => '168',
                        'weekly_work_hours' => '40',
                        'worked_hours' => '7.5',
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => '',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $approved->getStatusCode());

        $stored = $this->db->pdo()->prepare(
            'SELECT standard_fund_millihours, agreed_fund_millihours,
                    weekly_work_centihours, evidence_days, worked_millihours,
                    confirmation_note, source_snapshot_sha256, summary_sha256
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stored->execute([$this->supplierId, $this->employmentId]);
        $revision = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($revision);
        self::assertSame(168000, (int) $revision['standard_fund_millihours']);
        self::assertSame(168000, (int) $revision['agreed_fund_millihours']);
        self::assertSame(4000, (int) $revision['weekly_work_centihours']);
        self::assertSame(31, (int) $revision['evidence_days']);
        self::assertSame(7500, (int) $revision['worked_millihours']);
        self::assertSame('', $revision['confirmation_note']);
        self::assertSame($preview['source_snapshot_sha256'], $revision['source_snapshot_sha256']);

        $event = $this->db->pdo()->prepare(
            "SELECT jmhz_work_summary_revision_id, jmhz_work_summary_hash
               FROM payroll_time_month_events
              WHERE supplier_id = ? AND action = 'approved'"
        );
        $event->execute([$this->supplierId]);
        $approvalEvent = $event->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($approvalEvent);
        self::assertNotNull($approvalEvent['jmhz_work_summary_revision_id']);
        self::assertSame($revision['summary_sha256'], $approvalEvent['jmhz_work_summary_hash']);

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_time_month_events
                    (supplier_id, time_month_id, revision_no, action, reason,
                     snapshot_hash, jmhz_work_summary_revision_id,
                     jmhz_work_summary_hash, actor_id)
                 SELECT supplier_id, time_month_id, time_month_revision_no,
                        "approved", "tampered", UNHEX(REPEAT("00", 32)),
                        id, REPEAT("0", 64), approved_by
                   FROM payroll_jmhz_work_month_revisions
                  WHERE supplier_id = ? AND employment_id = ?'
            )->execute([$this->supplierId, $this->employmentId]);
            self::fail('Approval event s cizím hashem souhrnu nesmí projít.');
        } catch (\PDOException $e) {
            self::assertStringContainsString(
                'Payroll time approval event does not match JMHZ summary',
                $e->getMessage(),
            );
        }

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_time_month_events
                    (supplier_id, time_month_id, revision_no, action, reason,
                     snapshot_hash, jmhz_work_summary_revision_id,
                     jmhz_work_summary_hash, actor_id)
                 SELECT supplier_id, time_month_id, time_month_revision_no,
                        "approved", "missing summary", UNHEX(REPEAT("00", 32)),
                        NULL, NULL, approved_by
                   FROM payroll_jmhz_work_month_revisions
                  WHERE supplier_id = ? AND employment_id = ?'
            )->execute([$this->supplierId, $this->employmentId]);
            self::fail('Nový approval event bez JMHZ souhrnu nesmí projít.');
        } catch (\PDOException $e) {
            self::assertStringContainsString(
                'Payroll time approval event does not match JMHZ summary',
                $e->getMessage(),
            );
        }

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_time_month_events
                    SET jmhz_work_summary_revision_id = NULL,
                        jmhz_work_summary_hash = NULL
                  WHERE supplier_id = ? AND action = "approved"'
            )->execute([$this->supplierId]);
            self::fail('Approval event musí zůstat neměnný.');
        } catch (\PDOException $e) {
            self::assertStringContainsString(
                'Payroll time month events are immutable',
                $e->getMessage(),
            );
        }
    }

    public function testJmhzConditionalWorkBlocksAreExplicitAndFailClosed(): void
    {
        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode());
        $entry = $this->saveEntry(monthVersion: 0);
        self::assertSame(201, $entry->getStatusCode());
        $monthVersion = (int) $this->json($entry)['month']['row_version'];
        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $preview = $this->json($overview)['items'][0]['jmhz_work_summary']['preview'];
        $core = [
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'standard_fund_hours' => '168',
            'agreed_fund_hours' => '168',
            'weekly_work_hours' => '40',
            'worked_hours' => '7.5',
            'confirmation_note' => 'Potvrzeno ze syntetické docházky a absencí.',
        ];

        $missingDecision = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => $core,
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $missingDecision->getStatusCode());

        $orphanObstacles = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => $core + [
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => true,
                        'employee_obstacle_paid_hours' => '8',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $orphanObstacles->getStatusCode());

        $detailWithoutInteraction = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => $core + [
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'vacation_hours' => '8',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $detailWithoutInteraction->getStatusCode());

        $invalidCases = [
            'boolean_as_string' => [
                'unworked_hours_occurred' => 'false',
                'work_obstacles_occurred' => false,
            ],
            'missing_total' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => false,
            ],
            'empty_optional_value' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => false,
                'unworked_total_hours' => '8',
                'vacation_hours' => '',
            ],
            'obstacle_without_value' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => true,
                'unworked_total_hours' => '8',
            ],
            'vacation_above_paid' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => false,
                'unworked_total_hours' => '16',
                'unworked_paid_hours' => '7.999',
                'vacation_hours' => '8',
            ],
            'obstacle_above_fund' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => true,
                'unworked_total_hours' => '169',
                'employee_obstacle_paid_hours' => '168.001',
            ],
            'conditional_value_above_product_cap' => [
                'unworked_hours_occurred' => true,
                'work_obstacles_occurred' => false,
                'unworked_total_hours' => '100000',
            ],
            'core_value_above_product_cap' => [
                'standard_fund_hours' => '10000',
                'unworked_hours_occurred' => false,
                'work_obstacles_occurred' => false,
            ],
        ];
        foreach ($invalidCases as $name => $invalid) {
            $response = $this->action->approve(
                $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                    ->withParsedBody([
                        'employment_id' => $this->employmentId,
                        'row_version' => $monthVersion,
                        'jmhz_work_summary' => array_replace($core, $invalid),
                    ]),
                new Response(),
                ['period' => '2026-05'],
            );
            self::assertSame(422, $response->getStatusCode(), $name);
        }

        $approved = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => $core + [
                        'unworked_hours_occurred' => true,
                        'work_obstacles_occurred' => true,
                        'unworked_total_hours' => '80',
                        'unworked_paid_hours' => '0',
                        'dpn_without_employer_compensation_hours' => null,
                        'dpn_with_employer_compensation_hours' => '80',
                        'vacation_hours' => null,
                        'care_hours' => null,
                        'employee_obstacle_paid_hours' => '80',
                        'employer_obstacle_hours' => null,
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $approved->getStatusCode());

        $stored = $this->db->pdo()->prepare(
            'SELECT derivation_version, conditional_blocks_confirmed,
                    unworked_hours_occurred, work_obstacles_occurred,
                    unworked_total_millihours, unworked_paid_millihours,
                    vacation_millihours, employee_obstacle_paid_millihours
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stored->execute([$this->supplierId, $this->employmentId]);
        $revision = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($revision);
        self::assertSame('jmhz-work-month.v2', $revision['derivation_version']);
        self::assertSame(1, (int) $revision['conditional_blocks_confirmed']);
        self::assertSame(1, (int) $revision['unworked_hours_occurred']);
        self::assertSame(1, (int) $revision['work_obstacles_occurred']);
        self::assertSame(80000, (int) $revision['unworked_total_millihours']);
        self::assertSame(0, (int) $revision['unworked_paid_millihours']);
        self::assertNull($revision['vacation_millihours']);
        self::assertSame(80000, (int) $revision['employee_obstacle_paid_millihours']);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no,
                 row_version, last_changed_by, approved_by, approved_at)
             VALUES (?, ?, "2026-06-01", "approved", 1, 1, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $this->userId,
            $this->userId,
        ]);
        $juneMonthId = (int) $this->db->pdo()->lastInsertId();
        $constraintFailure = null;
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_jmhz_work_month_revisions
                    (supplier_id, employment_id, time_month_id, time_month_revision_no,
                     period_start, spec_package_id, spec_manifest_sha256,
                     scenario_catalog_key, scenario_manifest_sha256, derivation_version,
                     source_snapshot_json, source_snapshot_sha256,
                     standard_fund_millihours, agreed_fund_millihours,
                     weekly_work_centihours, evidence_days, worked_millihours,
                     conditional_blocks_confirmed, unworked_hours_occurred,
                     work_obstacles_occurred, confirmation_note, provenance_json,
                     summary_sha256, approved_by, approved_at)
                 SELECT supplier_id, employment_id, ?, 1, "2026-06-01", spec_package_id,
                        spec_manifest_sha256, scenario_catalog_key, scenario_manifest_sha256,
                        derivation_version, source_snapshot_json, source_snapshot_sha256,
                        standard_fund_millihours, agreed_fund_millihours,
                        weekly_work_centihours, 30, worked_millihours,
                        1, NULL, NULL, confirmation_note, provenance_json,
                        summary_sha256, approved_by, approved_at
                   FROM payroll_jmhz_work_month_revisions
                  WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-05-01"'
            )->execute([$juneMonthId, $this->supplierId, $this->employmentId]);
        } catch (\PDOException $exception) {
            $constraintFailure = $exception;
        }
        self::assertInstanceOf(\PDOException::class, $constraintFailure);
        self::assertStringContainsString(
            'chk_payroll_jmhz_work_month_conditional_confirmation',
            $constraintFailure->getMessage(),
        );

        $missingTotalFailure = null;
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_jmhz_work_month_revisions
                    (supplier_id, employment_id, time_month_id, time_month_revision_no,
                     period_start, spec_package_id, spec_manifest_sha256,
                     scenario_catalog_key, scenario_manifest_sha256, derivation_version,
                     source_snapshot_json, source_snapshot_sha256,
                     standard_fund_millihours, agreed_fund_millihours,
                     weekly_work_centihours, evidence_days, worked_millihours,
                     conditional_blocks_confirmed, unworked_hours_occurred,
                     work_obstacles_occurred, confirmation_note, provenance_json,
                     summary_sha256, approved_by, approved_at)
                 SELECT supplier_id, employment_id, ?, 1, "2026-06-01", spec_package_id,
                        spec_manifest_sha256, scenario_catalog_key, scenario_manifest_sha256,
                        derivation_version, source_snapshot_json, source_snapshot_sha256,
                        standard_fund_millihours, agreed_fund_millihours,
                        weekly_work_centihours, 30, worked_millihours,
                        1, 1, 0, confirmation_note, provenance_json,
                        summary_sha256, approved_by, approved_at
                   FROM payroll_jmhz_work_month_revisions
                  WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-05-01"'
            )->execute([$juneMonthId, $this->supplierId, $this->employmentId]);
        } catch (\PDOException $exception) {
            $missingTotalFailure = $exception;
        }
        self::assertInstanceOf(\PDOException::class, $missingTotalFailure);
        self::assertStringContainsString(
            'chk_payroll_jmhz_work_month_unworked_block',
            $missingTotalFailure->getMessage(),
        );
    }

    public function testJmhzCoreWorkSummaryRejectsStalePreviewAndRoundingGuess(): void
    {
        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode());
        $entry = $this->saveEntry(
            monthVersion: 0,
            endsAt: '2026-05-04T08:31:00+02:00',
        );
        self::assertSame(201, $entry->getStatusCode());
        $monthVersion = (int) $this->json($entry)['month']['row_version'];
        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $preview = $this->json($overview)['items'][0]['jmhz_work_summary']['preview'];
        self::assertNull($preview['suggestions']['worked_hours']);

        $invalidShape = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => 'skip',
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $invalidShape->getStatusCode());

        $rejected = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => str_repeat('0', 64),
                        'standard_fund_hours' => '168',
                        'agreed_fund_hours' => '168',
                        'weekly_work_hours' => '40',
                        'worked_hours' => '0.017',
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => 'Nepotvrzený zaokrouhlený odhad.',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(409, $rejected->getStatusCode());
        self::assertSame(0, $this->countRows('payroll_jmhz_work_month_revisions'));
        $month = $this->db->pdo()->prepare(
            'SELECT status FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?'
        );
        $month->execute([$this->supplierId, $this->employmentId, '2026-05-01']);
        self::assertSame('open', $month->fetchColumn());

        $freshOverview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $freshPreview = $this->json($freshOverview)['items'][0]['jmhz_work_summary']['preview'];
        $overPrecision = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $monthVersion,
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $freshPreview['source_snapshot_sha256'],
                        'standard_fund_hours' => '168',
                        'agreed_fund_hours' => '168',
                        'weekly_work_hours' => '40',
                        'worked_hours' => '0.0167',
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => 'Hodnota má nepovolenou přesnost.',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $overPrecision->getStatusCode());
        self::assertSame(0, $this->countRows('payroll_jmhz_work_month_revisions'));
    }

    public function testJmhzWorkedSuggestionUsesEachEntryLocalMonth(): void
    {
        $newYork = $this->saveEntry(
            monthVersion: 0,
            startsAt: '2026-05-31T20:00:00-04:00',
            endsAt: '2026-05-31T21:00:00-04:00',
            timezone: 'America/New_York',
            breakMinutes: 0,
        );
        self::assertSame(201, $newYork->getStatusCode());
        $monthVersion = (int) $this->json($newYork)['month']['row_version'];

        $auckland = $this->saveEntry(
            monthVersion: $monthVersion,
            startsAt: '2026-05-01T00:30:00+12:00',
            endsAt: '2026-05-01T01:30:00+12:00',
            timezone: 'Pacific/Auckland',
            breakMinutes: 0,
        );
        self::assertSame(201, $auckland->getStatusCode());

        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode());
        $preview = $this->json($overview)['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('2', $preview['suggestions']['worked_hours']);
        self::assertNotContains(
            'worked_interval_crosses_month',
            array_column($preview['issues'], 'code'),
        );
    }

    public function testCsvPreviewPartialImportAndReplayAreIdempotent(): void
    {
        $csv = implode("\n", [
            'employment_code;starts_at;ends_at;timezone;category;break_minutes;external_id',
            'SYN-TIME-1;2026-05-04T08:00:00+02:00;2026-05-04T16:00:00+02:00;Europe/Prague;regular;30;EXT-1',
            'SYN-TIME-1;2026-05-04T08:00:00+02:00;2026-05-04T16:00:00+02:00;Europe/Prague;regular;30;EXT-1',
            'UNKNOWN;2026-05-05T08:00:00+02:00;2026-05-05T16:00:00+02:00;Europe/Prague;regular;30;EXT-2',
            'SYN-TIME-1;2026-05-06T08:00:00+02:00;2026-05-06T16:00:00+02:00;Europe/Prague;unsupported;30;EXT-3',
        ]);
        $payload = [
            'period' => '2026-05',
            'format' => 'csv',
            'original_name' => 'synthetic-time.csv',
            'content' => $csv,
        ];
        $preview = $this->action->previewImport(
            $this->request('POST', '/api/payroll/time/imports/preview')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode());
        $summary = $this->json($preview)['preview'];
        self::assertSame(1, $summary['accepted_rows']);
        self::assertSame(2, $summary['rejected_rows']);
        self::assertSame(1, $summary['duplicate_rows']);

        $foreign = $this->action->previewImport(
            $this->request(
                'POST',
                '/api/payroll/time/imports/preview',
                supplierId: $this->otherSupplierId,
            )->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(200, $foreign->getStatusCode());
        $foreignSummary = $this->json($foreign)['preview'];
        self::assertSame(0, $foreignSummary['accepted_rows']);
        self::assertSame(4, $foreignSummary['rejected_rows']);

        $bearer = $this->action->previewImport(
            $this->request(
                'POST',
                '/api/payroll/time/imports/preview',
                authMethod: 'bearer',
            )->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $first = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode());
        $firstImport = $this->json($first)['import'];
        self::assertSame('partial', $firstImport['status']);
        self::assertSame(1, $firstImport['accepted_rows']);
        self::assertSame(2, $firstImport['rejected_rows']);
        self::assertSame(1, $firstImport['duplicate_rows']);

        $replay = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        $replayed = $this->json($replay)['import'];
        self::assertSame($firstImport['id'], $replayed['id']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(1, $this->countRows('payroll_time_entries'));
    }

    public function testXlsxPreviewHandlesFiveHundredEmploymentsWithoutCompanyWideLoad(): void
    {
        $pdo = $this->db->pdo();
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, 42000, 0, 1)'
        );
        $profile = $pdo->prepare(
            "INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        );
        $employment = $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, ?, 'employment', 'active', '2026-01-01', 4200000, 0)"
        );
        $rows = [[
            'employment_code',
            'starts_at',
            'ends_at',
            'timezone',
            'category',
            'break_minutes',
            'external_id',
        ], [
            'SYN-TIME-1',
            '2026-05-04T08:00:00+02:00',
            '2026-05-04T16:00:00+02:00',
            'Europe/Prague',
            'regular',
            30,
            'SCALE-1',
        ]];
        for ($index = 2; $index <= 500; ++$index) {
            $code = sprintf('SYN-SCALE-%03d', $index);
            $employee->execute([
                $this->supplierId,
                "Syntetická osoba {$index}",
                'employee',
                'hpp',
            ]);
            $employeeId = (int) $pdo->lastInsertId();
            $profile->execute([$this->supplierId, $employeeId]);
            $employment->execute([$this->supplierId, $employeeId, $code]);
            $day = (($index - 1) % 28) + 1;
            $rows[] = [
                $code,
                sprintf('2026-05-%02dT08:00:00+02:00', $day),
                sprintf('2026-05-%02dT16:00:00+02:00', $day),
                'Europe/Prague',
                'regular',
                30,
                "SCALE-{$index}",
            ];
        }

        $response = $this->action->previewImport(
            $this->request('POST', '/api/payroll/time/imports/preview')
                ->withParsedBody([
                    'period' => '2026-05',
                    'format' => 'xlsx',
                    'original_name' => 'synthetic-scale.xlsx',
                    'content' => base64_encode($this->xlsx($rows)),
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $preview = $this->json($response)['preview'];
        self::assertSame(500, $preview['total_rows']);
        self::assertSame(500, $preview['accepted_rows']);
        self::assertSame(0, $preview['rejected_rows']);
        self::assertSame(0, $preview['duplicate_rows']);
    }

    public function testXlsxPreviewPartialImportAndReplayAreIdempotent(): void
    {
        $content = $this->xlsx([
            ['employment_code', 'starts_at', 'ends_at', 'timezone', 'category', 'break_minutes', 'external_id'],
            ['SYN-TIME-1', '2026-05-04T08:00:00+02:00', '2026-05-04T16:00:00+02:00', 'Europe/Prague', 'regular', 30, 'XLSX-EXT-1'],
            ['SYN-TIME-1', '2026-05-04T08:00:00+02:00', '2026-05-04T16:00:00+02:00', 'Europe/Prague', 'regular', 30, 'XLSX-EXT-1'],
            ['UNKNOWN', '2026-05-05T08:00:00+02:00', '2026-05-05T16:00:00+02:00', 'Europe/Prague', 'regular', 30, 'XLSX-EXT-2'],
            ['SYN-TIME-1', '2026-05-06T08:00:00+02:00', '2026-05-06T16:00:00+02:00', 'Europe/Prague', 'unsupported', 30, 'XLSX-EXT-3'],
        ]);
        $payload = [
            'period' => '2026-05',
            'format' => 'xlsx',
            'original_name' => 'synthetic-time.xlsx',
            'content' => base64_encode($content),
        ];

        $preview = $this->action->previewImport(
            $this->request('POST', '/api/payroll/time/imports/preview')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode());
        $summary = $this->json($preview)['preview'];
        self::assertTrue($summary['supported']);
        self::assertSame(1, $summary['accepted_rows']);
        self::assertSame(2, $summary['rejected_rows']);
        self::assertSame(1, $summary['duplicate_rows']);

        $first = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode());
        $firstImport = $this->json($first)['import'];
        self::assertSame('xlsx', $firstImport['format']);
        self::assertSame('partial', $firstImport['status']);
        self::assertSame(1, $firstImport['accepted_rows']);
        self::assertSame(2, $firstImport['rejected_rows']);
        self::assertSame(1, $firstImport['duplicate_rows']);

        $replay = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        $replayed = $this->json($replay)['import'];
        self::assertSame($firstImport['id'], $replayed['id']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(1, $this->countRows('payroll_time_entries'));
    }

    public function testXlsxFormulaIsRejectedBeforePreviewOrApplyWritesAnything(): void
    {
        $payload = [
            'period' => '2026-05',
            'format' => 'xlsx',
            'original_name' => 'formula-must-not-run.xlsx',
            'content' => base64_encode($this->xlsx([
                ['employment_code', 'starts_at', 'ends_at', 'timezone', 'category', 'break_minutes', 'external_id'],
                ['SYN-TIME-1', '2026-05-04T08:00:00+02:00', '2026-05-04T16:00:00+02:00', 'Europe/Prague', 'regular', '=15+15', 'FORMULA-1'],
            ])),
        ];

        $preview = $this->action->previewImport(
            $this->request('POST', '/api/payroll/time/imports/preview')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(422, $preview->getStatusCode());
        self::assertStringContainsString('vzorec', $this->json($preview)['error']['message']);

        $apply = $this->action->import(
            $this->request('POST', '/api/payroll/time/imports')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(422, $apply->getStatusCode());
        self::assertStringContainsString('vzorec', $this->json($apply)['error']['message']);
        self::assertSame(0, $this->countRows('payroll_time_entries'));
        self::assertSame(0, $this->countRows('payroll_time_imports'));
    }

    public function testCrossMonthEntryBelongsOnlyToLocalStartMonth(): void
    {
        $entry = $this->saveEntry(
            monthVersion: 0,
            startsAt: '2026-05-31T22:00:00+02:00',
            endsAt: '2026-06-01T06:00:00+02:00',
        );
        self::assertSame(201, $entry->getStatusCode());

        $may = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $june = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-06']),
            new Response(),
        );

        self::assertSame(450, $this->json($may)['items'][0]['summary']['actual_minutes']);
        self::assertCount(1, $this->json($may)['items'][0]['entries']);
        self::assertSame(0, $this->json($june)['items'][0]['summary']['actual_minutes']);
        self::assertCount(0, $this->json($june)['items'][0]['entries']);
    }

    public function testTenantIsolationAndBearerAreFailClosed(): void
    {
        $foreign = $this->action->month(
            $this->request(
                'GET',
                '/api/payroll/time/month',
                supplierId: $this->otherSupplierId,
            )->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame([], $this->json($foreign)['items']);

        $bearer = $this->action->month(
            $this->request('GET', '/api/payroll/time/month', authMethod: 'bearer')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    private function saveEntry(
        int $monthVersion,
        string $startsAt = '2026-05-04T08:00:00+02:00',
        string $endsAt = '2026-05-04T16:00:00+02:00',
        ?int $supersedesId = null,
        int $rowVersion = 0,
        string $timezone = 'Europe/Prague',
        int $breakMinutes = 30,
    ): Response {
        return $this->action->entry(
            $this->request('POST', '/api/payroll/time/entries')->withParsedBody([
                'employment_id' => $this->employmentId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'category' => 'regular',
                'break_minutes' => $breakMinutes,
                'row_version' => $rowVersion,
                'month_row_version' => $monthVersion,
                'supersedes_id' => $supersedesId,
            ]),
            new Response(),
        );
    }

    /** @return array<string,mixed> */
    private function calendarPayload(): array
    {
        return [
            'name' => 'Syntetický pravidelný týden',
            'timezone' => 'Europe/Prague',
            'schedule_type' => 'regular',
            'week_pattern' => [
                '1' => 480,
                '2' => 480,
                '3' => 480,
                '4' => 480,
                '5' => 480,
                '6' => 0,
                '7' => 0,
            ],
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'row_version' => 0,
            'month_row_version' => 0,
            'days' => [],
        ];
    }

    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?"
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<list<string|int>> $rows */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-time-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            (new Xlsx($spreadsheet))->save($tmp);
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Syntetický XLSX nelze načíst.');
            }
            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tmp);
        }
    }

    private function request(
        string $method,
        string $uri,
        ?int $supplierId = null,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
