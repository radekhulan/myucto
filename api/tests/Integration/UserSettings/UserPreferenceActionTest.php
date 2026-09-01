<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\UserSettings;

use MyInvoice\Action\UserSettings\UserPreferenceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy per-user preferencí tabulek (Epic F5 §3.3/§3.4).
 *
 * Preference jsou globální per user (BEZ supplier scope — R3). Jedna transakce,
 * rollback v tearDown; soft-skip bez cfg.php.
 */
#[Group('integration')]
final class UserPreferenceActionTest extends TestCase
{
    private Connection $db;
    private UserPreferenceAction $action;

    private int $userId = 0;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container    = Bootstrap::buildApp()->getContainer();
            $this->db     = $container->get(Connection::class);
            $this->action = $container->get(UserPreferenceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPutUpsertAndGet(): void
    {
        $first = $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['hidden' => ['client'], 'density' => 'compact']]);
        self::assertSame(200, $first['status']);
        self::assertSame(['hidden' => ['client'], 'density' => 'compact'], $first['body']);

        $second = $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['hidden' => ['status']]]);
        self::assertSame(200, $second['status']);
        self::assertSame(['hidden' => ['status']], $second['body']);

        $rows = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM user_preferences WHERE user_id = {$this->userId} AND pref_key = 'table.invoices'"
        )->fetchColumn();
        self::assertSame(1, $rows, 'Druhý PUT přepsal, nezaložil nový řádek.');

        $get = $this->call('list', 'GET');
        self::assertSame(['hidden' => ['status']], $get['body']['table.invoices']);
    }

    public function testGetFilteredByKeys(): void
    {
        $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['density' => 'compact']]);
        $this->call('put', 'PUT', ['args' => ['key' => 'table.journal'], 'body' => ['density' => 'comfortable']]);

        $get = $this->call('list', 'GET', ['query' => ['keys' => 'table.invoices']]);
        self::assertArrayHasKey('table.invoices', $get['body']);
        self::assertArrayNotHasKey('table.journal', $get['body']);
    }

    public function testDeleteResetsKey(): void
    {
        $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['density' => 'compact']]);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'table.invoices']]);
        self::assertSame(200, $del['status']);
        self::assertTrue($del['body']['deleted']);

        $get = $this->call('list', 'GET');
        self::assertArrayNotHasKey('table.invoices', $get['body']);

        $again = $this->call('delete', 'DELETE', ['args' => ['key' => 'table.invoices']]);
        self::assertSame(404, $again['status']);
        self::assertSame('not_found', $again['body']['error']['code']);
    }

    public function testUserIsolation(): void
    {
        $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['density' => 'compact']]);
        $other = $this->userId + 999000;

        $get = $this->call('list', 'GET', ['user' => $other]);
        self::assertArrayNotHasKey('table.invoices', $get['body']);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'table.invoices'], 'user' => $other]);
        self::assertSame(404, $del['status']);
    }

    public function testGlobalAcrossSuppliers(): void
    {
        $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['density' => 'compact'], 'supplier' => $this->supplierId]);

        $get = $this->call('list', 'GET', ['supplier' => $this->supplierId + 99999]);
        self::assertSame(['density' => 'compact'], $get['body']['table.invoices'], 'Preference jsou supplier-agnostic (R3).');
    }

    public function testInvalidPrefKey422(): void
    {
        $noPrefix = $this->call('put', 'PUT', ['args' => ['key' => 'invoices'], 'body' => ['density' => 'compact']]);
        self::assertSame(422, $noPrefix['status']);
        self::assertSame('invalid_pref_key', $noPrefix['body']['error']['code']);

        $unknown = $this->call('put', 'PUT', ['args' => ['key' => 'table.nonexistent'], 'body' => ['density' => 'compact']]);
        self::assertSame(422, $unknown['status']);
        self::assertSame('invalid_pref_key', $unknown['body']['error']['code']);
    }

    public function testPayloadLimits422(): void
    {
        $tooLarge = $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['x' => str_repeat('a', 16400)]]);
        self::assertSame(422, $tooLarge['status']);
        self::assertSame('payload_too_large', $tooLarge['body']['error']['code']);

        $tooDeep = $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['a' => ['b' => ['c' => ['d' => 'e']]]]]);
        self::assertSame(422, $tooDeep['status']);
        self::assertSame('payload_too_deep', $tooDeep['body']['error']['code']);
    }

    public function testNavOrderKeyAccepted(): void
    {
        // §10: nav.order je mimo table.* whitelist, ale platný klíč (globální per user).
        $payload = [
            'sections' => ['taxes', 'finance'],
            'items'    => ['taxes' => ['/reports/dph', '/reports/kh']],
        ];

        $put = $this->call('put', 'PUT', ['args' => ['key' => 'nav.order'], 'body' => $payload]);
        self::assertSame(200, $put['status']);
        self::assertSame($payload, $put['body'], 'Vnořený payload nav.order projde (hloubka 4).');

        $get = $this->call('list', 'GET');
        self::assertSame($payload, $get['body']['nav.order']);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'nav.order']]);
        self::assertSame(200, $del['status']);
        self::assertTrue($del['body']['deleted']);

        // Jiný klíč v namespace nav.* není whitelistovaný → 422.
        $bad = $this->call('put', 'PUT', ['args' => ['key' => 'nav.foo'], 'body' => ['a' => 'b']]);
        self::assertSame(422, $bad['status']);
        self::assertSame('invalid_pref_key', $bad['body']['error']['code']);
    }

    public function testKeyboardShortcutsKeyAcceptedFilteredAndDeleted(): void
    {
        $payload = [
            'version'   => 1,
            'overrides' => [
                'search.global'       => 'alt+q',
                'new:/invoices/new'   => null,
                'nav:/reports/dph'    => 'alt+7',
            ],
        ];

        $put = $this->call('put', 'PUT', ['args' => ['key' => 'keyboard.shortcuts'], 'body' => $payload]);
        self::assertSame(200, $put['status']);
        self::assertSame($payload, $put['body']);

        $filtered = $this->call('list', 'GET', ['query' => ['keys' => 'keyboard.shortcuts']]);
        self::assertSame($payload, $filtered['body']['keyboard.shortcuts']);
        self::assertCount(1, $filtered['body']);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'keyboard.shortcuts']]);
        self::assertSame(200, $del['status']);
        self::assertTrue($del['body']['deleted']);
    }

    public function testOnboardingGuideKeyAcceptedFilteredAndDeleted(): void
    {
        // Průvodce prvním nastavením na Přehledu — ručně odškrtnuté kroky + skrytí.
        $payload = ['hidden' => false, 'done' => ['company', 'bank']];

        $put = $this->call('put', 'PUT', ['args' => ['key' => 'onboarding.guide'], 'body' => $payload]);
        self::assertSame(200, $put['status']);
        self::assertSame($payload, $put['body']);

        $filtered = $this->call('list', 'GET', ['query' => ['keys' => 'onboarding.guide']]);
        self::assertSame($payload, $filtered['body']['onboarding.guide']);
        self::assertCount(1, $filtered['body']);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'onboarding.guide']]);
        self::assertSame(200, $del['status']);
        self::assertTrue($del['body']['deleted']);

        // Sousední klíč v namespace onboarding.* whitelistovaný není.
        $bad = $this->call('put', 'PUT', ['args' => ['key' => 'onboarding.other'], 'body' => ['a' => 'b']]);
        self::assertSame(422, $bad['status']);
        self::assertSame('invalid_pref_key', $bad['body']['error']['code']);
    }

    public function testPayrollGuideKeyAcceptedFilteredAndDeleted(): void
    {
        // Průvodce prvním nastavením MEZD — vlastní klíč vedle onboarding.guide.
        $payload = ['hidden' => true, 'done' => ['employer', 'institutions']];

        $put = $this->call('put', 'PUT', ['args' => ['key' => 'payroll.guide'], 'body' => $payload]);
        self::assertSame(200, $put['status']);
        self::assertSame($payload, $put['body']);

        $filtered = $this->call('list', 'GET', ['query' => ['keys' => 'payroll.guide']]);
        self::assertSame($payload, $filtered['body']['payroll.guide']);
        self::assertCount(1, $filtered['body']);

        $del = $this->call('delete', 'DELETE', ['args' => ['key' => 'payroll.guide']]);
        self::assertSame(200, $del['status']);
        self::assertTrue($del['body']['deleted']);

        // Sousední klíč v namespace payroll.* whitelistovaný není.
        $bad = $this->call('put', 'PUT', ['args' => ['key' => 'payroll.other'], 'body' => ['a' => 'b']]);
        self::assertSame(422, $bad['status']);
        self::assertSame('invalid_pref_key', $bad['body']['error']['code']);
    }

    public function testReadonlyRoleCanWriteOwnPreferences(): void
    {
        $put = $this->call('put', 'PUT', ['args' => ['key' => 'table.invoices'], 'body' => ['density' => 'compact'], 'role' => 'readonly']);
        self::assertSame(200, $put['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $opts
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/user/preferences')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $opts['supplier'] ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $opts['user'] ?? $this->userId, 'role' => $opts['role'] ?? 'accountant']);
        if (isset($opts['query'])) {
            $req = $req->withQueryParams($opts['query']);
        }
        if (array_key_exists('body', $opts)) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
