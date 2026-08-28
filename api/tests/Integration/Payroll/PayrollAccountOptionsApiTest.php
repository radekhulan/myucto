<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAccountOptionsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollAccountOptionsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAccountOptionsAction $action;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAccountOptionsAction::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('chart_of_accounts')) {
            $this->markTestSkipped('Chybí účtová osnova.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET payroll_enabled = 1, accounting_mode = 'tax_evidence'
              WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $seeder->seedForSupplier($this->otherSupplierId);

        $this->renameAccount($this->supplierId, '521', 'Mzdové náklady tenanta A');
        $this->renameAccount($this->otherSupplierId, '521', 'Mzdové náklady tenanta B');
        $pdo->prepare(
            'UPDATE chart_of_accounts
                SET is_active = 0
              WHERE supplier_id = ? AND account_code = ?'
        )->execute([$this->supplierId, '523']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPayrollOnlyReaderGetsTenantScopedOptionsInTaxEvidenceMode(): void
    {
        $response = ($this->action)(
            $this->request($this->supplierId, $this->payrollOnlyRole()),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $accounts = $this->json($response)['accounts'];
        self::assertIsArray($accounts);
        self::assertNotEmpty($accounts);
        $accountTypes = array_values(array_unique(array_column($accounts, 'account_type')));
        sort($accountTypes);
        // Aktiva přibyla s pohledávkou za zaměstnancem (335) — vzniká při záporné
        // čisté mzdě, typicky když v měsíci bez příjmu doplácí pojistné do minima.
        self::assertSame(['asset', 'expense', 'liability'], $accountTypes);

        $byCode = array_column($accounts, null, 'account_code');
        self::assertSame('Mzdové náklady tenanta A', $byCode['521']['name']);
        self::assertFalse($byCode['523']['is_active']);
        self::assertArrayNotHasKey('supplier_id', $byCode['521']);
        self::assertArrayNotHasKey('created_at', $byCode['521']);

        $otherResponse = ($this->action)(
            $this->request($this->otherSupplierId, $this->payrollOnlyRole()),
            new Response(),
        );
        $otherByCode = array_column($this->json($otherResponse)['accounts'], null, 'account_code');
        self::assertSame('Mzdové náklady tenanta B', $otherByCode['521']['name']);
        self::assertStringNotContainsString(
            'tenanta B',
            json_encode($accounts, JSON_THROW_ON_ERROR),
        );
    }

    public function testAccountingPermissionAloneAndBearerTokenAreRejected(): void
    {
        $accountingOnly = new EffectiveRole(
            3,
            'Účetní',
            'staff',
            true,
            ['accounting' => AccessLevel::WRITE->value],
        );
        $forbidden = ($this->action)(
            $this->request($this->supplierId, $accountingOnly),
            new Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $bearer = ($this->action)(
            $this->request($this->supplierId, $this->payrollOnlyRole(), 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    private function payrollOnlyRole(): EffectiveRole
    {
        return new EffectiveRole(
            2,
            'Mzdová účetní',
            'staff',
            true,
            ['payroll.settings' => AccessLevel::READ->value],
        );
    }

    private function request(
        int $supplierId,
        EffectiveRole $role,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/settings/account-options')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod)
            ->withAttribute('auth.effective_role', $role);
    }

    private function renameAccount(int $supplierId, string $code, string $name): void
    {
        $this->db->pdo()->prepare(
            'UPDATE chart_of_accounts
                SET name = ?
              WHERE supplier_id = ? AND account_code = ?'
        )->execute([$name, $supplierId, $code]);
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
