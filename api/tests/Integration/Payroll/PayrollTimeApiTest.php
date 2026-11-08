<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
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
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
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

    public function testManualSummaryCanPersistVersionTenJmhzWithoutInventedDays(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours, workload_basis_points, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", 40, 10000, "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $calendar = $this->action->calendar($this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
            ->withParsedBody($this->calendarPayload()), new Response(), ['employmentId' => (string) $this->employmentId]);
        self::assertSame(201, $calendar->getStatusCode(), (string) $calendar->getBody());
        $saved = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Syntetický ruční podklad JMHZ', 'replace_source' => false,
            'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31', 'worked_duration_units' => 480000,
                'overtime_duration_units' => null, 'worked_days' => null]],
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $overview = $this->action->month($this->request('GET', '/api/payroll/time/month')->withQueryParams([
            'period' => '2026-05', 'employment_id' => $this->employmentId,
        ]), new Response());
        $preview = $this->json($overview)['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('jmhz-work-month.v10', $preview['derivation_version']);
        $approved = $this->action->approve($this->request('POST', '/api/payroll/time/months/2026-05/approve')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => $this->json($saved)['month']['row_version'],
            'jmhz_work_summary' => ['unworked_hours_occurred' => false, 'work_obstacles_occurred' => false]
                + $preview['suggestions'] + ['source_snapshot_sha256' => $preview['source_snapshot_sha256']],
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $statement = $this->db->pdo()->prepare('SELECT derivation_version, worked_millihours, worked_days, overtime_millihours FROM payroll_jmhz_work_month_revisions WHERE supplier_id = ? AND employment_id = ?');
        $statement->execute([$this->supplierId, $this->employmentId]);
        $revision = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame('jmhz-work-month.v10', $revision['derivation_version']);
        self::assertSame(160000, (int) $revision['worked_millihours']);
        self::assertNull($revision['worked_days']);
        self::assertNull($revision['overtime_millihours']);
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

    public function testMonthOverviewSearchesByNameOrCode(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId, 'Hledaný Syntetický', 'employee', 'hpp']);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'legacy')"
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, 'SYN-TIME-2', 'employment', 'active', '2026-01-01', 3000000, 0)"
        )->execute([$this->supplierId, $employeeId]);

        $byName = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05', 'q' => 'hledan']),
            new Response(),
        ));
        self::assertSame(1, $byName['total']);
        self::assertSame('Hledaný Syntetický', $byName['items'][0]['employment']['full_name']);

        $byCode = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05', 'q' => 'TIME-1']),
            new Response(),
        ));
        self::assertSame(1, $byCode['total']);
        self::assertSame('SYN-TIME-1', $byCode['items'][0]['employment']['code']);

        $all = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')->withQueryParams(['period' => '2026-05']),
            new Response(),
        ));
        self::assertSame(2, $all['total']);
    }

    public function testShiftLongerThanDayIsRejectedAndWrongShiftCanBeCancelled(): void
    {
        $shiftPayload = fn (string $start, string $end, int $monthVersion): array => [
            'employment_id' => $this->employmentId,
            'starts_at' => $start,
            'ends_at' => $end,
            'timezone' => 'Europe/Prague',
            'break_minutes' => 30,
            'remote_work' => false,
            'standby_minutes' => 0,
            'publish' => true,
            'row_version' => 0,
            'month_row_version' => $monthVersion,
            'supersedes_id' => null,
        ];

        // Konec o den později (chyba „Uložit a další den"): 32 hodin se neuloží.
        $tooLong = $this->action->shift(
            $this->request('POST', '/api/payroll/time/shifts')->withParsedBody(
                $shiftPayload('2026-05-05T08:00:00+02:00', '2026-05-06T16:30:00+02:00', 0),
            ),
            new Response(),
        );
        self::assertSame(422, $tooLong->getStatusCode());
        self::assertStringContainsString('24 hodin', $this->json($tooLong)['error']['message']);

        $saved = $this->action->shift(
            $this->request('POST', '/api/payroll/time/shifts')->withParsedBody(
                $shiftPayload('2026-05-05T08:00:00+02:00', '2026-05-05T16:30:00+02:00', 0),
            ),
            new Response(),
        );
        self::assertSame(201, $saved->getStatusCode());
        $shift = $this->json($saved)['shift'];
        $month = $this->json($saved)['month'];

        $cancelled = $this->action->cancel(
            $this->request('POST', '/api/payroll/time/shifts/' . $shift['id'] . '/cancel')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $shift['row_version'],
                    'month_row_version' => $month['row_version'],
                ]),
            new Response(),
            ['kind' => 'shifts', 'id' => (string) $shift['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode());

        $overview = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        ));
        self::assertSame([], $overview['items'][0]['shifts']);
        self::assertSame(0, $overview['items'][0]['summary']['planned_minutes']);

        $again = $this->action->cancel(
            $this->request('POST', '/api/payroll/time/shifts/' . $shift['id'] . '/cancel')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $shift['row_version'],
                    'month_row_version' => $this->json($cancelled)['month']['row_version'],
                ]),
            new Response(),
            ['kind' => 'shifts', 'id' => (string) $shift['id']],
        );
        self::assertSame(422, $again->getStatusCode());
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

    public function testManualSummaryReplacesAttendanceWithAuditAndKeepsVersions(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $entry = $this->saveEntry(monthVersion: 0);
        self::assertSame(201, $entry->getStatusCode());
        $month = $this->json($entry)['month'];
        $payload = [
            'employment_id' => $this->employmentId, 'row_version' => (int) $month['row_version'],
            'reason' => 'Syntetická oprava', 'replace_source' => false,
            'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31',
                'worked_duration_units' => 22503, 'overtime_duration_units' => null, 'worked_days' => null]],
        ];
        $attempt = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $attempt->getStatusCode());
        $payload['replace_source'] = true;
        $saved = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $data = $this->json($saved);
        self::assertSame('manual_summary', $data['month']['work_source']);
        self::assertSame(22503, $data['manual_summary']['segments'][0]['worked_duration_units']);
        self::assertNull($data['manual_summary']['segments'][0]['worked_days']);
        self::assertSame(422, $this->saveEntry(monthVersion: (int) $data['month']['row_version'])->getStatusCode());
        $overview = $this->json($this->action->month($this->request('GET', '/api/payroll/time/month')->withQueryParams([
            'period' => '2026-05', 'employment_id' => $this->employmentId,
        ]), new Response()))['items'][0];
        self::assertSame('manual_summary', $overview['summary']['work_source']);
        self::assertSame(22503, $overview['summary']['worked_duration_units']);
        self::assertNull($overview['summary']['actual_minutes']);
        self::assertNull($overview['summary']['difference_minutes']);
        self::assertNull($overview['summary']['category_minutes']);
        self::assertCount(1, $overview['entries']);
        self::assertSame('Syntetická oprava', $overview['manual_summary']['reason']);
        $historyQuery = ['employment_id' => $this->employmentId, 'from' => '2026-05', 'to' => '2026-05'];
        $selectedHistory = $this->json($this->action->history($this->request('GET', '/api/payroll/time/history')->withQueryParams($historyQuery), new Response()))['items'][0];
        self::assertSame('manual_summary', $selectedHistory['summary']['work_source']);
        self::assertSame(22503, $selectedHistory['summary']['worked_duration_units']);
        self::assertNull($selectedHistory['summary']['actual_minutes']);
        self::assertSame(22503, $selectedHistory['manual_summary']['segments'][0]['worked_duration_units']);

        $approval = $this->action->approve($this->request('POST', '/api/payroll/time/months/2026-05/approve')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => (int) $data['month']['row_version'],
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $approval->getStatusCode(), (string) $approval->getBody());
        $approved = $this->json($approval)['month'];
        self::assertSame('approved', $approved['status']);
        self::assertSame('manual_summary', $this->json($approval)['surcharges']['skipped_reason']);
        $status = $this->db->pdo()->prepare('SELECT status FROM payroll_time_entries WHERE supplier_id = ? AND employment_id = ?');
        $status->execute([$this->supplierId, $this->employmentId]);
        self::assertSame('draft', $status->fetchColumn());

        $reopened = $this->action->reopen($this->request('POST', '/api/payroll/time/months/2026-05/reopen')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => (int) $approved['row_version'], 'reason' => 'Syntetická oprava hodin',
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $reopened->getStatusCode(), (string) $reopened->getBody());
        $open = $this->json($reopened)['month'];
        $payload['row_version'] = (int) $open['row_version'];
        $payload['replace_source'] = false;
        $payload['segments'][0]['worked_duration_units'] = 0;
        $revised = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $revised->getStatusCode(), (string) $revised->getBody());
        self::assertSame(0, $this->json($revised)['manual_summary']['segments'][0]['worked_duration_units']);
        $zero = $this->json($this->action->month($this->request('GET', '/api/payroll/time/month')->withQueryParams([
            'period' => '2026-05', 'employment_id' => $this->employmentId,
        ]), new Response()))['items'][0]['summary'];
        self::assertSame(0, $zero['worked_duration_units']);
        self::assertSame(0, $zero['actual_minutes']);
        self::assertSame(3, $this->countRows('payroll_time_month_manual_summaries'));
        $revisedMonth = $this->json($revised)['month'];
        $restore = $this->action->restoreEntries($this->request('POST', '/api/payroll/time/months/2026-05/work-source')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => (int) $revisedMonth['row_version'],
            'reason' => 'Návrat k docházce', 'replace_source' => true, 'source' => 'entries',
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $restore->getStatusCode(), (string) $restore->getBody());
        self::assertSame('entries', $this->json($restore)['month']['work_source']);
        $history = $this->action->history($this->request('GET', '/api/payroll/time/history')->withQueryParams([
            'employment_id' => $this->employmentId, 'from' => '2026-05', 'to' => '2026-05',
        ]), new Response());
        self::assertSame(200, $history->getStatusCode(), (string) $history->getBody());
        $monthHistory = $this->json($history)['items'][0];
        self::assertSame('entries', $monthHistory['summary']['work_source']);
        self::assertSame(22500, $monthHistory['summary']['worked_duration_units']);
        self::assertSame(450, $monthHistory['summary']['actual_minutes']);
        self::assertNull($monthHistory['manual_summary']);
        self::assertFalse($monthHistory['manual_summary_history_truncated']);
        self::assertCount(3, $monthHistory['manual_summary_history']);
        self::assertSame(22503, $monthHistory['manual_summary_history'][2]['segments'][0]['worked_duration_units']);
        self::assertSame('Syntetická oprava', $monthHistory['manual_summary_history'][2]['reason']);
        self::assertSame($this->userId, $monthHistory['manual_summary_history'][2]['created_by']);
        self::assertNotNull($monthHistory['manual_summary_history'][2]['created_at']);
    }

    /**
     * První ruční souhrn do prázdného měsíce důvod nepotřebuje: server doplní
     * výchozí a navenek ho vrací jako `null` (obrazovka ukáže popisek).
     * Změna uloženého souhrnu důvod chce dál. Mezera v úsecích vypíše dny.
     */
    public function testFirstManualSummaryNeedsNoReasonButChangeDoes(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $path = '/api/payroll/time/months/2026-05/manual-summary';
        $segment = static fn (string $from, string $to, int $units): array => ['date_from' => $from, 'date_to' => $to,
            'worked_duration_units' => $units, 'overtime_duration_units' => null, 'worked_days' => $units === 0 ? 0 : 1];
        $payload = ['employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => null, 'replace_source' => false,
            'segments' => [$segment('2026-05-01', '2026-05-04', 0), $segment('2026-05-08', '2026-05-19', 0), $segment('2026-05-21', '2026-05-31', 0)]];

        $gap = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $gap->getStatusCode());
        self::assertStringContainsString('chybí dny 5.–7. 5., 20. 5.', (string) $this->json($gap)['error']['message']);

        $payload['segments'] = [$segment('2026-05-01', '2026-05-31', 0)];
        $saved = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $body = $this->json($saved);
        self::assertNull($body['manual_summary']['reason']);
        $stmt = $this->db->pdo()->prepare('SELECT reason FROM payroll_time_month_manual_summaries WHERE supplier_id = ? AND employment_id = ?');
        $stmt->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(PayrollTimeRepository::MANUAL_SUMMARY_INITIAL_REASON, $stmt->fetchColumn());

        // Docházka do měsíce s ručním souhrnem neprojde a hláška řekne, kde zdroj vrátit.
        $entry = $this->saveEntry(monthVersion: $body['month']['row_version']);
        self::assertGreaterThanOrEqual(400, $entry->getStatusCode());
        self::assertStringContainsString('Podklady hodin', (string) $this->json($entry)['error']['message']);
        self::assertStringContainsString('Změnit vybraný zdroj hodin', (string) $this->json($entry)['error']['message']);

        unset($payload['reason']);
        $payload['row_version'] = $body['month']['row_version'];
        $payload['segments'] = [$segment('2026-05-01', '2026-05-01', 1), $segment('2026-05-02', '2026-05-31', 0)];
        $change = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $change->getStatusCode());
        self::assertStringContainsString('důvod', (string) $this->json($change)['error']['message']);

        $payload['reason'] = 'Oprava po kontrole';
        $changed = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $changed->getStatusCode(), (string) $changed->getBody());
        self::assertSame('Oprava po kontrole', $this->json($changed)['manual_summary']['reason']);
    }

    public function testManualSummaryNeedsNoAttendanceAndEnforcesTenantVersionAndSession(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $payload = [
            'employment_id' => $this->employmentId, 'row_version' => 0,
            'reason' => 'Nulová skutečná práce', 'replace_source' => false,
            'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31',
                'worked_duration_units' => 0, 'overtime_duration_units' => null, 'worked_days' => 0]],
        ];
        $path = '/api/payroll/time/months/2026-05/manual-summary';
        $bearer = $this->action->saveManualSummary($this->request('POST', $path, authMethod: 'bearer')->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(403, $bearer->getStatusCode());
        $wrongTenant = $this->action->saveManualSummary($this->request('POST', $path, $this->otherSupplierId)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $wrongTenant->getStatusCode());
        $saved = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(0, $this->json($saved)['manual_summary']['segments'][0]['worked_duration_units']);
        self::assertSame(0, $this->json($saved)['manual_summary']['segments'][0]['worked_days']);
        $stale = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(409, $stale->getStatusCode());
    }

    public function testManualSummaryPermissionsSeparateWriteApproveAndReopen(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $role = static fn (array $permissions): EffectiveRole => new EffectiveRole(0, 'Test', 'staff', true, $permissions);
        $payload = ['employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Podklad', 'replace_source' => false,
            'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31', 'worked_duration_units' => 0, 'overtime_duration_units' => null, 'worked_days' => 0]]];
        $path = '/api/payroll/time/months/2026-05/manual-summary';
        $denied = $this->action->saveManualSummary($this->request('POST', $path)->withAttribute('auth.effective_role', $role([]))->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(403, $denied->getStatusCode());
        $writer = $role(['payroll.time.write' => AccessLevel::WRITE->value]);
        $saved = $this->action->saveManualSummary($this->request('POST', $path)->withAttribute('auth.effective_role', $writer)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $month = $this->json($saved)['month'];
        $approve = ['employment_id' => $this->employmentId, 'row_version' => (int) $month['row_version']];
        $denied = $this->action->approve($this->request('POST', '/approve')->withAttribute('auth.effective_role', $writer)->withParsedBody($approve), new Response(), ['period' => '2026-05']);
        self::assertSame(403, $denied->getStatusCode());
        $approver = $role(['payroll.approve' => AccessLevel::WRITE->value]);
        $approved = $this->action->approve($this->request('POST', '/approve')->withAttribute('auth.effective_role', $approver)->withParsedBody($approve), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $reopen = ['employment_id' => $this->employmentId, 'row_version' => (int) $this->json($approved)['month']['row_version'], 'reason' => 'Oprava'];
        $denied = $this->action->reopen($this->request('POST', '/reopen')->withAttribute('auth.effective_role', $approver)->withParsedBody($reopen), new Response(), ['period' => '2026-05']);
        self::assertSame(403, $denied->getStatusCode());
        $reopener = $role(['payroll.reopen' => AccessLevel::WRITE->value]);
        $opened = $this->action->reopen($this->request('POST', '/reopen')->withAttribute('auth.effective_role', $reopener)->withParsedBody($reopen), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $opened->getStatusCode(), (string) $opened->getBody());
    }

    public function testManualSummaryHistoryCanPagePastTwentyRevisions(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $version = 0;
        for ($i = 1; $i <= 22; ++$i) {
            $response = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody([
                'employment_id' => $this->employmentId, 'row_version' => $version,
                'reason' => 'Syntetická revize ' . $i, 'replace_source' => false,
                'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31',
                    'worked_duration_units' => $i, 'overtime_duration_units' => null, 'worked_days' => null]],
            ]), new Response(), ['period' => '2026-05']);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $version = (int) $this->json($response)['month']['row_version'];
        }
        $query = ['employment_id' => $this->employmentId, 'from' => '2026-05', 'to' => '2026-05'];
        $first = $this->action->history($this->request('GET', '/api/payroll/time/history')->withQueryParams($query), new Response());
        self::assertSame(200, $first->getStatusCode());
        $firstItem = $this->json($first)['items'][0];
        self::assertCount(20, $firstItem['manual_summary_history']);
        self::assertTrue($firstItem['manual_summary_history_truncated']);
        self::assertSame(22, $firstItem['manual_summary_history'][0]['segments'][0]['worked_duration_units']);
        $second = $this->action->history($this->request('GET', '/api/payroll/time/history')->withQueryParams($query + ['manual_summary_offset' => 20]), new Response());
        self::assertSame(200, $second->getStatusCode());
        $secondItem = $this->json($second)['items'][0];
        self::assertSame(20, $secondItem['manual_summary_history_offset']);
        self::assertCount(2, $secondItem['manual_summary_history']);
        self::assertFalse($secondItem['manual_summary_history_truncated']);
        self::assertSame(1, $secondItem['manual_summary_history'][1]['segments'][0]['worked_duration_units']);
    }

    public function testMonthlyEmploymentCannotUseManualSummaryOrChangeSource(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, monthly_gross_minor) VALUES (?, ?, "2026-01-01", "2026-01-01", 4200000)')
            ->execute([$this->supplierId, $this->employmentId]);
        $saved = $this->action->saveManualSummary($this->request('POST', '/api/payroll/time/months/2026-05/manual-summary')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Syntetický podklad', 'replace_source' => true,
            'segments' => [['date_from' => '2026-05-01', 'date_to' => '2026-05-31', 'worked_duration_units' => 3000, 'overtime_duration_units' => null, 'worked_days' => null]],
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertStringContainsString('hodinovým odměňováním', (string) $saved->getBody());
        $restore = $this->action->restoreEntries($this->request('POST', '/api/payroll/time/months/2026-05/work-source')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Syntetický import', 'replace_source' => true, 'source' => 'import_summary',
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $restore->getStatusCode(), (string) $restore->getBody());
        self::assertSame(0, $this->countRows('payroll_time_months'));
    }

    /**
     * „Počítat ze směn": měsíc z importu docházky přejde na směny jedním
     * krokem. Dřív zápis směny odkázal na neexistující panel a změna zdroje
     * spadla na nezrušených vstupech z importu, které musela účetní hledat jinde.
     */
    public function testSwitchToEntriesCancelsDraftImportInputsAndChangesSource(): void
    {
        $monthVersion = $this->importSummaryMonth();
        $draftId = $this->importInput('draft', 'attendance:2026-05:' . $this->employmentId . ':hours');
        $leaveId = $this->importInput('draft', 'leave:attendance:2026-05:' . $this->employmentId . ':leave');
        $manualId = $this->importInput('draft', 'manual:2026-05:' . $this->employmentId);

        $blocked = $this->saveEntry(monthVersion: $monthVersion);
        self::assertSame(422, $blocked->getStatusCode());
        self::assertStringContainsString('Počítat ze směn', (string) $this->json($blocked)['error']['message']);
        self::assertStringNotContainsString('Odpracované hodiny', (string) $this->json($blocked)['error']['message']);

        $switched = $this->action->switchToEntries($this->request('POST', '/api/payroll/time/months/2026-05/switch-to-entries')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => $monthVersion,
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $switched->getStatusCode(), (string) $switched->getBody());
        $body = $this->json($switched);
        self::assertSame('entries', $body['month']['work_source']);
        self::assertEqualsCanonicalizing([$draftId, $leaveId], $body['cancelled_input_ids']);
        self::assertSame('cancelled', $this->inputStatus($draftId));
        self::assertSame('cancelled', $this->inputStatus($leaveId));
        self::assertSame('draft', $this->inputStatus($manualId));

        $entry = $this->saveEntry(monthVersion: (int) $body['month']['row_version']);
        self::assertSame(201, $entry->getStatusCode(), (string) $entry->getBody());
    }

    public function testSwitchToEntriesRefusesApprovedImportInputsAndChangesNothing(): void
    {
        $monthVersion = $this->importSummaryMonth();
        $draftId = $this->importInput('draft', 'attendance:2026-05:' . $this->employmentId . ':hours');
        $approvedId = $this->importInput('approved', 'attendance:2026-05:' . $this->employmentId . ':bonus');

        $refused = $this->action->switchToEntries($this->request('POST', '/api/payroll/time/months/2026-05/switch-to-entries')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => $monthVersion,
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(409, $refused->getStatusCode(), (string) $refused->getBody());
        $error = $this->json($refused)['error'];
        self::assertSame('import_inputs_approved', $error['code']);
        self::assertStringContainsString('schválených nebo zamčených', (string) $error['message']);
        self::assertSame('draft', $this->inputStatus($draftId));
        self::assertSame('approved', $this->inputStatus($approvedId));
        $stmt = $this->db->pdo()->prepare('SELECT work_source FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-05-01"');
        $stmt->execute([$this->supplierId, $this->employmentId]);
        self::assertSame('import_summary', $stmt->fetchColumn());
    }

    private function importSummaryMonth(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours, workload_basis_points, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", 40, 10000, "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $calendar = $this->action->calendar($this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
            ->withParsedBody($this->calendarPayload()), new Response(), ['employmentId' => (string) $this->employmentId]);
        self::assertSame(201, $calendar->getStatusCode(), (string) $calendar->getBody());
        $pdo->prepare('INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, status, work_source, revision_no, row_version) VALUES (?, ?, "2026-05-01", "open", "import_summary", 1, 1)
            ON DUPLICATE KEY UPDATE work_source = "import_summary"')
            ->execute([$this->supplierId, $this->employmentId]);
        $stmt = $pdo->prepare('SELECT row_version FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-05-01"');
        $stmt->execute([$this->supplierId, $this->employmentId]);

        return (int) $stmt->fetchColumn();
    }

    private function importInput(string $status, string $externalId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment, health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, "Syntetická složka importu", "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included", "included", "included", "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId, 'IMP' . bin2hex(random_bytes(3))]);
        $componentId = (int) $pdo->lastInsertId();
        $employeeId = (int) $pdo->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $this->employmentId)->fetchColumn();
        $json = '{"code":"IMP"}';
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor, source_kind, external_id, status,
                 component_snapshot_json, component_snapshot_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-05-01", 10000, "manual", ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $employeeId, $this->employmentId, $componentId, $externalId, $status,
            $json, hash('sha256', $json, true),
            $status === 'draft' ? null : $this->userId,
            $status === 'draft' ? null : '2026-05-31 12:00:00',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function inputStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);

        return (string) $stmt->fetchColumn();
    }

    public function testManualSummaryRejectsGapsMalformedDatesAndOverlongReason(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $path = '/api/payroll/time/months/2026-05/manual-summary';
        $segment = static fn (string $from, string $to, int $units): array => ['date_from' => $from, 'date_to' => $to, 'worked_duration_units' => $units, 'overtime_duration_units' => null, 'worked_days' => null];
        $payload = ['employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Syntetický podklad', 'replace_source' => false,
            'segments' => [$segment('2026-05-01', '2026-05-15', 3000), $segment('2026-05-18', '2026-05-31', 3000)]];
        $gap = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $gap->getStatusCode(), (string) $gap->getBody());
        self::assertStringContainsString('bez mezer', (string) $gap->getBody());
        $payload['segments'] = [$segment('2026-05-01', "2026-05-31\n", 3000)];
        $malformed = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $malformed->getStatusCode(), (string) $malformed->getBody());
        self::assertStringContainsString('celý ležet ve zvoleném měsíci', (string) $malformed->getBody());
        $payload['segments'] = [$segment('2026-05-01', '2026-05-31', 3000)];
        $payload['reason'] = str_repeat('ř', 451);
        $tooLong = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(422, $tooLong->getStatusCode(), (string) $tooLong->getBody());
        self::assertSame(0, $this->countRows('payroll_time_month_manual_summaries'));

        $entry = $this->saveEntry(monthVersion: 0);
        self::assertSame(201, $entry->getStatusCode(), (string) $entry->getBody());
        $payload['reason'] = str_repeat('ř', 449) . 'Z';
        $payload['replace_source'] = true;
        $payload['row_version'] = (int) $this->json($entry)['month']['row_version'];
        $saved = $this->action->saveManualSummary($this->request('POST', $path)->withParsedBody($payload), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());
        $event = $this->db->pdo()->prepare('SELECT reason FROM payroll_time_month_events WHERE supplier_id = ? AND action = "changed" ORDER BY id DESC LIMIT 1');
        $event->execute([$this->supplierId]);
        self::assertStringEndsWith('řZ', (string) $event->fetchColumn());
    }

    public function testRestoringCurrentSourceIsNoOp(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms (supplier_id, employment_id, effective_from, planned_start_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis) VALUES (?, ?, "2026-01-01", "2026-01-01", "hourly", 20000, "wage_statement", "Syntetický výměr")')
            ->execute([$this->supplierId, $this->employmentId]);
        $restore = $this->action->restoreEntries($this->request('POST', '/api/payroll/time/months/2026-05/work-source')->withParsedBody([
            'employment_id' => $this->employmentId, 'row_version' => 0, 'reason' => 'Syntetický návrat', 'replace_source' => true, 'source' => 'entries',
        ]), new Response(), ['period' => '2026-05']);
        self::assertSame(200, $restore->getStatusCode(), (string) $restore->getBody());
        self::assertFalse($this->json($restore)['changed']);
        self::assertSame(0, $this->countRows('payroll_time_months'));
        self::assertSame(0, $this->countRows('payroll_time_month_events'));
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

    public function testPartnerDependentCanConfirmZeroFundWithoutShiftCalendar(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments
                SET relation_type = 'partner_dependent'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $this->employmentId]);

        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        self::assertSame(200, $overview->getStatusCode());
        $item = $this->json($overview)['items'][0];
        $preview = $item['jmhz_work_summary']['preview'];
        self::assertSame([], $preview['issues']);
        self::assertSame('0', $preview['suggestions']['agreed_fund_hours']);
        self::assertSame('0', $preview['suggestions']['worked_hours']);

        $approved = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $item['month']['row_version'],
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
                        'standard_fund_hours' => '0',
                        'agreed_fund_hours' => '0',
                        'weekly_work_hours' => '99',
                        'worked_hours' => '0',
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => 'Ověřeno podle podkladu ČSSZ.',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(200, $approved->getStatusCode());

        $stored = $this->db->pdo()->prepare(
            'SELECT standard_fund_millihours, agreed_fund_millihours,
                    weekly_work_centihours, evidence_days, worked_millihours
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ?'
        );
        $stored->execute([$this->supplierId, $this->employmentId]);
        $revision = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($revision);
        self::assertSame(0, (int) $revision['standard_fund_millihours']);
        self::assertSame(0, (int) $revision['agreed_fund_millihours']);
        self::assertSame(9900, (int) $revision['weekly_work_centihours']);
        self::assertSame(31, (int) $revision['evidence_days']);
        self::assertSame(0, (int) $revision['worked_millihours']);
    }

    /**
     * Pokyny MPSV k 10260: u dohody se uvede předpokládaný rozsah pracovní
     * doby v měsíci. Bez rozvrhu je nejlepším předpokladem odpracovaná doba,
     * s rozvrhem jeho plán přes trvání vztahu. Dřív se navrhovala nula,
     * protože dohoda nemá evidenční interval.
     */
    public function testAgreementSuggestsExpectedWorkingTimeAsAgreedFund(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments
                SET relation_type = 'dpp'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $this->employmentId]);
        $entry = $this->saveEntry(monthVersion: 0);
        self::assertSame(201, $entry->getStatusCode(), (string) $entry->getBody());

        $withoutCalendar = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        ))['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('7.5', $withoutCalendar['suggestions']['agreed_fund_hours']);
        self::assertSame('99', $withoutCalendar['suggestions']['weekly_work_hours']);
        self::assertSame(0, $withoutCalendar['suggestions']['evidence_days']);
        self::assertSame([], $withoutCalendar['issues']);

        $calendar = $this->action->calendar(
            $this->request('PUT', '/api/payroll/time/calendars/' . $this->employmentId)
                ->withParsedBody($this->calendarPayload()),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(201, $calendar->getStatusCode(), (string) $calendar->getBody());
        $withCalendar = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        ))['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('168', $withCalendar['suggestions']['agreed_fund_hours']);
    }

    /**
     * Pokyny MPSV k 10261: kdo není v pracovním poměru, nezapočítává se do
     * povinného podílu OZP a má „missingovou hodnotu 99". Návrh ji musí
     * nabídnout a potvrzení jinou hodnotu nepřijme.
     */
    public function testStatutoryWeeklyWorkIsSuggestedAndRequiredAsMissingValue(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments
                SET relation_type = 'partner_dependent'
              WHERE supplier_id = ? AND id = ?"
        )->execute([$this->supplierId, $this->employmentId]);

        $overview = $this->action->month(
            $this->request('GET', '/api/payroll/time/month')
                ->withQueryParams(['period' => '2026-05']),
            new Response(),
        );
        $item = $this->json($overview)['items'][0];
        $preview = $item['jmhz_work_summary']['preview'];
        self::assertSame('99', $preview['suggestions']['weekly_work_hours']);

        $rejected = $this->action->approve(
            $this->request('POST', '/api/payroll/time/months/2026-05/approve')
                ->withParsedBody([
                    'employment_id' => $this->employmentId,
                    'row_version' => $item['month']['row_version'],
                    'jmhz_work_summary' => [
                        'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
                        'standard_fund_hours' => '0',
                        'agreed_fund_hours' => '0',
                        'weekly_work_hours' => '40',
                        'worked_hours' => '0',
                        'unworked_hours_occurred' => false,
                        'work_obstacles_occurred' => false,
                        'confirmation_note' => '',
                    ],
                ]),
            new Response(),
            ['period' => '2026-05'],
        );
        self::assertSame(422, $rejected->getStatusCode());
        self::assertSame(0, $this->countRows('payroll_jmhz_work_month_revisions'));
    }

    /**
     * 10261 je stanovená týdenní doba podle § 79 zákoníku práce, ne kratší
     * sjednaná doba podle § 80. Polovina úvazku u zaměstnavatele se 40 h týdně
     * hlásí 40 (kratší doba je jen ve sjednaném fondu 10260), zaměstnanec
     * v režimu 37,5 h na plný úvazek hlásí 37,5 a stanovený fond 10259 z 37,5 h.
     */
    public function testStatedWeeklyWorkIsFullTimeOfEmployerNotAgreedShorterTime(): void
    {
        $terms = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours, workload_basis_points)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $terms->execute([$this->supplierId, $this->employmentId, '2026-01-01', '2026-01-01', '20.00', 5000]);
        $preview = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')->withQueryParams(['period' => '2026-05']),
            new Response(),
        ))['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('40', $preview['suggestions']['weekly_work_hours']);
        self::assertSame('168', $preview['suggestions']['standard_fund_hours']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET weekly_hours = ?, workload_basis_points = ?
              WHERE supplier_id = ? AND employment_id = ?'
        )->execute(['37.50', 10000, $this->supplierId, $this->employmentId]);
        $preview = $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')->withQueryParams(['period' => '2026-05']),
            new Response(),
        ))['items'][0]['jmhz_work_summary']['preview'];
        self::assertSame('37.50', $preview['suggestions']['weekly_work_hours']);
        self::assertSame('157.5', $preview['suggestions']['standard_fund_hours']);
    }

    /**
     * Stanovený fond 10259 jen za pracovní dny trvání vztahu v měsíci: datový slovník
     * „pracovní dny trvání dohody včetně svátků", a stejně u pracovního poměru, který
     * v měsíci začal nebo skončil (přijatá hlášení jiného systému: skončení 19. 4. 104 h,
     * nástup 20. 4. 72 h). Květen 2026: do 15. 5. je 11 pracovních dnů včetně svátku
     * 1. 5., od 20. 5. osm.
     */
    public function testStandardFundCoversOnlyDaysTheRelationLastedInTheMonth(): void
    {
        $preview = fn (): array => $this->json($this->action->month(
            $this->request('GET', '/api/payroll/time/month')->withQueryParams(['period' => '2026-05']),
            new Response(),
        ))['items'][0]['jmhz_work_summary']['preview'];
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2026-05-15', $this->supplierId, $this->employmentId]);
        self::assertSame('88', $preview()['suggestions']['standard_fund_hours']);

        $pdo->prepare('UPDATE payroll_employments SET end_date = NULL, start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2026-05-20', '2026-05-20', $this->supplierId, $this->employmentId]);
        self::assertSame('64', $preview()['suggestions']['standard_fund_hours']);
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
        self::assertSame('jmhz-work-month.v9', $revision['derivation_version']);
        self::assertSame(1, (int) $revision['conditional_blocks_confirmed']);
        self::assertSame(1, (int) $revision['unworked_hours_occurred']);
        self::assertSame(1, (int) $revision['work_obstacles_occurred']);
        // Svátky 1. a 8. 5. (2 × 8 h) souhrn přičte k 10275 i 10276 sám;
        // účetní zadává jen neodpracované hodiny za nepřítomnosti.
        self::assertSame(96000, (int) $revision['unworked_total_millihours']);
        self::assertSame(16000, (int) $revision['unworked_paid_millihours']);
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

    /**
     * Měsíční mřížka ukládá dvanáct dnů JEDNÍM požadavkem.
     *
     * Klient posílá `month_row_version` jen u první buňky vztahu; kdyby si ho
     * dávka nedržela z odpovědi předchozího zápisu, spadl by druhý den vždycky
     * na optimistický zámek, který zvedl náš vlastní předchozí zápis.
     */
    public function testEntryBatchWritesWholeMonthInOneRequestAndTracksMonthVersion(): void
    {
        $cells = [];
        foreach (range(4, 15) as $day) {
            $date = sprintf('2026-05-%02d', $day);
            $cells[] = [
                'employment_id' => $this->employmentId,
                'category' => 'regular',
                'starts_at' => "{$date}T08:00:00+02:00",
                'ends_at' => "{$date}T16:30:00+02:00",
                'timezone' => 'Europe/Prague',
                'break_minutes' => 30,
                'supersedes_id' => null,
                'row_version' => 0,
                'month_row_version' => 0,
            ];
        }
        $response = $this->action->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => '2026-05'])
                ->withParsedBody(['period' => '2026-05', 'cells' => $cells]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(12, $body['saved']);
        self::assertSame([], $body['failures']);
        // Odpověď nese rovnou přenačtenou stránku, takže uložení stojí jeden
        // požadavek, ne uložení plus GET.
        self::assertSame('2026-05', $body['month']['period']);
        self::assertSame(12 * 480, $body['month']['items'][0]['summary']['actual_minutes']);
        self::assertCount(12, $body['month']['items'][0]['entries']);
    }

    /**
     * Dávka musí umět částečný výsledek: co prošlo, co ne a proč, u KONKRÉTNÍ
     * buňky. Jedna vadná buňka nesmí shodit zbytek měsíce.
     */
    public function testEntryBatchReportsFailingCellWithoutLosingTheRest(): void
    {
        $response = $this->action->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => '2026-05'])
                ->withParsedBody([
                    'period' => '2026-05',
                    'cells' => [
                        [
                            'employment_id' => $this->employmentId,
                            'category' => 'regular',
                            'starts_at' => '2026-05-04T08:00:00+02:00',
                            'ends_at' => '2026-05-04T16:30:00+02:00',
                            'timezone' => 'Europe/Prague',
                            // Přestávka delší než interval — buňka se odmítne
                            // ještě před dotykem měsíce.
                            'break_minutes' => 999,
                            'row_version' => 0,
                            'month_row_version' => 0,
                        ],
                        [
                            'employment_id' => $this->employmentId,
                            'category' => 'regular',
                            'starts_at' => '2026-05-05T08:00:00+02:00',
                            'ends_at' => '2026-05-05T16:30:00+02:00',
                            'timezone' => 'Europe/Prague',
                            'break_minutes' => 30,
                            'row_version' => 0,
                            'month_row_version' => 0,
                        ],
                    ],
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(1, $body['saved']);
        self::assertCount(1, $body['failures']);
        self::assertSame(0, $body['failures'][0]['index']);
        self::assertSame('2026-05-04', $body['failures'][0]['date']);
        self::assertSame('validation_failed', $body['failures'][0]['code']);
        // Vadná buňka nezhasla platný den, který stál za ní.
        self::assertSame(480, $body['month']['items'][0]['summary']['actual_minutes']);
    }

    /**
     * Po konfliktu se u téhož vztahu nepokračuje. Hádat verzi měsíce dál by
     * znamenalo buď tiše přepsat cizí změnu, nebo sypat matoucí konflikty.
     */
    public function testEntryBatchStopsEmploymentAfterVersionConflict(): void
    {
        $response = $this->action->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withQueryParams(['period' => '2026-05'])
                ->withParsedBody([
                    'period' => '2026-05',
                    'cells' => [
                        [
                            'employment_id' => $this->employmentId,
                            'category' => 'regular',
                            'starts_at' => '2026-05-04T08:00:00+02:00',
                            'ends_at' => '2026-05-04T16:30:00+02:00',
                            'timezone' => 'Europe/Prague',
                            'break_minutes' => 30,
                            'row_version' => 0,
                            // Měsíc ještě neexistuje, takže verze 9 je konflikt.
                            'month_row_version' => 9,
                        ],
                        [
                            'employment_id' => $this->employmentId,
                            'category' => 'regular',
                            'starts_at' => '2026-05-05T08:00:00+02:00',
                            'ends_at' => '2026-05-05T16:30:00+02:00',
                            'timezone' => 'Europe/Prague',
                            'break_minutes' => 30,
                            'row_version' => 0,
                            'month_row_version' => 9,
                        ],
                    ],
                ]),
            new Response(),
        );

        $body = $this->json($response);
        self::assertSame(0, $body['saved']);
        self::assertCount(2, $body['failures']);
        self::assertSame('row_version_conflict', $body['failures'][0]['code']);
        self::assertSame('stale_after_conflict', $body['failures'][1]['code']);
        self::assertSame(0, $this->countRows('payroll_time_entries'));
    }

    /** Strop dávky je tvrdý — bez něj by jeden požadavek mohl běžet minuty. */
    public function testEntryBatchRejectsOversizedAndEmptyBatch(): void
    {
        $empty = $this->action->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withParsedBody(['period' => '2026-05', 'cells' => []]),
            new Response(),
        );
        self::assertSame(422, $empty->getStatusCode());

        $oversized = $this->action->entryBatch(
            $this->request('POST', '/api/payroll/time/entries/batch')
                ->withParsedBody([
                    'period' => '2026-05',
                    'cells' => array_fill(0, 501, ['employment_id' => $this->employmentId]),
                ]),
            new Response(),
        );
        self::assertSame(422, $oversized->getStatusCode());
        self::assertSame('validation_failed', $this->json($oversized)['error']['code']);
        self::assertSame(0, $this->countRows('payroll_time_entries'));
    }

    public function testTenantIsolationAndLimitedBearerContract(): void
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
        self::assertSame(200, $bearer->getStatusCode());
        self::assertArrayHasKey('items', $this->json($bearer));

        $calendar = $this->action->calendar(
            $this->request(
                'POST',
                "/api/payroll/time/employments/{$this->employmentId}/calendars",
                authMethod: 'bearer',
            ),
            new Response(),
            ['employmentId' => (string) $this->employmentId],
        );
        self::assertSame(403, $calendar->getStatusCode());
        self::assertSame('session_required', $this->json($calendar)['error']['code']);
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
