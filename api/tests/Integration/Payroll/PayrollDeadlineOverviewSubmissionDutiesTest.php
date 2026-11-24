<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollMonthlyAgendaDutyRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Deadline\PayrollTaxStatementDeadlinePolicy;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyAgendaDutyService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Podání v hlídači termínů: co se hlásí, co ne a jak.
 *
 * - měsíční hlášení ze SCHVÁLENÉHO běhu bez připravené povinnosti se hlásí
 *   (dřív z Termínů zmizelo, Měsíční přehled ho přitom ukazoval),
 * - odeslané podání po lhůtě čeká na výsledek, není „po termínu",
 * - odhláška za událost vyřízenou předchozím programem se jako zmeškaná
 *   povinnost MyÚčta nehlásí.
 */
#[Group('integration')]
final class PayrollDeadlineOverviewSubmissionDutiesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDeadlineOverviewService $service;
    private \DI\Container $container;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->container = $container;
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_obligations',
            'payroll_submission_deadlines',
            'payroll_submissions',
            'payroll_external_jmhz_submissions',
            'payroll_module_state',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(
            new \DateTimeImmutable('2026-11-24 09:00:00', new \DateTimeZone('Europe/Prague')),
        );
        $assessments = new PayrollDeadlineAssessmentService($clock);
        $this->service = new PayrollDeadlineOverviewService(
            new PayrollDeadlineOverviewRepository($this->db),
            $assessments,
            new PayrollRegistrationChangeProposalRepository($this->db),
            $container->get(PayrollRegistrationChangeDetectionService::class),
            new PayrollTaxStatementDeadlinePolicy(),
            new PayrollSicknessCaseRepository($this->db),
            new SicknessDeadlinePolicy($container->get(PayrollRulesetProvider::class)),
            $clock,
            agendaDuties: new PayrollMonthlyAgendaDutyService(
                new PayrollMonthlyAgendaDutyRepository($this->db),
                $container->get(JmhzDeadlinePolicy::class),
                new HealthNotificationDeadlinePolicy(),
                $assessments,
            ),
            historicalPeriods: $container->get(PayrollHistoricalPeriodService::class),
        );

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
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
     * Běh za září je schválený, hlášení JMHZ nikdo nepřipravil. Lhůta uplynula
     * a hlídač o měsíci mlčel, protože znal jen evidenci, převzaté měsíce
     * a měsíce BEZ schváleného běhu.
     */
    public function testApprovedRunWithoutPreparedReportIsOverdue(): void
    {
        $runId = $this->approvedRun('2026-09-01');

        $items = $this->jmhzItems();

        self::assertCount(1, $items);
        self::assertSame('agenda_duty:' . JmhzSubmissionBridgeService::AGENDA_CODE . ':' . $runId, $items[0]['reference']);
        self::assertSame('2026-09', $items[0]['period']);
        self::assertSame('overdue', $items[0]['phase']);
        self::assertSame('not_prepared', $items[0]['status']);
    }

    /** Připravená povinnost mluví sama za sebe; druhý řádek o téže věci nevznikne. */
    public function testRegisteredObligationIsNotReportedTwice(): void
    {
        $runId = $this->approvedRun('2026-09-01');
        $obligationId = $this->obligation(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run:' . $runId . ':office:1',
            'payroll_run_approved',
            'run:' . $runId,
            '2026-09-01',
            '2026-09-30',
            '2026-10-01',
            '2026-10-20',
        );

        $items = $this->jmhzItems();

        self::assertSame(['payroll_obligation:' . $obligationId], array_column($items, 'reference'));
    }

    /**
     * Hlášení podané mimo MyÚčto (portál ČSSZ, potvrzení účetní) povinnost
     * splnilo, i když ho MyÚčto samo nepřipravilo.
     */
    public function testReportFiledOutsideMyUctoIsNotReported(): void
    {
        $this->approvedRun('2026-09-01');
        $store = $this->container->get(JmhzExternalSubmissionStore::class);
        self::assertInstanceOf(JmhzExternalSubmissionStore::class, $store);
        $store->attestMonthly($this->supplierId, 'production', '2026-09', '2026-10-15', null, null);

        self::assertSame([], $this->jmhzItems());
    }

    /**
     * Odeslané podání, u kterého aplikace nemá přijetí, čeká na výsledek —
     * i po lhůtě. Že šlo po termínu, nese příznak, ne fáze.
     */
    public function testSubmittedAfterDueAwaitsResultWithLateFlag(): void
    {
        $obligationId = $this->obligation(
            'PPZ_2026',
            'payroll_run:5:111',
            'payroll_health_payment_overview',
            'payroll_run:5:111',
            '2026-09-01',
            '2026-09-30',
            '2026-10-01',
            '2026-10-20',
            'submitted',
        );
        $this->submission($obligationId, 'submitted', '2026-11-02 10:00:00');

        $items = array_values(array_filter(
            $this->service->overview($this->supplierId, 'production')['items'],
            static fn (array $item): bool => $item['reference'] === 'payroll_obligation:' . $obligationId,
        ));

        self::assertCount(1, $items);
        self::assertSame('awaiting_result', $items[0]['phase']);
        self::assertFalse($items[0]['is_overdue']);
        self::assertTrue($items[0]['submitted_late']);
        self::assertFalse($items[0]['dispatched_via_isds']);
    }

    /**
     * Vztah skončil v převzatém srpnu, mzdy vede MyÚčto od září. Odhlášku
     * podal předchozí program; povinnost založená přípravou v MyÚčtu se nesmí
     * hlásit jako zmeškaná. Jakmile ji účetní z MyÚčta přesto odešle, hlídá
     * se dál její vlastní průběh.
     */
    public function testDeregistrationHandledByPredecessorIsNotOverdue(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, "setup", "2026-09-01", 1)
             ON DUPLICATE KEY UPDATE start_period = VALUES(start_period)',
        )->execute([$this->supplierId]);
        $obligationId = $this->obligation(
            PayrollRegistrationSubmissionService::AGENDA_REGZEC,
            'employment:77',
            PayrollRegistrationSubmissionService::SOURCE_EVENT_TYPE,
            'payroll_registration_event:91',
            '2026-08-03',
            '2026-08-11',
            '2026-08-03',
            '2026-08-11',
        );
        $reference = 'payroll_obligation:' . $obligationId;
        $references = fn (): array => array_column(
            $this->service->overview($this->supplierId, 'production')['items'],
            'reference',
        );

        self::assertNotContains($reference, $references());

        $this->submission($obligationId, 'ready', null);
        self::assertNotContains($reference, $references(), 'Připravené, neodeslané podání nic nemění.');

        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "rejected", submitted_at = "2026-11-20 09:00:00", decided_at = "2026-11-21 09:00:00"
              WHERE supplier_id = ? AND obligation_id = ?',
        )->execute([$this->supplierId, $obligationId]);
        self::assertContains($reference, $references(), 'Odmítnuté podání z MyÚčta se hlídá dál.');
    }

    /** Událost v prvním měsíci vedení mezd je povinnost MyÚčta. */
    public function testDeregistrationInFirstOwnMonthStaysWatched(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, "setup", "2026-09-01", 1)
             ON DUPLICATE KEY UPDATE start_period = VALUES(start_period)',
        )->execute([$this->supplierId]);
        $obligationId = $this->obligation(
            PayrollRegistrationSubmissionService::AGENDA_REGZEC,
            'employment:78',
            PayrollRegistrationSubmissionService::SOURCE_EVENT_TYPE,
            'payroll_registration_event:92',
            '2026-09-03',
            '2026-09-11',
            '2026-09-03',
            '2026-09-11',
        );

        $items = array_values(array_filter(
            $this->service->overview($this->supplierId, 'production')['items'],
            static fn (array $item): bool => $item['reference'] === 'payroll_obligation:' . $obligationId,
        ));

        self::assertCount(1, $items);
        self::assertSame('overdue', $items[0]['phase']);
    }

    /** @return list<array<string,mixed>> */
    private function jmhzItems(): array
    {
        return array_values(array_filter(
            $this->service->overview($this->supplierId, 'production')['items'],
            static fn (array $item): bool => $item['source'] === 'submission'
                && $item['title'] === JmhzSubmissionBridgeService::AGENDA_CODE,
        ));
    }

    private function approvedRun(string $periodStart): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, "2026-10-10", "closed", 1)',
        )->execute([$this->supplierId, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = json_encode([
            'schema_version' => 'payroll-run-input-snapshot.test',
            'people' => [['statutory_evidence' => ['health' => ['coverage' => ['insurer_code' => '111']]]]],
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, NULL, "regular", "approved", "test.v1", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
        ]);

        return $runId;
    }

    private function obligation(
        string $agendaCode,
        string $subjectReference,
        string $sourceEventType,
        string $sourceEventReference,
        string $periodStart,
        string $periodEnd,
        string $earliestOn,
        string $dueOn,
        string $status = 'open',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type, subject_reference, period_start, period_end,
                 obligation_kind, preferred_channel, status, source_event_type, source_event_reference,
                 source_event_hash, request_fingerprint, idempotency_key_hash)
             VALUES (?, "production", ?, "other", ?, ?, ?, "regular", "isds", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $agendaCode,
            $subjectReference,
            $periodStart,
            $periodEnd,
            $status,
            $sourceEventType,
            $sourceEventReference,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind, earliest_submission_on, due_on,
                 calendar_basis, ruleset_id, ruleset_hash, trigger_event_hash)
             VALUES (?, "production", ?, "regular", ?, ?, "calendar_days", "test", ?, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $earliestOn,
            $dueOn,
            str_repeat('c', 64),
            str_repeat('a', 64),
        ]);

        return $obligationId;
    }

    private function submission(int $obligationId, string $status, ?string $submittedAt): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind, channel, status,
                 source_snapshot_hash, request_fingerprint, idempotency_key_hash, submitted_at)
             VALUES (?, "production", ?, "regular", "isds", ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $status,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
            $submittedAt,
        ]);
    }
}
