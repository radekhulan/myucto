<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyImportService;
use MyInvoice\Service\Property\PropertySummaryService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyRegistryTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private int $supplier;
    private int $other;
    private int $actor;
    private array $clients;
    private int $foreignClient;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $seed = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($pdo, $seed);
        $this->other = $this->createIsolatedSupplier($pdo, $seed);
        $this->actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET legal_form = NULL, accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplier]);
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $this->clients = [];
        for ($i = 1; $i <= 18; ++$i) {
            $insert->execute([$this->supplier, 'Synthetic person ' . $i, 'Synthetic street', 'Synthetic city', '10000', $country, 'synthetic' . $i . '@example.test', $currency]);
            $this->clients[$i] = (int) $pdo->lastInsertId();
        }
        $insert->execute([$this->other, 'Foreign synthetic person', 'Synthetic street', 'Synthetic city', '10000', $country, 'foreign@example.test', $currency]);
        $this->foreignClient = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testTwelveUnitsProfileHistoryAndSingleMaritalParticipation(): void
    {
        $profile = new PropertyProfileRepository($this->db);
        $request = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'effective_to' => null,
            'enabled' => true, 'reason' => 'Synthetic declaration', 'title_ref' => 'Synthetic SVJ deed', 'idempotency_key' => 'profile-on'];
        $saved = $profile->save($this->supplier, $request, $this->actor);
        self::assertSame($saved, $profile->save($this->supplier, $request, $this->actor));
        $repo = new PropertyRegistryRepository($this->db);
        $house = $repo->create($this->supplier, 'house', $this->request('LIP', self::house()), $this->actor);
        $service = $repo->create($this->supplier, 'service', $this->request('WATER', ['name' => 'Synthetic water', 'active' => true, 'measure' => 'm3', 'measure_label' => '']), $this->actor);
        $fixture = require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php';
        $created = [];
        foreach ($fixture as $code => $state) {
            foreach ($state['ownerships'] as &$group) $group['members'] = array_map(fn (int $id): int => $this->clients[$id], $group['members']);
            unset($group);
            foreach ($state['roles'] as &$role) $role['client_id'] = $this->clients[$role['client_id']];
            unset($role);
            $state['services'] = $state['services'] === [] ? [] : [$service['id']];
            $created[$code] = $repo->create($this->supplier, 'unit', $this->request($code, $state) + ['parent_id' => $house['id']], $this->actor);
        }
        $read = new PropertyReadRepository($this->db);
        $units = $read->list($this->supplier, 'unit', '2026-06-01T12:00:00+02:00');
        self::assertCount(12, $units);
        $summary = (new PropertySummaryService($this->db))->at($this->supplier, '2026-06-01T12:00:00+02:00');
        self::assertSame(['num' => '1', 'den' => '1'], $summary['scopes'][0]['total_share']);
        self::assertTrue($summary['scopes'][0]['share_complete']);
        $sjm = $read->find($this->supplier, $created['U01']['id'], '2026-06-01T12:00:00+02:00');
        self::assertCount(1, $sjm['revision']['state']['ownerships']);
        self::assertSame([$this->clients[1], $this->clients[2]], $sjm['revision']['state']['ownerships'][0]['members']);
        self::assertSame(['num' => '1', 'den' => '1'], $sjm['revision']['state']['ownerships'][0]['share']);
        self::assertCount(12, array_unique(array_column($units, 'dimension_value_id')));
        $usage = new \MyInvoice\Service\Property\PropertyHistoryUseGuard($this->db);
        self::assertTrue($usage->supplierHasHistory($this->supplier, true));
        self::assertFalse($usage->supplierHasHistory($this->other, true));
        self::assertTrue($usage->clientHasHistory($this->supplier, $this->clients[1], true));
        self::assertTrue($usage->clientHasHistory($this->supplier, $this->clients[13], true));
        self::assertFalse($usage->clientHasHistory($this->other, $this->clients[1], true));
        $action = new \MyInvoice\Action\Property\PropertyAction($this->db, $read, $profile, $repo, new PropertyImportService($this->db), new PropertySummaryService($this->db));
        $exportRequest = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/property/export')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withQueryParams(['as_of' => '2026-06-01T12:00:00+02:00']);
        $exported = $action->export($exportRequest, new \Slim\Psr7\Response());
        self::assertSame(200, $exported->getStatusCode());
        $exportBody = json_decode((string) $exported->getBody(), true);
        self::assertCount(12, $exportBody['unit']);
        self::assertContains('Synthetic person 1', array_column($exportBody['parties'], 'company_name'));
        self::assertSame(403, $action->export($exportRequest->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'bearer'), new \Slim\Psr7\Response())->getStatusCode());
        $exportAudit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM property_audit WHERE supplier_id = ? AND action = 'export' AND created_by = ?");
        $exportAudit->execute([$this->supplier, $this->actor]);
        self::assertSame(1, (int) $exportAudit->fetchColumn());
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM dimension_types WHERE supplier_id = ? AND system_key IN ('unit','service')");
        $stmt->execute([$this->other]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        $pdo->prepare("UPDATE supplier SET legal_form = '112' WHERE id = ?")->execute([$this->supplier]);
        $off = $request;
        $off['expected_revision'] = 1;
        $off['effective_from'] = '2026-07-01T00:00:00+02:00';
        $off['enabled'] = false;
        $off['idempotency_key'] = 'profile-off';
        $profile->save($this->supplier, $off, $this->actor);
        self::assertCount(12, $read->list($this->supplier, 'unit', '2026-08-01T12:00:00+02:00'));
        self::assertTrue($profile->at($this->supplier, '2026-06-01T00:00:00Z')['enabled']);
        self::assertFalse($profile->at($this->supplier, '2026-08-01T00:00:00Z')['enabled']);
        $blocked = $this->request('BLOCKED', self::house());
        $blocked['effective_from'] = '2026-08-01T00:00:00+02:00';
        $this->refused('property_profile_required', fn () => $repo->create($this->supplier, 'house', $blocked, $this->actor));
        self::assertCount(1, $read->list($this->supplier, 'house', '2026-08-01T12:00:00+02:00'));
        $this->sqlRefused(fn () => $pdo->prepare('UPDATE property_revisions SET reason = ? WHERE id = ?')->execute(['rewrite', $sjm['revision']['id']]));
        $this->sqlRefused(fn () => $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clients[1]]));
        $this->sqlRefused(fn () => $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]));
        $groupId = (int) $pdo->query('SELECT id FROM property_revision_groups WHERE revision_id = ' . $sjm['revision']['id'])->fetchColumn();
        $this->sqlRefused(fn () => $pdo->prepare('INSERT INTO property_revision_members (supplier_id, group_id, client_id) VALUES (?, ?, ?)')->execute([$this->supplier, $groupId, $this->foreignClient]));
        self::assertSame($sjm['revision']['state'], $read->find($this->supplier, $created['U01']['id'], '2026-06-01T12:00:00+02:00')['revision']['state']);
    }

    public function testPendingTransferConfirmationSecondTransferAndCasKeepPastFacts(): void
    {
        (new PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'profile'], $this->actor);
        $repo = new PropertyRegistryRepository($this->db);
        $reader = new PropertyReadRepository($this->db);
        $house = $repo->create($this->supplier, 'house', $this->request('HOUSE', self::house()), $this->actor);
        $state = (require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php')['U05'];
        $state['services'] = [];
        $state['ownerships'][0]['members'] = [$this->clients[7]];
        $unit = $repo->create($this->supplier, 'unit', $this->request('U05', $state) + ['parent_id' => $house['id']], $this->actor);
        $past = $reader->find($this->supplier, $unit['id'], '2026-04-01T12:00:00+02:00');
        $state['ownerships'][0]['members'] = [$this->clients[8]];
        $pending = ['expected_revision' => 1, 'effective_from' => '2026-05-01T10:15:30+02:00', 'status' => 'pending', 'state' => $state,
            'reason' => 'Synthetic pending transfer', 'title_ref' => 'Synthetic deed B', 'idempotency_key' => 'pending-B'];
        $repo->revise($this->supplier, $unit['id'], 'unit', $pending, $this->actor);
        $waiting = $reader->find($this->supplier, $unit['id'], '2026-06-01T12:00:00+02:00');
        self::assertSame([$this->clients[7]], $waiting['revision']['state']['ownerships'][0]['members']);
        self::assertTrue($waiting['ownership_review_required']);
        $confirm = ['expected_revision' => 2, 'effective_from' => $pending['effective_from'], 'reason' => 'Synthetic proof confirmed',
            'title_ref' => 'Synthetic verified deed B', 'idempotency_key' => 'confirm-B'];
        $confirmed = $repo->confirm($this->supplier, $unit['id'], $waiting['pending_revision_ids'][0], $confirm, $this->actor);
        self::assertSame($confirmed, $repo->confirm($this->supplier, $unit['id'], $waiting['pending_revision_ids'][0], $confirm, $this->actor));
        $current = $reader->find($this->supplier, $unit['id'], '2026-06-01T12:00:00+02:00');
        self::assertSame([$this->clients[8]], $current['revision']['state']['ownerships'][0]['members']);
        self::assertFalse($current['ownership_review_required']);
        $next = $pending;
        $next['expected_revision'] = 3;
        $next['effective_from'] = '2026-09-01T00:00:00+02:00';
        $next['status'] = 'confirmed';
        $next['state']['ownerships'][0]['members'] = [$this->clients[9]];
        $next['idempotency_key'] = 'transfer-C';
        $repo->revise($this->supplier, $unit['id'], 'unit', $next, $this->actor);
        self::assertSame([$this->clients[9]], $reader->find($this->supplier, $unit['id'], '2026-10-01T00:00:00+02:00')['revision']['state']['ownerships'][0]['members']);
        self::assertSame($past['revision'], $reader->find($this->supplier, $unit['id'], '2026-04-01T12:00:00+02:00')['revision']);
        self::assertSame($past['dimension_value_id'], $current['dimension_value_id']);
        $next['idempotency_key'] = 'stale-C';
        $this->refused('property_revision_conflict', fn () => $repo->revise($this->supplier, $unit['id'], 'unit', $next, $this->actor));
        $next['expected_revision'] = 4;
        $next['state']['ownerships'][0]['members'] = [$this->foreignClient];
        $this->refused('property_party_not_found', fn () => $repo->revise($this->supplier, $unit['id'], 'unit', $next, $this->actor));
        self::assertCount(4, $reader->find($this->supplier, $unit['id'], '2026-10-01T00:00:00+02:00')['history']);
    }

    public function testImportIsAtomicIdempotentAndRejectsStalePreviewAndForeignParty(): void
    {
        (new PropertyProfileRepository($this->db))->save($this->supplier, ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00',
            'enabled' => true, 'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'profile'], $this->actor);
        $repo = new PropertyRegistryRepository($this->db);
        $house = $repo->create($this->supplier, 'house', $this->request('HOUSE', self::house()), $this->actor);
        $state = (require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php')['U05'];
        $state['services'] = [];
        $state['ownerships'][0]['members'] = [$this->clients[7]];
        $input = ['house_id' => $house['id'], 'effective_from' => '2026-01-01T00:00:00+01:00', 'status' => 'confirmed',
            'reason' => 'Synthetic import', 'title_ref' => 'Synthetic imported deed', 'units' => [['code' => 'ONE', 'state' => $state], ['code' => 'TWO', 'state' => $state]]];
        $imports = new PropertyImportService($this->db);
        $preview = $imports->previewPlan($this->supplier, $input);
        $before = $this->propertyCounts();
        $this->refused('property_identifier_conflict', fn () => $imports->apply($this->supplier, $preview + ['idempotency_key' => 'import'], $this->actor));
        self::assertSame($before, $this->propertyCounts(), 'Late duplicate VS rolls back prior object, dimension, revision and audit inserts.');
        $input['units'][1]['state']['identifiers'][0]['value'] = '200';
        $preview = $imports->previewPlan($this->supplier, $input);
        $request = $preview + ['idempotency_key' => 'import'];
        $result = $imports->apply($this->supplier, $request, $this->actor);
        self::assertCount(2, $result);
        self::assertSame($result, $imports->apply($this->supplier, $request, $this->actor));
        $changed = $request;
        $changed['plan']['reason'] = 'Changed request';
        $this->refused('property_idempotency_conflict', fn () => $imports->apply($this->supplier, $changed, $this->actor));
        $stale = $imports->previewPlan($this->supplier, $input);
        $change = $this->request('IGNORED', $state);
        unset($change['code']);
        $change['expected_revision'] = 1;
        $change['idempotency_key'] = 'manual-revision';
        $repo->revise($this->supplier, $result[0]['id'], 'unit', $change, $this->actor);
        $this->refused('property_preview_stale', fn () => $imports->apply($this->supplier, $stale + ['idempotency_key' => 'stale-import'], $this->actor));
        $input['units'][0]['state']['ownerships'][0]['members'] = [$this->foreignClient];
        $this->refused('property_party_not_found', fn () => $imports->previewPlan($this->supplier, $input));
    }

    public function testPropertyDimensionsPreserveOneFractionalAxisAndRejectAmbiguousOrUnavailableFacts(): void
    {
        $profiles = new PropertyProfileRepository($this->db);
        $profile = ['expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'enabled' => true,
            'reason' => 'Synthetic profile', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'profile'];
        $profiles->save($this->supplier, $profile, $this->actor);
        $repo = new PropertyRegistryRepository($this->db);
        $house = $repo->create($this->supplier, 'house', $this->request('HOUSE', self::house()), $this->actor);
        $reader = new PropertyReadRepository($this->db);
        $serviceValues = [];
        foreach (['WATER', 'HEAT'] as $code) {
            $service = $repo->create($this->supplier, 'service', $this->request($code, ['name' => $code, 'active' => true, 'measure' => 'unit', 'measure_label' => '']), $this->actor);
            $serviceValues[] = $reader->find($this->supplier, $service['id'], '2026-05-01T00:00:00+02:00')['dimension_value_id'];
        }
        $state = (require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php')['U05'];
        $state['services'] = [];
        $state['identifiers'] = [];
        $state['ownerships'][0]['members'] = [$this->clients[7]];
        $units = [];
        foreach (['ONE', 'TWO'] as $code) {
            $unit = $repo->create($this->supplier, 'unit', $this->request($code, $state) + ['parent_id' => $house['id']], $this->actor);
            $units[] = $reader->find($this->supplier, $unit['id'], '2026-05-01T00:00:00+02:00');
        }
        $stmt = $this->db->pdo()->prepare("SELECT system_key, id FROM dimension_types WHERE supplier_id = ? AND system_key IN ('unit','service')");
        $stmt->execute([$this->supplier]);
        $types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $policy = new \MyInvoice\Service\Property\PropertyDimensionPolicy($this->db);
        $scalar = ['dimensions' => [$types['unit'] => $units[0]['dimension_value_id'], $types['service'] => $serviceValues[0]]];
        $split = ['dimensions' => [$types['service'] => $serviceValues[0]], 'dimension_splits' => [$types['unit'] => [
            $units[0]['dimension_value_id'] => 0.25, $units[1]['dimension_value_id'] => 0.75]]];
        $policy->assertAccountingDate($this->supplier, '2026-05-01', [$scalar, $split]);
        $this->refused('property_dimension_unavailable', fn () => $policy->assertAccountingDate($this->supplier, '2026-05-01', [
            ['dimensions' => [$types['service'] => $units[0]['dimension_value_id'], $types['unit'] => $units[0]['dimension_value_id']]]]));
        $ambiguous = $split;
        unset($ambiguous['dimensions']);
        $ambiguous['dimension_splits'][$types['service']] = [$serviceValues[0] => 0.5, $serviceValues[1] => 0.5];
        $this->refused('property_joint_required', fn () => $policy->assertAccountingDate($this->supplier, '2026-05-01', [$ambiguous]));
        $off = $this->request('IGNORED', array_replace($state, ['active' => false]));
        unset($off['code']);
        $off['expected_revision'] = 1;
        $off['effective_from'] = '2026-05-01T12:00:00+02:00';
        $repo->revise($this->supplier, $units[1]['id'], 'unit', $off, $this->actor);
        $policy->assertAt($this->supplier, '2026-05-01T11:59:59+02:00', [$split]);
        $this->refused('property_dimension_unavailable', fn () => $policy->assertAccountingDate($this->supplier, '2026-05-01', [$split]));
        $this->refused('property_dimension_unavailable', fn () => $policy->assertAt($this->supplier, '2026-05-01T12:00:00+02:00', [$split]));
        $policy->assertAccountingDate($this->supplier, '2026-05-01', [$scalar]);
        $profile['expected_revision'] = 1;
        $profile['effective_from'] = '2026-07-01T00:00:00+02:00';
        $profile['enabled'] = false;
        $profile['idempotency_key'] = 'disable';
        $profiles->save($this->supplier, $profile, $this->actor);
        $this->refused('property_profile_required', fn () => $policy->assertAccountingDate($this->supplier, '2026-08-01', [$scalar]));
        $policy->assertAccountingDate($this->supplier, '2026-05-01', [$scalar]);
        self::assertSame($units[0]['revision'], $reader->find($this->supplier, $units[0]['id'], '2026-05-01T00:00:00+02:00')['revision'], 'Historical source facts remain readable for verified settlement after disable.');
        $this->refused('property_dimension_unavailable', fn () => $policy->assertAccountingDate($this->other, '2026-05-01', [$scalar]));
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2027-01-01', 'cz_500')")->execute([$this->supplier]);
        $this->refused('property_framework_required', fn () => $policy->assertAccountingDate($this->supplier, '2027-02-01', [$scalar]));
    }

    private function propertyCounts(): array
    {
        $out = [];
        foreach (['property_objects', 'property_revisions', 'property_audit', 'dimension_values', 'dimension_types'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id = ?');
            $stmt->execute([$this->supplier]);
            $out[$table] = (int) $stmt->fetchColumn();
        }
        return $out;
    }

    private function request(string $code, array $state): array
    {
        return ['code' => $code, 'expected_revision' => 0, 'effective_from' => '2026-01-01T00:00:00+01:00', 'effective_to' => null,
            'status' => 'confirmed', 'state' => $state, 'reason' => 'Synthetic initial register', 'title_ref' => 'Synthetic deed', 'idempotency_key' => 'create-' . $code];
    }

    private static function house(): array
    {
        return ['name' => 'Dům U Lip', 'active' => true, 'address' => ['street' => 'Syntetická 12', 'city' => 'Testovací město', 'postal_code' => '10000', 'country' => 'CZ'],
            'declaration_ref' => 'Synthetic declaration', 'share_scope' => 'LIP-DECLARATION', 'registry_complete' => false];
    }

    private function refused(string $code, callable $work): void
    {
        try { $work(); self::fail('Expected ' . $code); }
        catch (PropertyException $e) { self::assertSame($code, $e->errorCode); }
    }

    private function sqlRefused(callable $work): void
    {
        try { $work(); self::fail('Expected immutable history or tenant FK refusal.'); }
        catch (\PDOException $e) { self::assertContains((string) $e->getCode(), ['23000', '45000']); }
    }
}
