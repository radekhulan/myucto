<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #113 — podmínky pracovního vztahu: klíč, který v těle chybí, drží
 * uloženou hodnotu platné verze; výslovný null ji maže.
 */
#[Group('integration')]
final class PayrollEmploymentTermsPartialUpdateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentAction $action;
    private int $supplierId;
    private int $employeeId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Migrace 1195 neproběhla.');
        }
        $this->action = $container->get(PayrollEmploymentAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický Pracovník", "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'MAIN', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
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

    public function testCorrectionWithPartialBodyKeepsStoredTermsAndNullClears(): void
    {
        $employment = $this->activeEmployment();

        $corrected = $this->call('correctTerms', $employment, [
            'row_version' => $employment['row_version'],
            'weekly_hours' => '30',
        ]);
        $terms = $corrected['terms'][0];
        self::assertSame('30.00', $terms['weekly_hours']);
        self::assertSame(7500, $terms['workload_basis_points']);
        self::assertSame('Praha', $terms['work_place']);
        self::assertSame('2026-12-31', $terms['fixed_term_end_on']);
        self::assertSame('2025-12-20', $terms['contract_signed_on']);
        self::assertSame($this->officeId, $terms['office_id']);
        self::assertSame('1', $terms['activity_code']);
        self::assertCount(1, $corrected['terms']);

        $cleared = $this->call('correctTerms', $corrected, [
            'row_version' => $corrected['row_version'],
            'fixed_term_end_on' => null,
        ]);
        self::assertNull($cleared['terms'][0]['fixed_term_end_on']);
        self::assertSame(7500, $cleared['terms'][0]['workload_basis_points']);
    }

    public function testNewVersionWithPartialBodyInheritsCurrentTerms(): void
    {
        $employment = $this->activeEmployment();

        $changed = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'],
            'effective_from' => '2026-03-01',
            'weekly_hours' => '20',
        ]);
        self::assertCount(2, $changed['terms']);
        $terms = $changed['terms'][0];
        self::assertSame('2026-03-01', $terms['effective_from']);
        self::assertSame('20.00', $terms['weekly_hours']);
        self::assertSame(7500, $terms['workload_basis_points']);
        self::assertSame('Praha', $terms['regular_workplace']);
        self::assertSame('2026-12-31', $terms['fixed_term_end_on']);
        self::assertSame($this->officeId, $terms['office_id']);
        // Důvod změny patří k zápisu, ze staré verze se nepřebírá.
        self::assertNull($terms['change_reason']);
    }

    /** @return array<string,mixed> */
    private function activeEmployment(): array
    {
        $response = $this->action->create(
            $this->request('POST', "/api/payroll/people/{$this->employeeId}/employments", [
                'code' => 'SYN-PART-1',
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4000000,
                'terms' => [
                    'office_id' => $this->officeId,
                    'effective_from' => '2026-01-01',
                    'contract_signed_on' => '2025-12-20',
                    'planned_start_on' => '2026-01-01',
                    'actual_start_on' => null,
                    'fixed_term_end_on' => '2026-12-31',
                    'weekly_hours' => '40',
                    'workload_basis_points' => 7500,
                    'work_place' => 'Praha',
                    'regular_workplace' => 'Praha',
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => '43111',
                    'activity_code' => '1',
                    'jmhz_relationship_detail_code' => '1',
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'tax_declaration_signed' => true,
                    'is_primary' => true,
                    'change_reason' => 'Syntetické podmínky',
                ],
            ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $employment = $this->json($response)['employment'];
        $employment = $this->transition($employment, 'preregistered', '2026-01-01');

        return $this->transition($employment, 'active', '2026-01-02');
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function transition(array $employment, string $target, string $effectiveOn): array
    {
        $response = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/employments/{$employment['id']}/transitions/{$target}",
                ['row_version' => $employment['row_version'], 'effective_on' => $effectiveOn],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'target' => $target],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    /**
     * @param array<string,mixed> $employment
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function call(string $method, array $employment, array $body): array
    {
        $response = $this->action->{$method}(
            $this->request('PUT', "/api/payroll/employments/{$employment['id']}/terms", $body),
            new Response(),
            ['id' => (string) $employment['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    /** @param array<string,mixed> $body */
    private function request(
        string $method,
        string $path,
        array $body,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
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
