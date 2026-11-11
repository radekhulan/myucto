<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBody;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollEmploymentWorkplaceBulkFill;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné doplnění místa výkonu práce pro JMHZ nad skutečnými tabulkami:
 * zápis jde přes opravu podmínek (validátor, číselník, historie) a vyplněný
 * údaj se nikdy nepřepisuje.
 */
#[Group('integration')]
final class PayrollEmploymentWorkplaceBulkFillTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PRAGUE = '554782';

    private Connection $db;
    private PayrollEmploymentAction $action;
    private PayrollEmploymentWorkplaceBulkFill $bulk;
    private PayrollEmploymentRepository $repository;
    private PayrollEmploymentValidator $validator;
    private int $supplierId;
    private int $employeeId;
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
        $this->action = $container->get(PayrollEmploymentAction::class);
        $this->bulk = $container->get(PayrollEmploymentWorkplaceBulkFill::class);
        $this->repository = $container->get(PayrollEmploymentRepository::class);
        $this->validator = $container->get(PayrollEmploymentValidator::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $employee = $pdo->prepare(
            "INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, 'Syntetický Pracovník', 'employee', 'hpp', 0, 0, 0, NULL, 0, 1)",
        );
        $employee->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
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

    public function testFillsMissingWorkplaceThroughTermsCorrection(): void
    {
        $employmentId = $this->createEmployment('BULK-1', true);

        $preview = $this->bulk->preview($this->supplierId, '2026-06-01', null);
        self::assertSame(1, $preview['summary']['missing']);
        self::assertSame([$employmentId], $preview['missing_employment_ids']);

        $result = $this->bulk->apply(
            $this->supplierId,
            '2026-06-01',
            self::PRAGUE,
            'cz',
            $preview['missing_employment_ids'],
            $this->userId,
            null,
            null,
        );
        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts']);

        $term = $this->term($employmentId);
        self::assertSame(self::PRAGUE, $term['jmhz_workplace_municipality_code']);
        self::assertSame('CZ', $term['jmhz_workplace_country_code']);
        self::assertSame($result['municipality_name'], $term['work_place']);
        // Provenienci doplní validátor podmínek, ne hromadná akce.
        self::assertNotNull($term['jmhz_external_codebook_overlay_key']);

        $after = $this->bulk->preview($this->supplierId, '2026-06-01', null);
        self::assertSame(1, $after['summary']['verified']);
        self::assertSame(self::PRAGUE, $after['suggestions'][0]['municipality_code']);
        self::assertSame(1, $after['suggestions'][0]['employments']);

        // Druhé spuštění už nic nemění.
        $again = $this->bulk->apply($this->supplierId, '2026-06-01', self::PRAGUE, 'CZ', [$employmentId], $this->userId, null, null);
        self::assertSame(['applied' => 0, 'skipped' => 1, 'failed' => 0], $again['counts']);
    }

    /**
     * Doplnění mění jen pracoviště. Uložený druh činnosti mimo připnutý
     * číselník (převzatá data) ho dřív shodil jako „failed".
     */
    public function testUnchangedLegacyFieldsDoNotBlockTheFill(): void
    {
        $employmentId = $this->createEmployment('BULK-LEGACY', true);
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_terms SET activity_code = 'ZZ' WHERE supplier_id = ? AND employment_id = ?",
        )->execute([$this->supplierId, $employmentId]);

        $result = $this->bulk->apply($this->supplierId, '2026-06-01', self::PRAGUE, 'CZ', [$employmentId], $this->userId, null, null);

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result['failed'], JSON_UNESCAPED_UNICODE));
        self::assertSame(self::PRAGUE, $this->term($employmentId)['jmhz_workplace_municipality_code']);
        self::assertSame('ZZ', $this->term($employmentId)['activity_code']);
    }

    public function testFilledButUnverifiedWorkplaceIsNeverOverwritten(): void
    {
        $employmentId = $this->createEmployment('BULK-2', true);
        // Kód bez shodného názvu obce — číselník ho neověří.
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_terms
                SET jmhz_workplace_municipality_code = ?, jmhz_workplace_country_code = 'CZ'
              WHERE supplier_id = ? AND employment_id = ?",
        )->execute([self::PRAGUE, $this->supplierId, $employmentId]);

        $preview = $this->bulk->preview($this->supplierId, '2026-06-01', null);
        self::assertSame('invalid', $preview['items'][0]['state']);
        self::assertSame([], $preview['missing_employment_ids']);
        // Nepřepíše se, ale náhled ho jmenuje i s vyplněným textem.
        self::assertSame([[
            'employment_id' => $employmentId,
            'employee_id' => $this->employeeId,
            'full_name' => 'Syntetický Pracovník',
            'employment_code' => 'BULK-2',
            'work_place' => 'Praha',
            'municipality_code' => self::PRAGUE,
        ]], $preview['unverified']);

        $result = $this->bulk->apply($this->supplierId, '2026-06-01', self::PRAGUE, 'CZ', [$employmentId], $this->userId, null, null);
        self::assertSame(0, $result['counts']['applied']);
        self::assertSame('workplace_not_verified', $result['skipped'][0]['reason']);
        self::assertSame([$employmentId], array_column($result['unverified'], 'employment_id'));
        self::assertSame('Praha', $this->term($employmentId)['work_place']);
    }

    /**
     * Ukončený vztah bez hlášení JMHZ za poslední měsíc se doplní stejně jako
     * běžící; datum skutečného konce zůstane.
     */
    public function testEndedEmploymentGetsWorkplaceWhileNoJmhzReport(): void
    {
        $employmentId = $this->createEmployment('BULK-ENDED', true);
        $this->endEmployment($employmentId, '2026-06-30');

        $preview = $this->bulk->preview($this->supplierId, '2026-06-01', null);
        self::assertSame([$employmentId], $preview['missing_employment_ids'], json_encode($preview['items'], JSON_UNESCAPED_UNICODE));

        $result = $this->bulk->apply($this->supplierId, '2026-06-01', self::PRAGUE, 'CZ', [$employmentId], $this->userId, null, null);

        self::assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $result['counts'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(self::PRAGUE, $this->term($employmentId)['jmhz_workplace_municipality_code']);
        $state = $this->db->pdo()->prepare('SELECT status, end_date FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $state->execute([$this->supplierId, $employmentId]);
        self::assertSame(['status' => 'ended', 'end_date' => '2026-06-30'], $state->fetch(\PDO::FETCH_ASSOC));
    }

    /**
     * Zmrazené hlášení JMHZ za měsíc ukončeného vztahu opravu zastaví —
     * v náhledu i na kartě — a věta jmenuje hlášení a další krok.
     */
    public function testEndedEmploymentWithFrozenJmhzReportIsRefusedWithNextStep(): void
    {
        $employmentId = $this->createEmployment('BULK-REPORTED', true);
        $this->endEmployment($employmentId, '2026-06-30');
        $this->frozenJmhz($employmentId, '2026-06-01');

        $preview = $this->bulk->preview($this->supplierId, '2026-06-01', null);
        self::assertSame('excluded', $preview['items'][0]['state']);
        self::assertSame('employment_reported', $preview['items'][0]['reason']);

        try {
            $this->correctOnCard($employmentId, ['cz_isco_code' => '25120']);
            self::fail('Oprava ukončeného vztahu s podaným hlášením musí být odmítnutá.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('hlášení JMHZ za 6/2026', $exception->getMessage());
            self::assertStringContainsString('opravné hlášení', $exception->getMessage());
        }
    }

    /** Mzdu ani úvazek ukončeného vztahu oprava údajů pro JMHZ neotevře. */
    public function testEndedEmploymentRefusesNonJmhzChange(): void
    {
        $employmentId = $this->createEmployment('BULK-WAGE', true);
        $this->endEmployment($employmentId, '2026-06-30');

        try {
            $this->correctOnCard($employmentId, ['weekly_hours' => '20']);
            self::fail('Úvazek ukončeného vztahu se opravit nesmí.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('weekly_hours', $exception->getMessage());
        }

        $this->correctOnCard($employmentId, ['cz_isco_code' => '25120']);
        $stmt = $this->db->pdo()->prepare(
            'SELECT cz_isco_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        self::assertSame('25120', $stmt->fetchColumn());
    }

    /** @param array<string,mixed> $changes */
    private function correctOnCard(int $employmentId, array $changes): void
    {
        $current = $this->repository->currentTerms($this->supplierId, $employmentId);
        self::assertIsArray($current);
        $body = [
            ...PayrollEmploymentTermsBody::fromCurrent($current, 'Syntetická oprava'),
            'effective_from' => (string) $current['effective_from'],
            ...$changes,
        ];
        $version = $this->db->pdo()->prepare('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $version->execute([$this->supplierId, $employmentId]);
        $this->repository->correctTerms(
            $this->supplierId,
            $employmentId,
            $this->validator->terms($body, null, null, 'employment', $current),
            (int) $version->fetchColumn(),
            $this->userId,
            null,
            null,
        );
    }

    private function endEmployment(int $employmentId, string $endOn): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employments SET status = 'ended', end_date = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?",
        )->execute([$endOn, $this->supplierId, $employmentId]);
    }

    /** Schválený běh za měsíc a z něj ostrá zmrazená příprava JMHZ. */
    private function frozenJmhz(int $employmentId, string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no, row_version)
             VALUES (?, ?, ?, 'approved', 1, 1)",
        )->execute([$this->supplierId, $periodStart, (new \DateTimeImmutable($periodStart))->modify('+1 month +14 days')->format('Y-m-d')]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                 (supplier_id, run_id, revision_no, status, schema_version,
                  ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test', ?, '{}', ?, ?)",
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), random_bytes(32)]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_employments
                 (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, '{}', ?)",
        )->execute([$this->supplierId, $revisionId, $this->employeeId, $employmentId, str_repeat('c', 64)]);
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

    public function testMunicipalityOutsideTheCodebookIsRefused(): void
    {
        $employmentId = $this->createEmployment('BULK-3', true);

        $this->expectException(\InvalidArgumentException::class);
        $this->bulk->apply($this->supplierId, '2026-06-01', '999999', 'CZ', [$employmentId], $this->userId, null, null);
    }

    private function createEmployment(string $code, bool $primary): int
    {
        $response = $this->action->create(
            (new ServerRequestFactory())
                ->createServerRequest('POST', "/api/payroll/people/{$this->employeeId}/employments")
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody([
                    'code' => $code,
                    'relation_type' => 'employment',
                    'monthly_gross_minor' => 4_000_000,
                    'terms' => [
                        'office_id' => $this->officeId,
                        'effective_from' => '2026-06-01',
                        'contract_signed_on' => '2026-05-20',
                        'planned_start_on' => '2026-06-01',
                        'actual_start_on' => null,
                        'fixed_term_end_on' => '2026-12-31',
                        'weekly_hours' => '40',
                        'workload_basis_points' => 10000,
                        'work_place' => 'Praha',
                        'regular_workplace' => 'Praha',
                        'jmhz_workplace_municipality_code' => null,
                        'jmhz_workplace_country_code' => null,
                        'jmhz_apz_contribution_status' => 'unverified',
                        'jmhz_apz_instrument_code' => null,
                        'jmhz_functional_benefits_status' => 'unverified',
                        'jmhz_temporary_assignment_status' => 'unverified',
                        'cz_isco_code' => '43111',
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'foreign_legislation_country_code' => null,
                        'a1_certificate_until' => null,
                        'risky_work' => false,
                        'tax_declaration_signed' => true,
                        'is_primary' => $primary,
                        'change_reason' => 'Syntetické podmínky',
                    ],
                ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        self::assertSame(201, $response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return (int) $decoded['employment']['id'];
    }

    /** @return array<string,mixed> */
    private function term(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT work_place, jmhz_workplace_municipality_code, jmhz_workplace_country_code,
                    jmhz_external_codebook_overlay_key, activity_code
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
}
