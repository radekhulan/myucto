<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[Group('integration')]
final class MeAccountingFrameworkTest extends TestCase
{
    private PDO $db;
    private Config $config;
    private string $database;
    private string $rootDir;
    private bool $databaseCreated = false;
    private ?string $worker = null;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $config = $this->config = Config::load($this->rootDir);
        $host = strtolower((string) $config->get('db.host', '127.0.0.1'));
        $source = (string) $config->get('db.name');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D', $source);
        self::assertTrue(in_array($host, ['localhost', '127.0.0.1', '::1'], true) || getenv('MYINVOICE_ALLOW_REMOTE_TEST_DB') === '1');
        $this->database = 'myucto_me_framework_' . bin2hex(random_bytes(6)) . '_test';
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $config->get('db.port', 3306)),
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->db->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->databaseCreated = true;
        $this->db->exec("USE `{$this->database}`");
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['supplier', 'supplier_accounting_frameworks', 'roles', 'users', 'user_suppliers', 'role_permissions', 'webauthn_credentials'] as $table) {
            $ddl = $this->db->query("SHOW CREATE TABLE `{$source}`.`{$table}`")->fetch(PDO::FETCH_NUM)[1];
            $this->db->exec($ddl);
        }
        $this->db->exec("INSERT INTO roles (id, name, role_type) VALUES (1, 'Accounting only', 'staff')");
        $this->db->exec("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (1, 'accounting', 1)");
        $this->db->prepare('INSERT INTO users (id, email, password_hash, name, role_id, mfa_offer_dismissed_at) VALUES (1, ?, ?, ?, ?, NOW())')
            ->execute(['fixture@example.invalid', str_repeat('x', 60), 'Synthetic user', 1]);
        $supplier = $this->db->prepare("INSERT INTO supplier (id, company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, tax_accounting_decree) VALUES (?, ?, 'Test 1', 'Test', '10000', 1, ?, 1, 1, ?)");
        foreach ([11 => '500', 22 => '504', 33 => '500'] as $id => $manual) {
            $supplier->execute([$id, 'Synthetic ' . $id, 'fixture' . $id . '@example.invalid', $manual]);
        }
        $this->db->exec('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (1, 11), (1, 22)');
        $this->db->exec("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (11, '1900-01-01', 'cz_500'), (22, '1900-01-01', 'cz_500'), (33, '1900-01-01', 'cz_504')");
        $change = $this->db->prepare('INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (11, ?, ?)');
        $change->execute([date('Y-m-d', strtotime('-1 day')), 'cz_504']);
        $change->execute([date('Y-m-d', strtotime('+1 day')), 'cz_500']);
    }

    protected function tearDown(): void
    {
        if ($this->worker !== null && is_file($this->worker)) unlink($this->worker);
        if ($this->databaseCreated && isset($this->db) && preg_match('/^myucto_me_framework_[0-9a-f]{12}_test$/D', $this->database) === 1) {
            $this->db->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testBatchUsesHistoricalBoundariesFallbackAndOnlyRequestedTenants(): void
    {
        $config = new Config(['db' => [
            'host' => $this->config->get('db.host'), 'port' => $this->config->get('db.port', 3306),
            'name' => $this->database, 'user' => $this->config->get('db.user'), 'pass' => $this->config->get('db.pass', ''),
        ]]);
        $db = new \MyInvoice\Infrastructure\Database\Connection($config);
        $service = new \MyInvoice\Service\Accounting\AccountingFrameworkService($db, new \MyInvoice\Repository\AccountingFrameworkRepository($db));
        $this->db->exec('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = 22');
        self::assertSame([11 => 'cz_500', 22 => 'cz_500'], $service->forDateForSuppliers([11, 22], date('Y-m-d', strtotime('-2 days'))));
        self::assertSame([11 => 'cz_504', 22 => 'cz_500'], $service->forDateForSuppliers([11, 22], date('Y-m-d', strtotime('-1 day'))));
        self::assertSame([11 => 'cz_500', 22 => 'cz_500'], $service->forDateForSuppliers([11, 22], date('Y-m-d', strtotime('+1 day'))));
        self::assertSame([33 => 'cz_504'], $service->forDateForSuppliers([33], date('Y-m-d')));
        self::assertSame([], $service->forDateForSuppliers([], date('Y-m-d')));
        $db->close();
    }

    public function testAccountingOnlyUserReceivesEffectiveFrameworkOutsideSupplierCache(): void
    {
        $this->worker = tempnam(sys_get_temp_dir(), 'me-framework-');
        self::assertIsString($this->worker);
        file_put_contents($this->worker, <<<'PHP'
<?php
require $argv[1] . '/api/vendor/autoload.php';
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
try {
$container = Bootstrap::buildContainer();
$config = $container->get(Config::class);
$db = $container->get(Connection::class);
$cacheConfig = new Config(['redis' => ['enabled' => false], 'cache' => ['entities_enabled' => true]]);
$cache = new EntityCache(new RedisFactory($cacheConfig), $cacheConfig);
$action = new MeAction($db, $config, $container->get(UserSupplierRepository::class), $container->get(PermissionResolver::class), $container->get(LicenseService::class), $container->get(PasskeyCredentialRepository::class), $container->get(MfaPolicyService::class), $container->get(MfaOfferService::class), $container->get(SessionLockPolicy::class), $container->get(ClockInterface::class), $cache);
$user = $db->pdo()->query('SELECT * FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/me')->withHeader('X-Supplier-Id', '11')
    ->withAttribute(AuthMiddleware::ATTR_USER, $user)
    ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
    ->withAttribute(LicenseMiddleware::ATTR_STATE, new LicenseState(LicenseState::ACTIVE, 'synthetic', 'test', null, 1, 1, 3, null, null, null, null, null, true));
$handler = new class($action) implements RequestHandlerInterface {
    public function __construct(private MeAction $action) {}
    public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->action)($request, new Response()); }
};
$middleware = $container->get(PermissionMiddleware::class);
$first = $middleware->process($request, $handler);
$firstBody = json_decode((string) $first->getBody(), true, flags: JSON_THROW_ON_ERROR);
$ok = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(); }
};
$settingsStatus = $middleware->process($request->withUri($request->getUri()->withPath('/api/settings/supplier')), $ok)->getStatusCode();
$accountingStatus = $middleware->process($request->withUri($request->getUri()->withPath('/api/accounting/accounts')), $ok)->getStatusCode();
$raw = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->get('db.host'), $config->get('db.port', 3306), $config->get('db.name')), $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw->exec("UPDATE supplier SET company_name = 'Changed outside cache' WHERE id = 11");
$raw->exec("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = 11 AND effective_from <> '1900-01-01'");
$second = $middleware->process($request, $handler);
echo json_encode(['me_status' => $first->getStatusCode(), 'settings_status' => $settingsStatus, 'accounting_status' => $accountingStatus, 'first' => $firstBody, 'second' => json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
PHP);
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $environment['MYINVOICE_REDIS_ENABLED'] = '0';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', $this->worker, $this->rootDir], $this->rootDir, $environment, null, 60);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(200, $result['me_status']);
        self::assertSame(403, $result['settings_status']);
        self::assertSame(200, $result['accounting_status']);
        $first = array_column($result['first']['suppliers'], null, 'id');
        $second = array_column($result['second']['suppliers'], null, 'id');
        self::assertSame([11, 22], array_keys($first));
        self::assertSame('cz_504', $first[11]['accounting_framework']);
        self::assertSame('cz_500', $first[22]['accounting_framework']);
        self::assertSame('Synthetic 11', $second[11]['company_name'], 'Seznam musí skutečně pocházet z cache.');
        self::assertSame('cz_500', $second[11]['accounting_framework'], 'Účinný rámec nesmí zůstat v cachovaném seznamu.');
    }
}
