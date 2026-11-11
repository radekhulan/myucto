<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné průměry za čtvrtletí: „Nastavit pravděpodobný výdělek vybraným"
 * a schválení návrhů po řádcích.
 *
 * Výchozí stav odpovídá brigádníkům na dohodu, kterým hromadný návrh schválil
 * průměr z hodinové minimální mzdy (134,40 Kč), protože sazbu měli jen
 * v importovaných vstupech. Účetní jim jedním krokem nastaví 180 Kč.
 */
#[Group('integration')]
final class PayrollProbableEarningBulkApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const RATIONALE = 'Sjednaná odměna 180 Kč za hodinu podle dohod o provedení práce.';

    private Connection $db;
    private PayrollAbsenceAction $action;
    private int $userId;
    private int $supplierId;
    private int $officeId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAbsenceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "BRIG", "Syntetická účtárna", 1)')
            ->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testSetsTermsAndSupersedesMinimumWageAverage(): void
    {
        [, $employmentId] = $this->dpp('Syntetická Brigádnice');
        $minimumWage = $this->approvedMinimumWageAverage($employmentId);

        $result = $this->setProbable([$employmentId], 18_000);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['applied' => 1, 'unchanged' => 0, 'failed' => 0], $result['body']['counts']);
        self::assertSame('Syntetická Brigádnice', $result['body']['applied'][0]['employee_name']);
        self::assertSame(13_440, $result['body']['applied'][0]['superseded_average_hourly_minor']);
        self::assertSame([], $result['body']['applied'][0]['warnings']);

        $terms = $this->db->pdo()->prepare(
            'SELECT probable_hourly_earning_minor, probable_earning_rationale, effective_from
               FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $terms->execute([$this->supplierId, $employmentId]);
        $rows = $terms->fetchAll(PDO::FETCH_ASSOC);
        // Oprava platné verze, ne nová verze od dneška.
        self::assertCount(1, $rows);
        self::assertSame('2026-01-01', $rows[0]['effective_from']);
        self::assertSame(18_000, (int) $rows[0]['probable_hourly_earning_minor']);
        self::assertSame(self::RATIONALE, $rows[0]['probable_earning_rationale']);

        $snapshots = $this->snapshots($employmentId);
        self::assertSame('superseded', $snapshots[$minimumWage]['status']);
        $new = $snapshots[$result['body']['applied'][0]['snapshot_id']];
        self::assertSame('approved', $new['status']);
        self::assertSame('probable', $new['source_kind']);
        self::assertSame(18_000, (int) $new['average_hourly_minor']);
        self::assertSame(self::RATIONALE, $new['rationale']);
        self::assertSame('bulk_probable', json_decode((string) $new['input_trace'], true)['automatic']);

        $again = $this->setProbable([$employmentId], 18_000);
        self::assertSame(['applied' => 0, 'unchanged' => 1, 'failed' => 0], $again['body']['counts']);
        self::assertCount(2, $this->snapshots($employmentId));
    }

    public function testRefusesWhereFrozenJmhzUsesTheAverageAndProceedsWithOthers(): void
    {
        [$frozenEmployee, $frozen] = $this->dpp('Syntetický Zmrazený');
        [, $open] = $this->dpp('Syntetická Otevřená');
        $frozenAverage = $this->approvedMinimumWageAverage($frozen);
        $this->approvedMinimumWageAverage($open);
        $revisionId = $this->insertClosedRun('2026-02-01', $frozenEmployee, $frozen);
        $this->freezeJmhz('2026-02-01', $revisionId);

        $result = $this->setProbable([$frozen, $open], 18_000);

        self::assertSame(200, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['applied' => 1, 'unchanged' => 0, 'failed' => 1], $result['body']['counts']);
        self::assertSame($open, $result['body']['applied'][0]['employment_id']);
        $failure = $result['body']['failed'][0];
        self::assertSame($frozen, $failure['employment_id']);
        self::assertSame('Syntetický Zmrazený', $failure['employee_name']);
        self::assertSame('average_reported_in_jmhz', $failure['code']);
        self::assertSame('2/2026', $failure['params']['period']);
        self::assertStringContainsString('opravné hlášení JMHZ', $failure['message']);

        // Nic se tiše nezměnilo: průměr ani podmínky.
        $snapshots = $this->snapshots($frozen);
        self::assertCount(1, $snapshots);
        self::assertSame('approved', $snapshots[$frozenAverage]['status']);
        $terms = $this->db->pdo()->prepare(
            'SELECT probable_hourly_earning_minor FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $terms->execute([$this->supplierId, $frozen]);
        self::assertNull($terms->fetchColumn() ?: null);
    }

    public function testRoleWithoutEmploymentWriteIsRefused(): void
    {
        [, $employmentId] = $this->dpp('Syntetická Brigádnice');
        $average = $this->approvedMinimumWageAverage($employmentId);
        $role = new EffectiveRole(0, 'Mzdová pomoc', 'staff', true, [
            'payroll' => AccessLevel::WRITE->value,
            'payroll.time.write' => AccessLevel::WRITE->value,
        ]);

        $result = $this->setProbable([$employmentId], 18_000, $role);

        self::assertSame(403, $result['status']);
        self::assertSame('approved', $this->snapshots($employmentId)[$average]['status']);
        self::assertCount(1, $this->snapshots($employmentId));
    }

    /**
     * Vztah bez připraveného návrhu dávku nezastaví: schválí se ostatní
     * a přeskočený se vrátí se jménem a kódy důvodů.
     */
    public function testMixedBatchApprovesReadyRowsAndReportsTheOthers(): void
    {
        [$readyEmployee, $ready] = $this->dpp('Syntetická Připravená', '2026-06-01');
        $this->insertClosedRun('2026-06-01', $readyEmployee, $ready);
        // Vztah trvá od roku 2025 a nemá jediný běh: chybí rozhodné období.
        [, $blocked] = $this->dpp('Syntetický Blokovaný', '2025-01-01', withTerms: false);

        $page = $this->call('averageCandidates', 'GET', ['year' => 2026, 'quarter' => 2]);
        $versions = array_column($page['body']['items'], 'input_version', 'employment_id');
        $result = $this->call('createAutomaticAverages', 'POST', [
            'year' => 2026,
            'quarter' => 2,
            'items' => [
                ['employment_id' => $ready, 'input_version' => $versions[$ready]],
                ['employment_id' => $blocked, 'input_version' => $versions[$blocked]],
            ],
        ]);

        self::assertSame(201, $result['status'], json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $result['body']['averages']);
        self::assertSame($ready, (int) $result['body']['averages'][0]['employment_id']);
        self::assertCount(1, $result['body']['skipped']);
        $skipped = $result['body']['skipped'][0];
        self::assertSame($blocked, $skipped['employment_id']);
        self::assertSame('Syntetický Blokovaný', $skipped['employee_name']);
        self::assertContains('probable_earning_not_recorded', $skipped['blockers']);
        self::assertSame([], $this->snapshots($blocked));
    }

    /**
     * @param list<int> $employmentIds
     * @return array{status:int,body:array<string,mixed>}
     */
    private function setProbable(array $employmentIds, int $hourlyMinor, ?EffectiveRole $role = null): array
    {
        return $this->call('setProbableEarningBulk', 'POST', [
            'year' => 2026,
            'quarter' => 1,
            'probable_hourly_minor' => $hourlyMinor,
            'rationale' => self::RATIONALE,
            'employment_ids' => $employmentIds,
        ], $role);
    }

    /** @return array{0:int,1:int} employee_id, employment_id */
    private function dpp(string $name, string $start = '2026-01-01', bool $withTerms = true): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, 'employee', 1)",
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status, start_date, is_legacy_projection)
             VALUES (?, ?, ?, ?, 'dpp', 'active', ?, 0)",
        )->execute([$this->supplierId, $employeeId, $this->officeId, 'SYN-DPP-' . $employeeId, $start]);
        $employmentId = (int) $pdo->lastInsertId();
        if ($withTerms) {
            // Jako u importovaných brigádníků: v podmínkách není sazba ani
            // měsíční mzda, odměny jsou jen v importovaných vstupech.
            $pdo->prepare(
                'INSERT INTO payroll_employment_terms
                    (supplier_id, employment_id, office_id, effective_from, planned_start_on, weekly_hours)
                 VALUES (?, ?, ?, ?, ?, 40)',
            )->execute([$this->supplierId, $employmentId, $this->officeId, $start, $start]);
        }

        return [$employeeId, $employmentId];
    }

    /** Schválený průměr za 1. čtvrtletí 2026 z hodinové minimální mzdy. */
    private function approvedMinimumWageAverage(int $employmentId): int
    {
        $created = $this->call('createAverage', 'POST', [
            'employment_id' => $employmentId,
            'applicable_year' => 2026,
            'applicable_quarter' => 1,
            'decisive_from' => '2025-10-01',
            'decisive_to' => '2025-12-31',
            'gross_earnings_minor' => 0,
            'longer_period_allocated_minor' => 0,
            'worked_minutes' => 0,
            'worked_days' => 0,
            'probable_hourly_minor' => 13_440,
            'rationale' => 'Hodinová minimální mzda (§ 355 odst. 2 ZP).',
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $snapshot = $created['body']['snapshot'];
        $approved = $this->call(
            'approveAverage',
            'POST',
            ['row_version' => (int) $snapshot['row_version']],
            null,
            ['id' => (string) $snapshot['id']],
        );
        self::assertSame(200, $approved['status'], json_encode($approved['body'], JSON_UNESCAPED_UNICODE));

        return (int) $snapshot['id'];
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshots(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, source_kind, average_hourly_minor, rationale, input_trace
               FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    /** Uzavřený běh za měsíc, ve kterém je vztah v aktuální schválené revizi. */
    private function insertClosedRun(string $periodStart, int $employeeId, int $employmentId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "closed", 1)',
        )->execute([
            $this->supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+10 days')->format('Y-m-d'),
        ]);
        $runId = (int) $pdo->lastInsertId();
        $snapshotJson = (string) json_encode(['schema_version' => 'payroll-run-input.v2']);
        $resultJson = (string) json_encode(['schema_version' => 'payroll-run-result.v1']);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "payroll-run-input.v2", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            hash('sha256', 'manifest' . $periodStart . $employmentId),
            $snapshotJson,
            hash('sha256', $snapshotJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', 'idempotency' . $periodStart . $employmentId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $inputJson = (string) json_encode([
            'employment' => ['id' => $employmentId],
            'time_month' => [
                'id' => 1,
                'status' => 'approved',
                'revision_no' => 1,
                'row_version' => 2,
                'jmhz_work_summary' => [
                    'id' => 1,
                    'derivation_version' => 'jmhz-work-month.v2',
                    'source_snapshot_json' => '{}',
                    'source_snapshot_sha256' => hash('sha256', '{}'),
                    'summary_sha256' => str_repeat('b', 64),
                    'values' => ['evidence_days' => 28, 'worked_millihours' => 60_000],
                ],
            ],
        ]);
        $resultRowJson = (string) json_encode([
            'employment_id' => $employmentId,
            'totals' => ['average_earning_base_minor' => 1_080_000],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            $inputJson,
            hash('sha256', $inputJson),
            $resultRowJson,
            hash('sha256', $resultRowJson),
        ]);

        return $revisionId;
    }

    /** Zmrazená příprava JMHZ ze schválené revize běhu. */
    private function freezeJmhz(string $periodStart, int $revisionId): void
    {
        $pdo = $this->db->pdo();
        $runId = (int) $pdo->query('SELECT run_id FROM payroll_run_revisions WHERE id = ' . $revisionId)->fetchColumn();
        $pdo->prepare(
            "INSERT INTO payroll_jmhz_preparation_snapshots
                (supplier_id, environment, run_id, source_revision_id, period_start, scenario_key,
                 builder_version, readiness_status, issue_count, source_manifest_json,
                 source_manifest_sha256, readiness_json, readiness_sha256, snapshot_ciphertext,
                 snapshot_fingerprint, request_fingerprint, idempotency_key_hash, created_by)
             VALUES (?, 'production', ?, ?, ?, 'scenario_1', 'jmhz-preparation-source.v1',
                     'source_ready', 0, '{}', ?, '{}', ?, 'enc:v2:synteticky', ?, ?, ?, ?)",
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $periodStart,
            hash('sha256', 'manifest'),
            hash('sha256', 'readiness'),
            hash('sha256', 'snapshot'),
            hash('sha256', 'request' . $revisionId),
            hash('sha256', 'idempotency' . $revisionId, true),
            $this->userId,
        ]);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $args
     * @return array{status:int,body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $params, ?EffectiveRole $role = null, array $args = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/payroll/time/averages')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }
        $request = $httpMethod === 'GET' ? $request->withQueryParams($params) : $request->withParsedBody($params);
        $response = $args === []
            ? $this->action->{$method}($request, new Response())
            : $this->action->{$method}($request, new Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
