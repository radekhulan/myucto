<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #113 — úprava mzdového vstupu: chybějící klíč drží uloženou hodnotu,
 * výslovný null maže.
 */
#[Group('integration')]
final class PayrollInputPartialUpdateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;
    private int $componentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->components = $container->get(PayrollComponentsAction::class);
        $this->inputs = $container->get(PayrollInputsAction::class);
        if (!$this->db->hasTable('payroll_inputs')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }
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
             VALUES (?, "Syntetická osoba", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $this->employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection)
             VALUES (?, ?, "SYN-PART-IN", "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $this->employeeId]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $this->componentId = $this->createComponent();
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

    public function testPartialUpdateKeepsOmittedFieldsAndNullClears(): void
    {
        $created = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs', [
                'employee_id' => $this->employeeId,
                'employment_id' => $this->employmentId,
                'component_id' => $this->componentId,
                'period' => '2026-06',
                'source_period' => '2026-05',
                'amount_minor' => 10_000,
                'quantity_milliunits' => 2_500,
                'source_kind' => 'manual',
                'external_id' => 'syn-partial-1',
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $input = $this->json($created)['input'];
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');

        $updated = $this->update($id, [
            'row_version' => $input['row_version'],
            'amount_minor' => 15_000,
        ]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        $row = $this->json($updated)['input'];
        self::assertSame(15_000, $row['amount_minor']);
        self::assertSame(2_500, $row['quantity_milliunits']);
        self::assertSame('2026-05-01', $row['source_period_start']);
        self::assertSame('2026-06-01', $row['period_start']);
        self::assertSame('syn-partial-1', $row['external_id']);
        self::assertSame($this->componentId, $row['component_id']);

        $cleared = $this->update($id, [
            'row_version' => $row['row_version'],
            'quantity_milliunits' => null,
            'external_id' => '',
        ]);
        self::assertSame(200, $cleared->getStatusCode(), (string) $cleared->getBody());
        $row = $this->json($cleared)['input'];
        self::assertNull($row['quantity_milliunits']);
        self::assertNull($row['external_id']);
        self::assertSame(15_000, $row['amount_minor']);
        self::assertSame('2026-05-01', $row['source_period_start']);
    }

    public function testUpdateOfMissingInputIsNotFound(): void
    {
        $response = $this->update(999_999_999, ['row_version' => 1, 'amount_minor' => 1]);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @param array<string,mixed> $body */
    private function update(int $id, array $body): ResponseInterface
    {
        return $this->inputs->update(
            $this->request('PUT', "/api/payroll/inputs/{$id}", $body),
            new Response(),
            ['id' => (string) $id],
        );
    }

    private function createComponent(): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components', [
                'code' => 'SYN_PARTIAL',
                'name' => 'Syntetická odměna',
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'included',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::int($this->json($response)['component']['id'] ?? null, 'component.id');
    }

    /** @param array<string,mixed> $body */
    private function request(
        string $method,
        string $uri,
        array $body,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
