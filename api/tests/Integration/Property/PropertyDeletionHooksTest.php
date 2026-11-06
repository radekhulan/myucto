<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyDeletionHooksTest extends TestCase
{
    use IsolatedSupplierTrait;
    private string $ownedSchema;
    private PDO $server;
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        self::assertTrue(CloneSandbox::isLeased($this->ownedSchema));
        CloneSandbox::dropOrRelease($this->server, $this->ownedSchema, [$this->ownedSchema]);
    }


    public function testSupplierProfileHistoryRequiresFriendlyDependencyRefusal(): void
    {
        $db = $this->connections[0];
        $pdo = $db->pdo();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$supplier]);
        (new PropertyProfileRepository($db))->save($supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic confirmed profile', 'title_ref' => 'Synthetic evidence', 'idempotency_key' => 'profile'], $actor);
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/settings/suppliers/' . $supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin']);
        $response = $container->get(\MyInvoice\Action\Settings\SettingsAction::class)->deleteSupplierById($request, new \Slim\Psr7\Response(), ['id' => $supplier]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('has_dependencies', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertCount(1, (new PropertyProfileRepository($db))->history($supplier));
    }

    public function testClientHistoryAddedAfterSnapshotRequiresFriendlyRefusal(): void
    {
        [$db, $writer] = $this->connections;
        $pdo = $db->pdo();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$supplier, 'Synthetic registry party', 'Synthetic street', 'Synthetic city', '10000', $country, 'registry-party@example.test', $currency]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$supplier]);
        (new PropertyProfileRepository($writer))->save($supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic declaration', 'idempotency_key' => 'profile'], $actor);
        $pdo->beginTransaction();
        self::assertFalse((new \MyInvoice\Service\Property\PropertyHistoryUseGuard($db))->clientHasHistory($supplier, $client));
        $repo = new PropertyRegistryRepository($writer);
        $request = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'status' => 'confirmed',
            'reason' => 'Synthetic legal history', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'house', 'code' => 'HOUSE'];
        $house = $repo->create($supplier, 'house', $request + ['state' => ['name' => 'Synthetic house', 'active' => true,
            'address' => ['street' => 'Synthetic street', 'city' => 'Synthetic city', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic declaration', 'share_scope' => 'SYNTHETIC', 'registry_complete' => false]], $actor);
        $request['idempotency_key'] = 'unit';
        $request['code'] = 'UNIT';
        $unit = $repo->create($supplier, 'unit', $request + ['parent_id' => $house['id'], 'state' => ['unit_no' => '1',
            'kind' => 'apartment', 'active' => true, 'attributes' => ['share' => ['num' => '1', 'den' => '1']], 'ownerships' => [],
            'roles' => [['client_id' => $client, 'role' => 'delivery', 'scopes' => [], 'title_ref' => 'Synthetic delivery title']],
            'identifiers' => [], 'services' => []]], $actor);
        self::assertFalse((new \MyInvoice\Service\Property\PropertyHistoryUseGuard($db))->clientHasHistory($supplier, $client));
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $db);
        $http = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/clients/' . $client)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $actor, 'role' => 'admin']);
        $response = ($container->get(\MyInvoice\Action\Client\DeleteClientAction::class))($http, new \Slim\Psr7\Response(), ['id' => $client]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('has_dependencies', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertTrue((new \MyInvoice\Service\Property\PropertyHistoryUseGuard($db))->clientHasHistory($supplier, $client));
        self::assertNotNull((new PropertyReadRepository($db))->find($supplier, $unit['id'], '2026-06-01T12:00:00+02:00')['revision']);
    }
}
