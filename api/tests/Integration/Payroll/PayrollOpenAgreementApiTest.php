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
 * Založení další DPP osobě, která má otevřenou dohodu: server novou dohodu
 * bez potvrzení nezaloží (409 s popisem otevřené dohody), na volbu použije
 * otevřenou a prodlouží jí konec, na výslovné potvrzení založí novou.
 */
#[Group('integration')]
final class PayrollOpenAgreementApiTest extends TestCase
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
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollEmploymentAction::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Brigádník Syntetický", "employee", "dpp", 0, 0, 0, NULL, 0, 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "MAIN", "Hlavní účtárna", 1)',
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

    public function testSecondDppIsRefusedUntilUserChoosesBetweenExistingAndNew(): void
    {
        $first = $this->create('2026-06-01', '2026-06-30');
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $firstId = (int) $this->json($first)['employment']['id'];

        // Nová DPP od 15. 6. vedle otevřené dohody do 30. 6.: bez volby ji server nezaloží.
        $refused = $this->create('2026-06-15', '2026-09-30');
        self::assertSame(409, $refused->getStatusCode(), (string) $refused->getBody());
        $body = $this->json($refused);
        self::assertSame('open_agreement_exists', $body['error']['code'] ?? $body['code'] ?? null, (string) $refused->getBody());
        $agreements = $body['error']['open_agreements'] ?? $body['open_agreements'] ?? [];
        self::assertCount(1, $agreements);
        self::assertSame($firstId, $agreements[0]['employment_id']);
        self::assertTrue($agreements[0]['reusable']);
        self::assertSame('2026-09-30', $agreements[0]['extend_to']);
        self::assertSame(1, $this->dppCount());

        // Použít otevřenou: nová dohoda nevznikne, otevřené se prodlouží konec.
        $reused = $this->create('2026-06-15', '2026-09-30', ['use_open_agreement' => $firstId]);
        self::assertSame(200, $reused->getStatusCode(), (string) $reused->getBody());
        $reusedBody = $this->json($reused);
        self::assertTrue($reusedBody['reused']);
        self::assertSame($firstId, (int) $reusedBody['employment']['id']);
        self::assertSame('2026-09-30', $reusedBody['extended_to']);
        self::assertSame(1, $this->dppCount());
        self::assertSame(['end_date' => '2026-09-30', 'fixed_term_end_on' => '2026-09-30'], $this->end($firstId));

        // Výslovně nová dohoda projde.
        $confirmed = $this->create('2026-10-01', null, ['confirm_new_agreement' => true]);
        self::assertSame(201, $confirmed->getStatusCode(), (string) $confirmed->getBody());
        self::assertSame(2, $this->dppCount());
    }

    public function testEndedAgreementDoesNotBlockNewDpp(): void
    {
        self::assertSame(201, $this->create('2026-03-01', '2026-03-31')->getStatusCode());
        // Konec 31. 3. je před nástupem nové dohody, otevřená tedy není.
        $second = $this->create('2026-06-01', '2026-06-30');
        self::assertSame(201, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame(2, $this->dppCount());
    }

    /** @param array<string,mixed> $extra */
    private function create(string $start, ?string $end, array $extra = []): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/payroll/people/{$this->employeeId}/employments")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody([
                'code' => '',
                'relation_type' => 'dpp',
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => $this->officeId,
                    'effective_from' => $start,
                    'contract_signed_on' => null,
                    'planned_start_on' => $start,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => $end,
                    'weekly_hours' => '40',
                    'workload_basis_points' => 10000,
                    'work_place' => null,
                    'regular_workplace' => null,
                    'jmhz_workplace_municipality_code' => null,
                    'jmhz_workplace_country_code' => null,
                    'jmhz_apz_contribution_status' => 'no',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'no',
                    'jmhz_temporary_assignment_status' => 'no',
                    'cz_isco_code' => null,
                    'activity_code' => null,
                    'jmhz_relationship_detail_code' => null,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => false,
                    'change_reason' => 'Syntetická dohoda',
                ],
            ] + $extra);

        return $this->action->create($request, new Response(), ['id' => (string) $this->employeeId]);
    }

    private function dppCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? AND relation_type = "dpp"',
        );
        $stmt->execute([$this->supplierId, $this->employeeId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{end_date:?string,fixed_term_end_on:?string} */
    private function end(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.end_date,
                    (SELECT terms.fixed_term_end_on FROM payroll_employment_terms terms
                      WHERE terms.supplier_id = employment.supplier_id AND terms.employment_id = employment.id
                      ORDER BY terms.effective_from DESC, terms.id DESC LIMIT 1) AS fixed_term_end_on
               FROM payroll_employments employment
              WHERE employment.supplier_id = ? AND employment.id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return ['end_date' => $row['end_date'], 'fixed_term_end_on' => $row['fixed_term_end_on']];
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
