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
use MyInvoice\Service\Anonymization\PropertyCloneScrubber;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyImportService;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyCloneScrubberTest extends TestCase
{
    use IsolatedSupplierTrait;
    private PDO $server;
    private Config $config;
    private array $schemas = [];
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertTrue(\MyInvoice\Tests\Support\TestDatabaseHost::isAllowed((string) ($this->config->get('db.host'))), 'Testovací DB musí být lokální nebo v db.test_allowed_hosts.');
        $this->server = new PDO('mysql:host=' . $this->config->get('db.host') . ';port=' . $this->config->get('db.port', 3306) . ';charset=utf8mb4',
            $this->config->get('db.user'), $this->config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $prefix = 'myucto_property_clone_' . bin2hex(random_bytes(5));
        $this->schemas = [$prefix . '_source_test', $prefix . '_target_test', $prefix . '_partial_test'];
        $this->schemas[0] = CloneSandbox::acquire($this->server, $source, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) { if ($db->pdo()->inTransaction()) $db->pdo()->rollBack(); $db->close(); }
        foreach (array_reverse($this->schemas) as $schema) {
            if (!CloneSandbox::isLeased($schema)) self::assertMatchesRegularExpression('/^myucto_property_clone_[a-f0-9]{10}_(source|target|partial)_test$/D', $schema);
            CloneSandbox::dropOrRelease($this->server,$schema,$this->schemas);
        }
    }

    public function testClonePreservesLegalHistoryRetryAndCasAndRejectsCorruptionAtomically(): void
    {
        $source = $this->connect($this->schemas[0]);
        [$supplier, $actor, $profile, $houseRequest, $house, $unit, $pending, $confirmation, $confirmed, $importRequest, $imported] = $this->fixture($source);
        $sourceHash = $this->fingerprint($source->pdo());
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($this->schemas[0]);
        $this->schemas[1] = CloneSandbox::acquire($this->server, $this->schemas[0]);
        $target = $this->connect($this->schemas[1]);
        $pdo = $target->pdo();
        $scrubber = new TextScrubber(new Pseudonymizer(str_repeat('synthetic-property-', 3)));
        $helper = new PropertyCloneScrubber($pdo, $scrubber);
        $revisions = $pdo->prepare('SELECT id, state_json FROM property_revisions WHERE supplier_id = ? ORDER BY id');
        $revisions->execute([$supplier]);
        $oldRows = $revisions->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertCount(5, $oldRows);
        $firstId = (int) array_key_first($oldRows);
        $corrupt = json_decode($oldRows[$firstId], true, flags: JSON_THROW_ON_ERROR);
        $corrupt['name'] = 'Corrupted legal snapshot';
        $pdo->prepare('UPDATE property_revisions SET state_json = ? WHERE id = ?')->execute([json_encode($corrupt, JSON_THROW_ON_ERROR), $firstId]);
        $before = $this->fingerprint($pdo);
        $this->fails(fn () => $helper->run(), 'canonical hash mismatch');
        self::assertSame($before, $this->fingerprint($pdo), 'Failure rolls back prior profile transformations.');
        $pdo->prepare('UPDATE property_revisions SET state_json = ? WHERE id = ?')->execute([$oldRows[$firstId], $firstId]);
        $groups = $pdo->prepare('SELECT id, title_ref FROM property_revision_groups WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $groups->execute([$supplier]);
        $group = $groups->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($group);
        $pdo->prepare('UPDATE property_revision_groups SET title_ref = ? WHERE id = ?')->execute(['Unrelated changed deed', $group['id']]);
        $before = $this->fingerprint($pdo);
        $this->fails(fn () => $helper->run(), 'materialization differs');
        self::assertSame($before, $this->fingerprint($pdo));
        $pdo->prepare('UPDATE property_revision_groups SET title_ref = ? WHERE id = ?')->execute([$group['title_ref'], $group['id']]);
        $pdo->beginTransaction();
        $this->fails(fn () => $helper->run(), 'own transaction');
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $helper->run();
        $revisions = $pdo->prepare('SELECT state_json, state_hash FROM property_revisions WHERE supplier_id = ? ORDER BY id');
        $revisions->execute([$supplier]);
        $rows = $revisions->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('registry-person@example.test', $row['state_json']);
            self::assertSame(PropertyInput::hash(json_decode($row['state_json'], true, flags: JSON_THROW_ON_ERROR)), $row['state_hash']);
        }
        $reader = new PropertyReadRepository($target);
        $detail = $reader->find($supplier, $unit['id'], '2026-06-01T12:00:00+02:00');
        self::assertCount(3, $detail['history']);
        self::assertCount(1, $detail['revision']['state']['ownerships']);
        self::assertCount(2, $detail['revision']['state']['ownerships'][0]['members']);
        self::assertSame(['num' => '1', 'den' => '1'], $detail['revision']['state']['ownerships'][0]['share']);
        self::assertSame(['num' => '9', 'den' => '100'], $detail['revision']['state']['attributes']['share']);
        self::assertSame($pending['effective_from'], $detail['history'][1]['effective_from']);
        $cloner->finalize($plan, $this->schemas[1]);
        $this->fails(fn () => $helper->run(), 'before installing triggers');
        $repo = new PropertyRegistryRepository($target);
        $anonymousHouse = $reader->find($supplier, $house['id'], '2026-01-02T00:00:00Z')['history'][0];
        $houseRequest['state'] = $anonymousHouse['state'];
        $houseRequest['reason'] = $scrubber->scrub($houseRequest['reason']);
        $houseRequest['title_ref'] = $scrubber->scrub($houseRequest['title_ref']);
        self::assertSame($house, $repo->create($supplier, 'house', $houseRequest, $actor));
        $profile['reason'] = $scrubber->scrub($profile['reason']);
        $profile['title_ref'] = $scrubber->scrub($profile['title_ref']);
        self::assertSame(1, (new PropertyProfileRepository($target))->save($supplier, $profile, $actor)['revision']);
        $confirmation['reason'] = $scrubber->scrub($confirmation['reason']);
        $confirmation['title_ref'] = $scrubber->scrub($confirmation['title_ref']);
        self::assertSame($confirmed, $repo->confirm($supplier, $unit['id'], $detail['history'][1]['id'], $confirmation, $actor));
        $stmt = $pdo->prepare("SELECT payload_json FROM property_audit WHERE supplier_id = ? AND action = 'import'");
        $stmt->execute([$supplier]);
        $anonymousImport = json_decode($stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR)['request'];
        self::assertSame($imported, (new PropertyImportService($target))->apply($supplier, $anonymousImport, $actor));
        $this->refused('property_idempotency_conflict', fn () => (new PropertyImportService($target))->apply($supplier, $importRequest, $actor));
        $change = $pending;
        $change['state'] = $detail['revision']['state'];
        $change['expected_revision'] = 3;
        $change['idempotency_key'] = 'anonymous-next';
        $change['reason'] = 'Synthetic next evidence';
        self::assertSame(4, $repo->revise($supplier, $unit['id'], 'unit', $change, $actor)['revision']);
        $change['idempotency_key'] = 'stale-after-clone';
        $this->refused('property_revision_conflict', fn () => $repo->revise($supplier, $unit['id'], 'unit', $change, $actor));
        self::assertSame($sourceHash, $this->fingerprint($source->pdo()), 'Source database remains untouched.');
        $partial = $plan;
        $partial['tables'] = array_intersect_key($plan['tables'], array_flip(['property_audit']));
        $cloner->copyTables($partial, $this->schemas[2], static function (): void {});
        $this->fails(fn () => (new PropertyCloneScrubber($this->connect($this->schemas[2])->pdo(), $scrubber))->run(), 'complete schema');
    }

    public function testFullAnonymizationServicePreservesVerifiedPropertyHistory(): void
    {
        $source = $this->connect($this->schemas[0]);
        [$supplier, $actor, , , , $unit, , , , $originalImport, $imported] = $this->fixture($source);
        $sourceHash = $this->fingerprint($source->pdo());
        $this->schemas[1] = CloneSandbox::anonymized($this->server, $this->config, $this->schemas[0], 'synthetic-property-service');
        $target = $this->connect($this->schemas[1]);
        $revisions = $target->pdo()->prepare('SELECT state_json, state_hash FROM property_revisions WHERE supplier_id = ? ORDER BY id');
        $revisions->execute([$supplier]);
        $rows = $revisions->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(5, $rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('registry-person@example.test', $row['state_json']);
            self::assertSame(PropertyInput::hash(json_decode($row['state_json'], true, flags: JSON_THROW_ON_ERROR)), $row['state_hash']);
        }
        $detail = (new PropertyReadRepository($target))->find($supplier, $unit['id'], '2026-06-01T12:00:00Z');
        self::assertCount(3, $detail['history']);
        self::assertCount(2, $detail['revision']['state']['ownerships'][0]['members']);
        self::assertSame(['num' => '9', 'den' => '100'], $detail['revision']['state']['attributes']['share']);
        $stmt = $target->pdo()->prepare("SELECT payload_json FROM property_audit WHERE supplier_id=? AND action='import'");
        $stmt->execute([$supplier]);
        $anonymousImport = json_decode($stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR)['request'];
        self::assertSame($imported, (new PropertyImportService($target))->apply($supplier, $anonymousImport, $actor));
        $this->refused('property_idempotency_conflict', fn () => (new PropertyImportService($target))->apply($supplier, $originalImport, $actor));
        self::assertSame($sourceHash, $this->fingerprint($source->pdo()));
    }

    private function fixture(Connection $db): array
    {
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$supplier]);
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $clients = [];
        foreach ([1, 2] as $index) {
            $pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplier, 'Synthetic clone person ' . $index, 'Synthetic street', 'Synthetic city', '10000', $country, 'registry-person@example.test', $currency]);
            $clients[] = (int) $pdo->lastInsertId();
        }
        $profile = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'enabled' => true, 'reason' => 'Profile registry-person@example.test', 'title_ref' => 'Deed registry-person@example.test', 'idempotency_key' => 'profile'];
        (new PropertyProfileRepository($db))->save($supplier, $profile, $actor);
        $houseRequest = ['code' => 'HOUSE', 'expected_revision' => 0, 'effective_from' => $profile['effective_from'], 'status' => 'confirmed',
            'state' => ['name' => 'House registry-person@example.test', 'active' => true, 'address' => ['street' => 'Synthetic 12', 'city' => 'Synthetic city', 'postal_code' => '10000', 'country' => 'CZ'], 'declaration_ref' => 'Deed registry-person@example.test', 'share_scope' => 'HOUSE', 'registry_complete' => false],
            'reason' => $profile['reason'], 'title_ref' => $profile['title_ref'], 'idempotency_key' => 'house'];
        $repo = new PropertyRegistryRepository($db);
        $house = $repo->create($supplier, 'house', $houseRequest, $actor);
        $unitState = ['unit_no' => '12', 'kind' => 'apartment', 'active' => true, 'attributes' => ['share' => ['num' => '9', 'den' => '100']],
            'ownerships' => [['group_key' => 'SJM', 'kind' => 'marital_community', 'share' => ['num' => '1', 'den' => '1'], 'members' => $clients, 'title_ref' => $profile['title_ref']]],
            'roles' => [['client_id' => $clients[0], 'role' => 'payer', 'scopes' => [], 'title_ref' => $profile['title_ref']]],
            'identifiers' => [['kind' => 'text', 'value' => 'registry-person@example.test', 'payment_scope' => 'supplier', 'source' => 'manual', 'title_ref' => $profile['title_ref']]], 'services' => []];
        $request = ['code' => 'UNIT', 'parent_id' => $house['id'], 'expected_revision' => 0, 'effective_from' => $profile['effective_from'], 'status' => 'confirmed',
            'state' => $unitState, 'reason' => $profile['reason'], 'title_ref' => $profile['title_ref'], 'idempotency_key' => 'unit'];
        $unit = $repo->create($supplier, 'unit', $request, $actor);
        unset($request['code'], $request['parent_id']);
        $pending = array_replace($request, ['expected_revision' => 1, 'effective_from' => '2026-04-30T22:00:00.000000Z', 'status' => 'pending', 'idempotency_key' => 'pending']);
        $repo->revise($supplier, $unit['id'], 'unit', $pending, $actor);
        $detail = (new PropertyReadRepository($db))->find($supplier, $unit['id'], '2026-06-01T12:00:00Z');
        $confirmation = ['expected_revision' => 2, 'effective_from' => $pending['effective_from'], 'reason' => $profile['reason'], 'title_ref' => $profile['title_ref'], 'idempotency_key' => 'confirm'];
        $confirmed = $repo->confirm($supplier, $unit['id'], $detail['history'][1]['id'], $confirmation, $actor);
        $imports = new PropertyImportService($db);
        $importRequest = $imports->previewPlan($supplier, ['house_id' => $house['id'], 'effective_from' => $profile['effective_from'], 'status' => 'confirmed', 'reason' => $profile['reason'], 'title_ref' => $profile['title_ref'], 'units' => [['code' => 'IMPORTED', 'state' => $unitState]]]) + ['idempotency_key' => 'import'];
        $imported = $imports->apply($supplier, $importRequest, $actor);
        $pdo->commit();
        return [$supplier, $actor, $profile, $houseRequest, $house, $unit, $pending, $confirmation, $confirmed, $importRequest, $imported];
    }

    private function connect(string $schema): Connection
    {
        $data = $this->config->all(); $data['db']['name'] = $schema;
        $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $this->config->dataDir())));
        $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->connections[] = $db;
        return $db;
    }
    private function fingerprint(PDO $pdo): string
    {
        $out = [];
        foreach (['property_objects', 'property_module_profiles', 'property_revisions', 'property_revision_groups', 'property_revision_roles', 'property_revision_identifiers', 'property_audit'] as $table) $out[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return PropertyInput::hash($out);
    }
    private function fails(callable $work, string $fragment): void
    {
        try { $work(); self::fail('Expected clone refusal: ' . $fragment); } catch (\RuntimeException | \LogicException $e) { self::assertStringContainsString($fragment, $e->getMessage()); }
    }
    private function refused(string $code, callable $work): void
    {
        try { $work(); self::fail('Expected ' . $code); } catch (PropertyException $e) { self::assertSame($code, $e->errorCode); }
    }
}
