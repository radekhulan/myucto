<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Action\Dashboard\DashboardLayoutAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Dashboard\DashboardLayoutConflict;
use MyInvoice\Service\Dashboard\DashboardLayoutRegistry;
use MyInvoice\Service\Dashboard\DashboardLayoutService;
use MyInvoice\Service\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class DashboardLayoutServiceTest extends TestCase
{
    private Connection $db;
    private DashboardLayoutService $layouts;
    private DashboardLayoutRegistry $registry;
    private array $users = [];

    protected function setUp(): void
    {
        $this->db = new Connection(Config::load(dirname(__DIR__, 4)));
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        for ($i = 0; $i < 2; ++$i) {
            $pdo->prepare("INSERT INTO users (email,password_hash,name,role) VALUES (?,?,'Syntetický čtenář rozložení','readonly')")
                ->execute(['layout-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash('synthetic-only-password', PASSWORD_BCRYPT, ['cost' => 4])]);
            $this->users[] = (int) $pdo->lastInsertId();
        }
        $this->registry = new DashboardLayoutRegistry(new DashboardWidgetRegistry());
        $this->layouts = new DashboardLayoutService($this->db, $this->registry);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        foreach ($this->users as $user) $this->db->pdo()->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
        $this->db->close();
    }

    public function testOwnerBoardIsolationConflictAndResetTombstone(): void
    {
        $owner = $this->users[0];
        $initial = $this->layouts->get($owner, 'portal');
        self::assertSame(['layout' => null, 'revision' => '0', 'recovered' => false], $initial);
        $saved = $this->layouts->put($owner, 'portal', $this->registry->defaults('portal'), '0');
        self::assertEquals($saved, $this->layouts->get($owner, 'portal'));
        self::assertNotSame('0', $saved['revision']);
        self::assertSame($initial, $this->layouts->get($this->users[1], 'portal'));
        self::assertSame($initial, $this->layouts->get($owner, 'home'));
        try {
            $this->layouts->put($owner, 'portal', ['schemaVersion' => 2, 'items' => []], '0');
            self::fail('Stará revize nesmí přepsat cizí uložení.');
        } catch (DashboardLayoutConflict) {
            self::assertEquals($saved, $this->layouts->get($owner, 'portal'));
        }
        $reset = $this->layouts->reset($owner, 'portal', $saved['revision']);
        self::assertNull($reset['layout']);
        self::assertNotSame('0', $reset['revision']);
        self::assertSame($reset, $this->layouts->reset($owner, 'portal', $reset['revision']));
        $this->expectException(DashboardLayoutConflict::class);
        $this->layouts->put($owner, 'portal', $this->registry->defaults('portal'), '0');
    }

    #[DataProvider('corruptPayloads')]
    public function testCorruptStoredPreferenceFallsBackWithoutWritingAndCanBeReset(string $payload): void
    {
        $this->db->pdo()->prepare('INSERT INTO user_preferences (user_id,pref_key,payload) VALUES (?,\'dashboard.portal\',?)')->execute([$this->users[0], $payload]);
        $current = $this->layouts->get($this->users[0], 'portal');
        self::assertNull($current['layout']);
        self::assertTrue($current['recovered']);
        self::assertNotSame('0', $current['revision']);
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM user_preferences WHERE user_id=? AND pref_key=\'dashboard.portal\'');
        $stmt->execute([$this->users[0]]);
        self::assertSame($payload, $stmt->fetchColumn(), 'Čtení vadné preference nesmí zapisovat ani v demu.');
        $reset = $this->layouts->reset($this->users[0], 'portal', $current['revision']);
        self::assertNull($reset['layout']);
        self::assertFalse($reset['recovered']);
        self::assertNotSame($current['revision'], $reset['revision']);
        self::assertEquals($reset, $this->layouts->get($this->users[0], 'portal'));
    }

    public static function corruptPayloads(): iterable
    {
        // Sloupec je JSON-validovaný; syntakticky platné, ale neplatné konfigurace
        // pokrývají starý formát, neznámý typ i chybějící revizní obálku.
        yield 'old shape' => ['{"layout":{"schemaVersion":1,"widgets":[]},"revision":"broken"}'];
        yield 'unknown widget' => ['{"layout":{"schemaVersion":2,"items":[{"id":"bad","kind":"widget","type":"removed.widget","span":12,"height":"auto","options":{}}]},"revision":"0"}'];
        yield 'no envelope' => ['{"items":[]}'];
        yield 'scalar' => ['null'];
    }

    public function testInvalidPreferenceCanBeReplacedByValidEmptyLayout(): void
    {
        $this->db->pdo()->prepare('INSERT INTO user_preferences (user_id,pref_key,payload) VALUES (?,\'dashboard.home\',\'null\')')->execute([$this->users[0]]);
        $before = $this->layouts->get($this->users[0], 'home');
        $saved = $this->layouts->put($this->users[0], 'home', ['schemaVersion' => 2, 'items' => []], $before['revision']);
        self::assertSame([], $saved['layout']['items']);
        self::assertFalse($saved['recovered']);
    }

    public function testDemoCannotSaveOrResetButCanReadOwnPreference(): void
    {
        $saved = $this->layouts->put($this->users[0], 'home', $this->registry->defaults('home'), '0');
        $action = new DashboardLayoutAction($this->layouts);
        $request = $this->request()->withAttribute(DemoReadOnlyMiddleware::ATTR_ENABLED, true)
            ->withParsedBody(['layout' => ['schemaVersion' => 2, 'items' => []], 'revision' => $saved['revision']]);
        foreach (['put', 'delete'] as $method) {
            $response = $action->$method($request, new Response(), ['board' => 'home']);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('demo_read_only', json_decode((string) $response->getBody(), true)['error']['code']);
        }
        self::assertEquals($saved, $this->layouts->get($this->users[0], 'home'));
        $read = $action->get($request, new Response(), ['board' => 'home']);
        self::assertSame(200, $read->getStatusCode());
        self::assertFalse(json_decode((string) $read->getBody(), true)['writable']);
    }

    public function testSessionOnlyValidationRejectsInjectedOwnerAndBearerBeforeWrites(): void
    {
        $action = new DashboardLayoutAction($this->layouts);
        $body = ['layout' => $this->registry->defaults('home'), 'revision' => '0'];
        self::assertSame(422, $action->put($this->request()->withParsedBody($body + ['user_id' => $this->users[1]]), new Response(), ['board' => 'home'])->getStatusCode());
        foreach (['bearer', null] as $method) {
            $request = $this->request()->withAttribute(AuthMiddleware::ATTR_METHOD, $method)->withParsedBody($body);
            foreach (['get', 'put', 'delete'] as $operation) self::assertSame(403, $action->$operation($request, new Response(), ['board' => 'home'])->getStatusCode());
        }
        self::assertSame('0', $this->layouts->get($this->users[0], 'home')['revision']);
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/user/dashboard-layouts/home')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->users[0]])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 123)
            ->withAttribute('auth.effective_role', new EffectiveRole(31, 'Syntetický čtenář', 'staff', true, ['profile' => 1, 'dashboard' => 1]));
    }
}
