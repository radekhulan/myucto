<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\People\PayrollPeopleGlobalSearch;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Hledání zaměstnanců přes Alt+Q jde napříč firmami, a proto hlídá hranici
 * tenantů: osoba se vrátí jen z firmy, kde má uživatel členství, právo
 * `payroll` ke čtení a zapnuté mzdy. Firma, která neprojde, nesmí ve výsledku
 * zanechat ani stopu.
 */
#[Group('integration')]
final class PayrollPeopleGlobalSearchTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $userId;
    private string $token;
    /** @var array<string,int> */
    private array $suppliers = [];
    /** @var array<string,int> */
    private array $employees = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if (!$this->container->get(PayrollModuleAccess::class)->isLicensed()) {
            $this->markTestSkipped('Licence bez mzdového doplňku.');
        }
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($source <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $this->pdo->beginTransaction();

        $payrollRole = $this->role(['payroll']);
        $otherRole = $this->role(['invoices']);
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
                             VALUES (?, 'disabled-test-password', 'Syntetický hledající', ?, 'cs', 1)")
            ->execute(['people-search-' . bin2hex(random_bytes(6)) . '@example.invalid', $payrollRole]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->token = 'Qx' . bin2hex(random_bytes(4));
        foreach (['allowed' => true, 'no_permission' => true, 'not_member' => true, 'payroll_off' => false] as $key => $enabled) {
            $id = $this->createIsolatedSupplier($this->pdo, $source);
            $this->pdo->prepare('UPDATE supplier SET payroll_enabled = ? WHERE id = ?')->execute([(int) $enabled, $id]);
            $this->suppliers[$key] = $id;
            $this->pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
                                 VALUES (?, ?, "employee", 1)')
                ->execute([$id, 'Petr ' . $this->token . ' ' . $key]);
            $this->employees[$key] = (int) $this->pdo->lastInsertId();
        }
        $assign = $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)');
        $assign->execute([$this->userId, $this->suppliers['allowed'], null]);
        $assign->execute([$this->userId, $this->suppliers['no_permission'], $otherRole]);
        $assign->execute([$this->userId, $this->suppliers['payroll_off'], null]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testOnlyCompaniesWithPayrollReadAccessAreSearched(): void
    {
        $hits = $this->search($this->request('session'), $this->token);

        self::assertSame([$this->employees['allowed']], array_column($hits, 'id'));
        self::assertSame($this->suppliers['allowed'], $hits[0]['supplier_id']);
        self::assertNotSame('', $hits[0]['supplier_name'], 'Výsledek z jiné firmy musí říct, ze které je.');
    }

    public function testEveryWordMustMatchInAnyOrder(): void
    {
        self::assertCount(1, $this->search($this->request('session'), $this->token . ' petr'));
        self::assertSame([], $this->search($this->request('session'), $this->token . ' Pavel'));
    }

    public function testApiTokenGetsNoEmployees(): void
    {
        self::assertSame([], $this->search($this->request('bearer'), $this->token));
    }

    /** @return list<array<string,mixed>> */
    private function search(ServerRequestInterface $request, string $query): array
    {
        return $this->container->get(PayrollPeopleGlobalSearch::class)
            ->search($request, $this->suppliers['no_permission'], $query);
    }

    private function request(string $method): ServerRequestInterface
    {
        $user = $this->container->get(CrossSupplierAuthorization::class)->activeUser($this->userId);
        self::assertNotNull($user);

        return (new ServerRequestFactory())->createServerRequest('GET', '/api/search')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
    }

    /** @param list<string> $keys */
    private function role(array $keys): int
    {
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES ('Syntetická role hledání', 'staff', 1)")->execute();
        $id = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($keys as $key) {
            $insert->execute([$id, $key, AccessLevel::READ->value]);
        }

        return $id;
    }
}
