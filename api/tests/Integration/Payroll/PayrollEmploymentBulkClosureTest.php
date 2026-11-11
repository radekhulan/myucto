<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hromadné ukončení skončených dohod nad skutečnými tabulkami: výběr kandidátů
 * podle sjednaného konce a aktivity, zápis přes přechod stavu vztahu a chyba
 * jedné položky, která nezastaví ostatní.
 */
#[Group('integration')]
final class PayrollEmploymentBulkClosureTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TODAY = '2026-10-15';

    private Connection $db;
    private PayrollEmploymentBulkClosure $bulk;
    private int $supplierId;
    private int $foreignSupplierId;
    private int $userId;
    private int $componentId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Chybí tabulka podmínek pracovního vztahu.');
        }
        $this->bulk = $container->get(PayrollEmploymentBulkClosure::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->foreignSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->componentId = $this->component($this->supplierId);
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

    public function testCandidatesFollowAgreedEndAndRecentActivity(): void
    {
        $expired = $this->employment($this->supplierId, 'Uplynulá Dohoda', 'dpp', '2026-03-01', '2026-06-30');
        $this->employment($this->supplierId, 'Budoucí Dohoda', 'dpp', '2026-03-01', '2026-12-31');
        $idle = $this->employment($this->supplierId, 'Nečinná Dohoda', 'dpc', '2026-01-01', null);
        $this->input($idle, '2026-05-01', 150_000);
        // Nulový vstup (nulové hlášení) aktivitou není.
        $this->input($idle, '2026-09-01', 0);
        $working = $this->employment($this->supplierId, 'Pracující Dohoda', 'dpp', '2026-01-01', null);
        $this->timeEntry($working, '2026-09-10');
        $neverWorked = $this->employment($this->supplierId, 'Bez Práce', 'dpp', '2026-02-10', null);
        $this->employment($this->supplierId, 'Čerstvá Dohoda', 'dpp', '2026-09-01', null);
        $this->employment($this->supplierId, 'Hlavní Poměr', 'employment', '2025-01-01', null);
        $old = $this->employment($this->supplierId, 'Dávno Skončený', 'dpp', '2024-01-01', '2024-06-30', 'ended');
        $this->employment($this->supplierId, 'Nedávno Skončený', 'dpp', '2026-01-01', '2026-08-31', 'ended');
        // Jiná firma se stejnými znaky se do výběru nesmí dostat.
        $this->employment($this->foreignSupplierId, 'Cizí Dohoda', 'dpp', '2026-01-01', '2026-02-28');
        $this->employment($this->foreignSupplierId, 'Cizí Nečinná', 'dpp', '2026-01-01', null);

        $preview = $this->bulk->preview($this->supplierId, 3, 12, self::TODAY);

        self::assertSame('2026-08-01', $preview['activity_since']);
        $byId = array_column($preview['items'], null, 'employment_id');
        self::assertEqualsCanonicalizing([$expired, $idle, $neverWorked, $old], array_keys($byId));

        self::assertSame('expired', $byId[$expired]['group']);
        self::assertSame('2026-06-30', $byId[$expired]['proposed_on']);
        self::assertNull($byId[$expired]['blocked_reason']);

        self::assertSame('inactive', $byId[$idle]['group']);
        self::assertSame('2026-05', $byId[$idle]['last_activity_period']);
        self::assertSame('2026-05-31', $byId[$idle]['proposed_on']);

        // Bez jakékoli aktivity se navrhne konec měsíce nástupu.
        self::assertSame('2026-02-28', $byId[$neverWorked]['proposed_on']);

        self::assertSame('archive', $byId[$old]['group']);
        self::assertSame('archive', $byId[$old]['action']);
        self::assertSame(self::TODAY, $byId[$old]['proposed_on']);

        self::assertSame(['expired' => 1, 'inactive' => 2, 'archive' => 1, 'blocked' => 0], $preview['summary']);

        // Docházka v září je uvnitř tříměsíčního okna; při okně jednoho měsíce
        // se dohoda nabídne s koncem k poslednímu dni září.
        $short = array_column(
            $this->bulk->preview($this->supplierId, 1, 12, self::TODAY)['items'],
            null,
            'employment_id',
        );
        self::assertSame('2026-09-30', $short[$working]['proposed_on'] ?? null);
    }

    /**
     * Agentura má schválenou mzdu za únor a v říjnu teprve zpracovává jaro.
     * Nečinnost se měří k únoru: dohoda s prací v květnu (vstup ještě není
     * ve mzdách) nečinná není. Dřív se měřilo k dnešku a nabízela se.
     */
    public function testInactivityIsMeasuredToTheLastApprovedPayrollMonth(): void
    {
        $this->approvedRun('2026-02-01');
        $springWork = $this->employment($this->supplierId, 'Jarní Brigáda', 'dpp', '2026-01-01', null);
        $this->input($springWork, '2026-05-01', 120_000);
        $autumnOnly = $this->employment($this->supplierId, 'Podzimní Brigáda', 'dpp', '2025-10-01', null);
        $this->input($autumnOnly, '2025-11-01', 90_000);

        $preview = $this->bulk->preview($this->supplierId, 3, 12, self::TODAY);

        $byId = array_column($preview['items'], null, 'employment_id');
        self::assertArrayNotHasKey($springWork, $byId);
        self::assertSame('inactive', $byId[$autumnOnly]['group'] ?? null);
        self::assertSame('2025-11-30', $byId[$autumnOnly]['proposed_on']);
        self::assertSame('2025-12-01', $preview['activity_since']);
        self::assertSame('2026-02-28', $preview['activity_reference_on']);
    }

    /**
     * Sjednaný konec jen v podmínkách (vztah bez `end_date`) je taky uplynulý
     * konec: náhled ho nabídne k ukončení a noční úloha ho ukončí.
     */
    public function testAgreedEndFromTermsCountsAsExpiredForPreviewAndCron(): void
    {
        $employmentId = $this->employment($this->supplierId, 'Konec V Podmínkách', 'dpp', '2026-03-01', null);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, fixed_term_end_on, weekly_hours)
             VALUES (?, ?, '2026-03-01', '2026-03-01', '2026-03-31', 40)",
        )->execute([$this->supplierId, $employmentId]);

        $item = array_column($this->bulk->preview($this->supplierId, 3, 12, self::TODAY)['items'], null, 'employment_id')[$employmentId] ?? null;
        self::assertSame('expired', $item['group'] ?? null);
        self::assertSame('2026-03-31', $item['proposed_on']);
        self::assertSame('2026-03-31', $item['agreed_end_on']);

        $result = $this->bulk->endExpiredAgreements($this->supplierId, self::TODAY);
        self::assertSame([$employmentId], $result['ended'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(['status' => 'ended', 'end_date' => '2026-03-31'], $this->state($employmentId));
    }

    public function testExpiredAgreementWithWorkAfterTheEndIsFlagged(): void
    {
        $employmentId = $this->employment($this->supplierId, 'Pokračující Dohoda', 'dpp', '2026-01-01', '2026-06-30');
        $this->input($employmentId, '2026-07-01', 80_000);

        $preview = $this->bulk->preview($this->supplierId, 3, 12, self::TODAY);

        self::assertSame('activity_after_end', $preview['items'][0]['blocked_reason']);
        self::assertSame('2026-07', $preview['items'][0]['blocked_period']);
        self::assertSame(1, $preview['summary']['blocked']);
    }

    public function testBulkClosureEndsThroughTransitionAndOneConflictDoesNotStopOthers(): void
    {
        $expired = $this->employment($this->supplierId, 'Uplynulá Dohoda', 'dpp', '2026-03-01', '2026-06-30');
        $idle = $this->employment($this->supplierId, 'Nečinná Dohoda', 'dpc', '2026-01-01', null);
        $continued = $this->employment($this->supplierId, 'Pokračující Dohoda', 'dpp', '2026-01-01', '2026-06-30');
        $this->input($continued, '2026-08-01', 80_000);
        $stale = $this->employment($this->supplierId, 'Souběžně Změněná', 'dpp', '2026-01-01', '2026-05-31');
        $old = $this->employment($this->supplierId, 'Dávno Skončený', 'dpp', '2024-01-01', '2024-06-30', 'ended');

        $result = $this->bulk->apply($this->supplierId, [
            ['employment_id' => $expired, 'action' => 'end', 'effective_on' => '2026-06-30', 'row_version' => 1],
            // Datum změněné v náhledu.
            ['employment_id' => $idle, 'action' => 'end', 'effective_on' => '2026-04-15', 'row_version' => 1],
            ['employment_id' => $continued, 'action' => 'end', 'effective_on' => '2026-06-30', 'row_version' => 1],
            ['employment_id' => $stale, 'action' => 'end', 'effective_on' => '2026-05-31', 'row_version' => 7],
            ['employment_id' => $old, 'action' => 'archive', 'effective_on' => self::TODAY, 'row_version' => 1],
        ], $this->userId, null, null, self::TODAY);

        self::assertSame(['applied' => 3, 'failed' => 2], $result['counts']);
        $failed = array_column($result['failed'], 'code', 'employment_id');
        self::assertSame('activity_after_end', $failed[$continued]);
        self::assertSame('row_version_conflict', $failed[$stale]);

        self::assertSame(['status' => 'ended', 'end_date' => '2026-06-30'], $this->state($expired));
        self::assertSame(['status' => 'ended', 'end_date' => '2026-04-15'], $this->state($idle));
        self::assertSame(['status' => 'active', 'end_date' => '2026-06-30'], $this->state($continued));
        self::assertSame(['status' => 'active', 'end_date' => '2026-05-31'], $this->state($stale));
        self::assertSame('archived', $this->state($old)['status']);

        // Přechod stavu zapsal událost i kontrolní seznam odchodu jako ruční ukončení.
        $events = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ? AND event_type = 'status_changed' AND to_status = 'ended'",
        );
        $events->execute([$this->supplierId, $expired]);
        self::assertSame(1, (int) $events->fetchColumn());
        $checklist = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND phase = 'offboarding'",
        );
        $checklist->execute([$this->supplierId, $expired]);
        self::assertGreaterThan(0, (int) $checklist->fetchColumn());
    }

    public function testForeignEmploymentAndFutureDateAreRefused(): void
    {
        $foreign = $this->employment($this->foreignSupplierId, 'Cizí Dohoda', 'dpp', '2026-01-01', '2026-02-28');

        $result = $this->bulk->apply($this->supplierId, [
            ['employment_id' => $foreign, 'action' => 'end', 'effective_on' => '2026-02-28', 'row_version' => 1],
        ], $this->userId, null, null, self::TODAY);
        self::assertSame(['applied' => 0, 'failed' => 1], $result['counts']);
        self::assertSame('active', $this->state($foreign, $this->foreignSupplierId)['status']);

        $this->expectException(\InvalidArgumentException::class);
        $this->bulk->apply($this->supplierId, [
            ['employment_id' => $foreign, 'action' => 'end', 'effective_on' => '2026-11-30', 'row_version' => 1],
        ], $this->userId, null, null, self::TODAY);
    }

    public function testApplyRefusesEmploymentThatIsNotACandidate(): void
    {
        // Běžící dohoda s nedávnou prací kandidátem není; uplynulá dohoda je, ale k ukončení, ne archivaci.
        $running = $this->employment($this->supplierId, 'Běžící Dohoda', 'dpp', '2026-01-01', null);
        $this->input($running, '2026-10-01', 50_000);
        $expired = $this->employment($this->supplierId, 'Uplynulá Jinak', 'dpp', '2026-01-01', '2026-06-30');

        $result = $this->bulk->apply($this->supplierId, [
            ['employment_id' => $running, 'action' => 'end', 'effective_on' => self::TODAY, 'row_version' => 1],
            ['employment_id' => $expired, 'action' => 'archive', 'effective_on' => self::TODAY, 'row_version' => 1],
        ], $this->userId, null, null, self::TODAY);

        self::assertSame(['applied' => 0, 'failed' => 2], $result['counts']);
        self::assertSame(['not_candidate', 'not_candidate'], array_column($result['failed'], 'code'));
        self::assertSame('active', $this->state($running)['status']);
        self::assertSame('active', $this->state($expired)['status']);
    }

    public function testLimitPerRequest(): void
    {
        $items = [];
        for ($id = 1; $id <= PayrollEmploymentBulkClosure::MAX_ITEMS + 1; ++$id) {
            $items[] = ['employment_id' => $id, 'action' => 'end', 'effective_on' => '2026-01-31', 'row_version' => 1];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->bulk->apply($this->supplierId, $items, $this->userId, null, null, self::TODAY);
    }

    private function employment(
        int $supplierId,
        string $name,
        string $relationType,
        string $startOn,
        ?string $endOn,
        string $status = 'active',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, 'employee', 1)",
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)',
        )->execute([$supplierId, $employeeId, 'BULK-' . $employeeId, $relationType, $status, $startOn, $startOn, $endOn]);

        return (int) $pdo->lastInsertId();
    }

    /** Schválená mzda firmy za měsíc (hranice zpracovaných měsíců). */
    private function approvedRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, 'approved', 1, 1)",
        )->execute([$this->supplierId, $periodStart, (new \DateTimeImmutable($periodStart))->modify('+1 month +14 days')->format('Y-m-d')]);
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, (int) $pdo->lastInsertId(), str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
    }

    private function component(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, "ODMENA_DPP", "Odměna z dohody", "base_wage",
                     "monetary", "regular", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", "included", "521", "331", "2026-01-01")',
        )->execute([$supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function input(int $employmentId, string $periodStart, int $amountMinor): void
    {
        $employeeId = (int) $this->db->pdo()
            ->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $employmentId)
            ->fetchColumn();
        $json = CanonicalJson::encode(['code' => 'ODMENA_DPP', 'component_id' => $this->componentId]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id,
                 period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash)
             VALUES (?, ?, ?, ?, ?, ?, "manual", "draft", ?, ?)',
        )->execute([
            $this->supplierId,
            $employeeId,
            $employmentId,
            $this->componentId,
            $periodStart,
            $amountMinor,
            $json,
            hash('sha256', $json, true),
        ]);
    }

    private function timeEntry(int $employmentId, string $day): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_entries
                (supplier_id, employment_id, series_key, category, starts_at_utc, ends_at_utc,
                 timezone_name, source_kind, source_hash, status)
             VALUES (?, ?, ?, 'regular', ?, ?, 'Europe/Prague', 'manual', ?, 'draft')",
        )->execute([
            $this->supplierId,
            $employmentId,
            bin2hex(random_bytes(16)),
            $day . ' 06:00:00',
            $day . ' 14:00:00',
            random_bytes(32),
        ]);
    }

    /** @return array{status:string,end_date:?string} */
    private function state(int $employmentId, ?int $supplierId = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId ?? $this->supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return ['status' => (string) $row['status'], 'end_date' => $row['end_date'] === null ? null : (string) $row['end_date']];
    }
}
