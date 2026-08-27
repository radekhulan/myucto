<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAnnualSettlementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollAnnualSettlementActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAnnualSettlementAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('Aplikační kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $action = $container->get(PayrollAnnualSettlementAction::class);
        if (!$db instanceof Connection || !$action instanceof PayrollAnnualSettlementAction) {
            throw new \RuntimeException('Roční zúčtování není v kontejneru dostupné.');
        }
        $this->db = $db;
        $this->action = $action;

        $pdo = $this->db->pdo();
        $supplierQuery = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $userQuery = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($supplierQuery === false || $userQuery === false) {
            throw new \RuntimeException('Výchozí syntetická data nelze načíst.');
        }
        $sourceSupplierId = (int) ($supplierQuery->fetchColumn() ?: 0);
        $this->userId = (int) ($userQuery->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
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

    public function testRejectsCaregiverListBeyondDatabasePositionLimit(): void
    {
        $caregiver = [
            'given_name' => 'Jana',
            'family_name' => 'Syntetická',
            'birth_date' => '1990-01-01',
            'months_mask' => 'ANNNNNNNNNNN',
        ];
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'PUT',
                '/api/payroll/annual-settlement/2026/employees/1/request',
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(
                1,
                'Syntetická mzdová role',
                'staff',
                true,
                ['payroll.documents' => AccessLevel::WRITE->value],
            ))
            ->withParsedBody([
                'request_status' => 'unknown',
                'prior_employers' => 'unknown',
                'filing_obligation' => 'unknown',
                'annual_claims' => 'unknown',
                'other_household_caregiver_status' => 'present',
                'other_household_caregivers' => array_fill(0, 101, $caregiver),
            ]);

        $response = $this->action->saveRequest(
            $request,
            new Response(),
            ['year' => '2026', 'employeeId' => '1'],
        );

        self::assertSame(422, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $error = $body['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('validation_failed', $error['code'] ?? null);
    }
}
