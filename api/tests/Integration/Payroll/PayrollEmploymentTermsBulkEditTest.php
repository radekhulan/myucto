<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollEmploymentTermsBulkEditAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBulkEdit;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadná úprava podmínek vybraných vztahů nad skutečnými tabulkami: zápis
 * je oprava platné verze (žádná nová verze), režim „doplnit" nesahá na
 * vyplněné údaje a zamčený běh vztah vyřadí s důvodem.
 */
#[Group('integration')]
final class PayrollEmploymentTermsBulkEditTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06-01';

    private Connection $db;
    private PayrollEmploymentAction $employmentAction;
    private PayrollEmploymentTermsBulkEditAction $action;
    private PayrollEmploymentTermsBulkEdit $bulk;
    private int $supplierId;
    private int $officeId;
    private int $userId;

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
        $this->employmentAction = $container->get(PayrollEmploymentAction::class);
        $this->action = $container->get(PayrollEmploymentTermsBulkEditAction::class);
        $this->bulk = $container->get(PayrollEmploymentTermsBulkEdit::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $office = $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, 'MAIN', 'Hlavní účtárna', 1)",
        );
        $office->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
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

    public function testFillModeWritesOnlyEmptyFieldsAsCorrection(): void
    {
        $empty = $this->createDpp('Syntetická Prázdná', 'DPP-EMPTY', []);
        $filled = $this->createDpp('Syntetický Vyplněný', 'DPP-FILLED', [
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 18000,
            'remuneration_basis_kind' => 'agreement',
            'remuneration_basis' => 'Syntetická dohoda',
            'cz_isco_code' => '43111',
        ]);

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, 'dpp', null);
        self::assertSame(2, $preview['summary']['editable']);
        self::assertContains('T', array_column($preview['activity_codes'], 'code'));
        self::assertNotContains('1', array_column($preview['activity_codes'], 'code'));

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000, 'cz_isco_code' => '25120'],
            $preview['editable_employment_ids'],
            $this->userId,
            null,
            null,
        );

        self::assertSame(
            ['applied' => 1, 'skipped' => 1, 'failed' => 0],
            $result['counts'],
            json_encode($result, JSON_UNESCAPED_UNICODE),
        );
        self::assertSame($empty, $result['applied'][0]['employment_id']);
        self::assertSame(['hourly_rate', 'cz_isco_code'], $result['applied'][0]['fields']);
        self::assertSame('nothing_to_change', $result['skipped'][0]['reason']);
        self::assertSame('Syntetický Vyplněný', $result['skipped'][0]['full_name']);

        $term = $this->term($empty);
        self::assertSame('hourly', $term['remuneration_type']);
        self::assertSame(25000, (int) $term['hourly_rate_minor']);
        self::assertSame('agreement', $term['remuneration_basis_kind']);
        self::assertSame('25120', $term['cz_isco_code']);
        self::assertSame(1, $this->termCount($empty), 'Oprava platné verze nesmí založit novou verzi.');

        $kept = $this->term($filled);
        self::assertSame(18000, (int) $kept['hourly_rate_minor']);
        self::assertSame('43111', $kept['cz_isco_code']);
    }

    public function testOverwriteModeReplacesFilledValues(): void
    {
        $filled = $this->createDpp('Syntetický Vyplněný', 'DPP-FILLED', [
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 18000,
            'remuneration_basis_kind' => 'agreement',
            'remuneration_basis' => 'Syntetická dohoda',
            'cz_isco_code' => '43111',
        ]);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            [
                'hourly_rate_minor' => 25000,
                'probable_hourly_earning_minor' => 26000,
                'probable_earning_rationale' => 'Syntetická sazba stejné práce',
                'activity_code' => 'U',
            ],
            [$filled],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $term = $this->term($filled);
        self::assertSame(25000, (int) $term['hourly_rate_minor']);
        self::assertSame('Syntetická dohoda', $term['remuneration_basis']);
        self::assertSame(26000, (int) $term['probable_hourly_earning_minor']);
        self::assertSame('Syntetická sazba stejné práce', $term['probable_earning_rationale']);
        self::assertSame('U', $term['activity_code']);
        self::assertSame('43111', $term['cz_isco_code']);
        self::assertSame(1, $this->termCount($filled));
    }

    public function testActivityCodeOutsideRelationFamilyIsSkipped(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-ACT', []);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_OVERWRITE,
            ['activity_code' => '1'],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame('activity_code_relation_mismatch', $result['skipped'][0]['reason']);
    }

    public function testProbableEarningWithoutRationaleIsRefused(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-PE', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['probable_hourly_earning_minor' => 26000],
            [$employment],
            $this->userId,
            null,
            null,
        );
    }

    public function testApprovedRunLocksTheEmployment(): void
    {
        $employment = $this->createDpp('Syntetická Zamčená', 'DPP-LOCK', []);
        $this->approvedRun($employment, 'approved');

        $preview = $this->bulk->preview($this->supplierId, self::PERIOD, null, null);
        self::assertSame('excluded', $preview['items'][0]['state']);
        self::assertSame('run_locked', $preview['items'][0]['reason']);
        self::assertSame([], $preview['editable_employment_ids']);

        $result = $this->bulk->apply(
            $this->supplierId,
            self::PERIOD,
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 0, 'skipped' => 1, 'failed' => 0], $result['counts']);
        self::assertSame('run_locked', $result['skipped'][0]['reason']);
        self::assertNull($this->term($employment)['hourly_rate_minor']);
    }

    /**
     * Vyplacený leden: karta by chtěla novou verzi od prvního nezúčtovaného
     * měsíce, a totéž udělá hromadná úprava. Lednová verze zůstane netknutá,
     * nová od února přenese uložené údaje a nese změněné pole.
     */
    public function testSettledMonthCreatesNewVersionFromFirstUnsettledMonth(): void
    {
        $employment = $this->createDpp('Syntetická Vyplacená', 'DPP-PAID', [
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'cz_isco_code' => '43111',
        ]);
        $this->approvedRun($employment, 'paid', '2026-01-01');

        $preview = $this->bulk->preview($this->supplierId, '2026-02-01', 'dpp', null);
        self::assertSame('editable', $preview['items'][0]['state']);
        self::assertSame('2026-02-01', $preview['items'][0]['new_version_from']);

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-02-01',
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('new_version', $result['applied'][0]['kind']);
        self::assertSame('2026-02-01', $result['applied'][0]['effective_from']);
        self::assertSame(2, $this->termCount($employment));

        $latest = $this->term($employment);
        self::assertSame('2026-02-01', $latest['effective_from']);
        self::assertSame(25000, (int) $latest['hourly_rate_minor']);
        self::assertSame('43111', $latest['cz_isco_code']);
        self::assertSame('T', $latest['activity_code']);

        $january = $this->db->pdo()->prepare(
            'SELECT effective_to, hourly_rate_minor FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? AND effective_from = ?',
        );
        $january->execute([$this->supplierId, $employment, '2026-01-01']);
        $old = $january->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('2026-01-31', $old['effective_to']);
        self::assertNull($old['hourly_rate_minor']);
    }

    public function testApprovedRunAfterSettledMonthStillLocks(): void
    {
        $employment = $this->createDpp('Syntetická Schválená', 'DPP-APPR', [
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
        ]);
        $this->approvedRun($employment, 'paid', '2026-01-01');
        $this->approvedRun($employment, 'approved', '2026-02-01');

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-02-01',
            PayrollEmploymentTermsBulkEdit::MODE_FILL,
            ['hourly_rate_minor' => 25000],
            [$employment],
            $this->userId,
            null,
            null,
        );

        self::assertSame('run_locked', $result['skipped'][0]['reason']);
        self::assertSame(1, $this->termCount($employment));
    }

    public function testWritePermissionAndSessionAreRequired(): void
    {
        $employment = $this->createDpp('Syntetická Dohoda', 'DPP-RBAC', []);
        $body = [
            'period_start' => self::PERIOD,
            'mode' => 'fill',
            'changes' => ['hourly_rate_minor' => 25000],
            'employment_ids' => [$employment],
        ];

        $readonly = $this->action->apply($this->request($body, 'readonly'), new Response());
        self::assertSame(403, $readonly->getStatusCode(), $this->bodyOf($readonly));

        $bearer = $this->action->apply($this->request($body, 'accountant', 'bearer'), new Response());
        self::assertSame(403, $bearer->getStatusCode(), $this->bodyOf($bearer));

        self::assertNull($this->term($employment)['hourly_rate_minor']);

        $allowed = $this->action->apply($this->request($body, 'accountant'), new Response());
        self::assertSame(200, $allowed->getStatusCode(), $this->bodyOf($allowed));
        self::assertSame(25000, (int) $this->term($employment)['hourly_rate_minor']);
    }

    /** @param array<string,mixed> $body */
    private function request(array $body, string $role, string $method = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/employments/terms-bulk/apply')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withParsedBody($body);
    }

    private function bodyOf(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    private function approvedRun(int $employmentId, string $status, string $period = self::PERIOD): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                 (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, ?, 1, 1)',
        )->execute([
            $this->supplierId,
            $period,
            (new \DateTimeImmutable($period))->modify('+1 month +14 days')->format('Y-m-d'),
            $status,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                  idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $revisionId = (int) $pdo->lastInsertId();
        $employeeId = $pdo->prepare('SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $employeeId->execute([$this->supplierId, $employmentId]);
        $pdo->prepare(
            "INSERT INTO payroll_run_employments
                 (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, '{}', ?)",
        )->execute([$this->supplierId, $revisionId, (int) $employeeId->fetchColumn(), $employmentId, str_repeat('c', 64)]);
    }

    /** @param array<string,mixed> $terms */
    private function createDpp(string $name, string $code, array $terms): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, 'employee', 'dpp', 0, 0, 0, NULL, 0, 1)",
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();

        $response = $this->employmentAction->create(
            (new ServerRequestFactory())
                ->createServerRequest('POST', "/api/payroll/people/{$employeeId}/employments")
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody([
                    'code' => $code,
                    'relation_type' => 'dpp',
                    'terms' => [
                        'office_id' => $this->officeId,
                        'effective_from' => self::PERIOD,
                        'contract_signed_on' => '2026-05-20',
                        'planned_start_on' => self::PERIOD,
                        'actual_start_on' => null,
                        'fixed_term_end_on' => '2026-12-31',
                        'weekly_hours' => null,
                        'workload_basis_points' => 10000,
                        'work_place' => null,
                        'regular_workplace' => null,
                        'jmhz_workplace_municipality_code' => null,
                        'jmhz_workplace_country_code' => null,
                        'jmhz_apz_contribution_status' => 'unverified',
                        'jmhz_apz_instrument_code' => null,
                        'jmhz_functional_benefits_status' => 'unverified',
                        'jmhz_temporary_assignment_status' => 'unverified',
                        'cz_isco_code' => null,
                        'activity_code' => 'T',
                        'jmhz_relationship_detail_code' => null,
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'foreign_legislation_country_code' => null,
                        'a1_certificate_until' => null,
                        'risky_work' => false,
                        'tax_declaration_signed' => false,
                        'is_primary' => true,
                        'change_reason' => 'Syntetické podmínky',
                        ...$terms,
                    ],
                ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        $body = $this->bodyOf($response);
        self::assertSame(201, $response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return (int) $decoded['employment']['id'];
    }

    /** @return array<string,mixed> */
    private function term(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis,
                    probable_hourly_earning_minor, probable_earning_rationale, cz_isco_code, activity_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function termCount(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }
}
