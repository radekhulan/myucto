<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Action\Dashboard\DashboardLayoutAction;
use MyInvoice\Action\UserSettings\UserPreferenceAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Service\License\LicenseConsistency;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Service\Dashboard\DashboardWidgetAccess;
use Psr\Log\NullLogger;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Dashboard\DashboardLayoutConflict;
use MyInvoice\Service\Dashboard\DashboardLayoutService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class DashboardLayoutServiceTest extends TestCase
{
    private Connection $db;
    private DashboardLayoutService $layouts;
    private array $users = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('Test vyžaduje konfiguraci izolované testovací DB.');
        $this->db = new Connection(Config::load($root));
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $role = $pdo->query("SELECT id FROM roles WHERE system_key = 'readonly'")->fetchColumn();
        self::assertNotFalse($role, 'Test potřebuje migrovaný čtecí profil.');
        for ($i = 0; $i < 2; ++$i) {
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active) VALUES (?, ?, ?, ?, 'cs', 1)");
            $stmt->execute(['__dashboard_' . bin2hex(random_bytes(8)) . '@example.test', password_hash('synthetic-dashboard-password', PASSWORD_BCRYPT, ['cost' => 4]), 'Syntetický čtenář přehledu', $role]);
            $this->users[] = (int) $pdo->lastInsertId();
        }
        $this->layouts = new DashboardLayoutService($this->db, new DashboardWidgetRegistry());
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($this->users as $user) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
        }
        $this->db->close();
    }

    public function testFirstSaveConcurrentStaleSaveAndOwnerBoardIsolation(): void
    {
        $owner = $this->users[0];
        self::assertSame(['layout' => null, 'revision' => '0'], $this->layouts->get($owner, 'portal'));
        $saved = $this->layouts->put($owner, 'portal', $this->layout(), '0');
        self::assertNotSame('0', $saved['revision']);
        self::assertEquals($saved, $this->layouts->get($owner, 'portal'));
        self::assertSame(['layout' => null, 'revision' => '0'], $this->layouts->get($owner, 'home'));
        self::assertSame(['layout' => null, 'revision' => '0'], $this->layouts->get($this->users[1], 'portal'));
        try {
            $this->layouts->put($owner, 'portal', ['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => []], '0');
            self::fail('Stará revize nesmí přepsat uloženou sestavu.');
        } catch (DashboardLayoutConflict) {
            self::assertEquals($saved, $this->layouts->get($owner, 'portal'));
        }
        $next = $this->layouts->put($owner, 'portal', $this->layout(), $saved['revision']);
        self::assertNotSame($saved['revision'], $next['revision']);
        $this->expectException(DashboardLayoutConflict::class);
        $this->layouts->reset($owner, 'portal', $saved['revision']);
    }

    public function testResetIsIdempotentAndPreventsAbaReuse(): void
    {
        $owner = $this->users[0];
        self::assertSame(['layout' => null, 'revision' => '0'], $this->layouts->reset($owner, 'home', '0'));
        $saved = $this->layouts->put($owner, 'home', $this->layout(), '0');
        $reset = $this->layouts->reset($owner, 'home', $saved['revision']);
        self::assertNull($reset['layout']);
        self::assertNotSame('0', $reset['revision']);
        self::assertNotSame($saved['revision'], $reset['revision']);
        self::assertSame($reset, $this->layouts->reset($owner, 'home', $reset['revision']));
        self::assertSame($reset, $this->layouts->get($owner, 'home'));
        $this->expectException(DashboardLayoutConflict::class);
        $this->layouts->put($owner, 'home', $this->layout(), '0');
    }

    public function testReadonlySessionCanStoreHiddenKnownWidgetsButNotInjectOwner(): void
    {
        $action = new DashboardLayoutAction($this->layouts);
        $request = $this->request('PUT')->withParsedBody(['layout' => $this->layout(), 'revision' => '0']);
        $response = $action->put($request, new Response(), ['board' => 'home']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $stored = json_decode((string) $response->getBody(), true);
        self::assertSame('accounting.status', $stored['layout']['widgets'][0]['type']);
        self::assertSame('0', $this->layouts->get($this->users[1], 'home')['revision']);
        $malicious = $request->withParsedBody(['layout' => $this->layout(), 'revision' => $stored['revision'], 'user_id' => $this->users[1]]);
        self::assertSame(422, $action->put($malicious, new Response(), ['board' => 'home'])->getStatusCode());
        $conflict = $action->put($request, new Response(), ['board' => 'home']);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('dashboard_layout_conflict', json_decode((string) $conflict->getBody(), true)['error']['code']);
    }

    public function testBearerAnonymousAndWrongBoardAreRejectedBeforeStorage(): void
    {
        $action = new DashboardLayoutAction($this->layouts);
        foreach (['bearer', null] as $method) {
            $request = $this->request('GET')->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
            foreach (['get', 'put', 'delete'] as $operation) {
                self::assertSame(403, $action->$operation($request, new Response(), ['board' => 'portal'])->getStatusCode());
            }
        }
        self::assertSame(422, $action->get($this->request('GET'), new Response(), ['board' => 'other'])->getStatusCode());
    }

    public function testGenericPreferenceEndpointCannotOverwriteOrResetDashboardKeys(): void
    {
        $action = new UserPreferenceAction($this->db);
        foreach (['dashboard.home', 'dashboard.portal'] as $key) {
            $request = $this->request('PUT')->withParsedBody(['widgets' => []]);
            self::assertSame(422, $action->put($request, new Response(), ['key' => $key])->getStatusCode());
            self::assertSame(422, $action->delete($request, new Response(), ['key' => $key])->getStatusCode());
        }
    }

    public function testCommercialWidgetsAndTodoConsumeGenuineLicenseState(): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $config = Config::load(dirname(__DIR__, 4));
        $registry = new DashboardWidgetRegistry();
        $request = $this->request('GET')->withAttribute('auth.effective_role', new EffectiveRole(1, 'Syntetická účetní', 'staff', true,
            ['dashboard' => 1, 'reports' => 1, 'reports.submit' => 2, 'accounting' => 1, 'accounting.periods.manage' => 2, 'payroll.submissions' => 2]));
        $supplier = ['id' => 123, 'is_vat_payer' => 1, 'taxpayer_type' => 'fo', 'accounting_mode' => 'double_entry'];
        foreach ([0 => true, 90 => false] as $age => $available) {
            // Syntetický licenční řádek v rollbackované testovací transakci,
            // stejný skutečný výpočet stavu a konzistence jako v middleware.
            $pdo->prepare('UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL, trial_started_at = ? WHERE id = 1')
                ->execute([date('Y-m-d H:i:s', time() - $age * 86400)]);
            $consistency = new LicenseConsistency($this->db, $config, EntityCache::disabled(), new NullLogger());
            $license = new LicenseService($this->db, $config, new LicenseTokenVerifier(), new LicenseClient($config),
                cache: EntityCache::disabled(), consistency: $consistency);
            $state = $license->current();
            self::assertSame($available ? LicenseState::TRIAL : LicenseState::TRIAL_EXPIRED, $state->state);
            $scoped = $request->withAttribute(LicenseMiddleware::ATTR_STATE, $state);
            $access = new DashboardWidgetAccess($this->db, $registry, $consistency);
            foreach (['tax.net', 'tax.advances', 'accounting.status'] as $type) {
                self::assertSame($available, $access->allows($scoped, $registry->get($type), $supplier), $type);
            }
            $capabilities = $access->capabilities($scoped, 'actions.todo');
            foreach (['taxTasks', 'accountingPeriods', 'payrollSubmit'] as $capability) {
                self::assertSame($available, $capabilities[$capability], $capability);
            }
            self::assertTrue($access->allows($scoped, $registry->get('business.kpi'), $supplier));
        }
        $pdo->rollBack();
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/user/dashboard-layouts/home')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->users[0]])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 123)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Syntetický čtenář', 'staff', true, ['dashboard' => 1, 'profile' => 1]));
    }

    private function layout(): array
    {
        return ['schemaVersion' => 1, 'presetVersion' => 1, 'widgets' => [
            ['id' => 'hidden-accounting', 'type' => 'accounting.status', 'span' => 12, 'options' => []],
        ]];
    }
}
