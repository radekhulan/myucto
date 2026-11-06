<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyRegistryConcurrencyTest extends TestCase
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
        $this->ownedSchema = 'myucto_property_race_' . bin2hex(random_bytes(5)) . '_test';
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $this->ownedSchema, static function (): void {});
        $cloner->finalize($plan, $this->ownedSchema);
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
        self::assertMatchesRegularExpression('/^myucto_property_race_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        $this->server->exec('DROP DATABASE ' . DatabaseCloner::quote($this->ownedSchema));
    }

    public function testStaleCallerSnapshotCannotLoseRevisionOrMissProfileDisable(): void
    {
        [$a, $b] = $this->connections;
        $pdo = $a->pdo();
        $pdo->beginTransaction();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$supplier]);
        $profile = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'enabled' => true, 'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'profile'];
        (new PropertyProfileRepository($a))->save($supplier, $profile, $actor);
        $state = ['name' => 'Synthetic original', 'active' => true, 'address' => ['street' => 'Synthetic', 'city' => 'Synthetic', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic deed', 'share_scope' => 'SYNTHETIC', 'registry_complete' => false];
        $request = ['code' => 'HOUSE', 'expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'status' => 'confirmed', 'state' => $state,
            'reason' => 'Synthetic first version', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'house'];
        $house = (new PropertyRegistryRepository($a))->create($supplier, 'house', $request, $actor);
        $pdo->commit();
        $pdo->beginTransaction();
        self::assertSame(1, (int) $pdo->query('SELECT current_revision FROM property_objects WHERE id = ' . $house['id'])->fetchColumn());
        $change = $request;
        unset($change['code']);
        $change['expected_revision'] = 1;
        $change['state']['name'] = 'Synthetic concurrent change';
        $change['idempotency_key'] = 'concurrent';
        (new PropertyRegistryRepository($b))->revise($supplier, $house['id'], 'house', $change, $actor);
        $change['idempotency_key'] = 'stale';
        $this->refused('property_revision_conflict', fn () => (new PropertyRegistryRepository($a))->revise($supplier, $house['id'], 'house', $change, $actor));
        self::assertSame(2, (new PropertyReadRepository($a))->find($supplier, $house['id'], '2026-06-01T12:00:00Z', currentRead: true)['current_revision']);
        $pdo->rollBack();

        $pdo->beginTransaction();
        self::assertCount(1, (new PropertyReadRepository($a))->objects($supplier));
        $request['code'] = 'NEW';
        $request['idempotency_key'] = 'new';
        $new = (new PropertyRegistryRepository($b))->create($supplier, 'house', $request, $actor);
        $change['idempotency_key'] = 'new-current-edit';
        $edited = (new PropertyRegistryRepository($a))->revise($supplier, $new['id'], 'house', $change, $actor);
        self::assertSame(2, $edited['revision']);
        $pdo->commit();

        $pdo->beginTransaction();
        self::assertTrue((new PropertyProfileRepository($a))->at($supplier, '2026-08-01T12:00:00Z')['enabled']);
        $profile['expected_revision'] = 1;
        $profile['effective_from'] = '2026-07-01T00:00:00+02:00';
        $profile['enabled'] = false;
        $profile['idempotency_key'] = 'disabled';
        (new PropertyProfileRepository($b))->save($supplier, $profile, $actor);
        $request['code'] = 'BLOCKED';
        $request['idempotency_key'] = 'blocked';
        $request['effective_from'] = '2026-08-01T00:00:00+02:00';
        $this->refused('property_profile_required', fn () => (new PropertyRegistryRepository($a))->create($supplier, 'house', $request, $actor));
        self::assertCount(2, (new PropertyReadRepository($a))->objects($supplier, currentRead: true));
        $pdo->rollBack();
    }

    private function refused(string $code, callable $work): void
    {
        try { $work(); self::fail('Expected ' . $code); }
        catch (PropertyException $e) { self::assertSame($code, $e->errorCode); }
    }
}
