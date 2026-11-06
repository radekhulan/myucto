<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
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

        $result = $this->bulk->apply($this->supplierId, '2026-06-01', self::PRAGUE, 'CZ', [$employmentId], $this->userId, null, null);
        self::assertSame(0, $result['counts']['applied']);
        self::assertSame('workplace_not_verified', $result['skipped'][0]['reason']);
        self::assertSame('Praha', $this->term($employmentId)['work_place']);
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
                    jmhz_external_codebook_overlay_key
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
