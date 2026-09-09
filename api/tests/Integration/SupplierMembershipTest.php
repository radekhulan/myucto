<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Epic F0 — membership uživatel ↔ supplier (user_suppliers) + per-supplier role.
 *
 * In-process testy: request jde přes CELOU middleware pipeline
 * (Bootstrap::buildApp() → $app->handle()) bez HTTP serveru. Autentizace přes
 * bearer PAT (user_id bez supplier bindingu → membership se vynucuje stejně
 * jako u session).
 *
 * Fixtures: vlastní test useři (unikátní e-maily), druhý supplier se založí
 * pokud v DB není (šablonou podle prvního — FK na currencies/countries se
 * půjčí od supplier A). Po sobě vše uklízí.
 */
#[Group('integration')]
final class SupplierMembershipTest extends TestCase
{
    /** Musí projít PasswordHasher::validate() (min. 12 znaků). */
    private const PASSWORD = '__test_f0_Heslo123!';

    private Connection $db;
    private Config $config;
    private ApiTokenService $svc;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private bool $createdSupplierB = false;
    private int $currencyB = 0;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $tokenIds = [];
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $redis = new RedisFactory($this->config);
            $this->svc = new ApiTokenService($this->db, $redis);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $has = $this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn();
        if ($has === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }

        // Izolovaný druhý supplier: existující tenant může být záměrně rozpracovaný
        // a nemít vlastní měnu, což nesmí měnit výsledek membership testu.
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                   is_vat_payer, email, default_currency_id, default_vat_rate_id,
                                   default_payment_due_days, default_hourly_rate)
             SELECT '__TEST F0 supplier B', '__TEST F0 supplier B', street, city, zip, country_id,
                    0, email, default_currency_id, default_vat_rate_id,
                    default_payment_due_days, default_hourly_rate
               FROM supplier WHERE id = ?"
        );
        $stmt->execute([$this->supplierA]);
        $this->supplierB = (int) $this->db->pdo()->lastInsertId();
        $this->createdSupplierB = true;

        // Currencies jsou per-supplier — bez CZK řádku by create klienta pod B
        // spadl na "Currency not found". default_currency_id supplier-a B záměrně
        // ukazuje na měnu A (vyhneme se cyklickému FK při úklidu).
        $cur = $this->db->pdo()->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        );
        $cur->execute([$this->supplierB]);
        $this->currencyB = (int) $this->db->pdo()->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        foreach ($this->sessionTokens as $token) {
            try {
                $this->sessions->destroy($token);
            } catch (\Throwable) {
                // best-effort úklid
            }
        }

        if ($this->clientIds !== []) {
            $place = implode(',', array_fill(0, count($this->clientIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ($place)")->execute($this->clientIds);
        }
        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }
        if ($this->createdSupplierB && $this->supplierB > 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierB]);
            if ($this->currencyB > 0) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyB]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierB]);
        }

        $this->userIds = $this->tokenIds = $this->clientIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testMembershipBlocksForeignSupplierAndAllowsOwn(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        // Cizí firma → 403 forbidden_supplier
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(403, $res->getStatusCode(), 'Membership {A} musí blokovat X-Supplier-Id: B');
        $body = $this->json($res);
        self::assertSame('forbidden_supplier', $body['error']['code'] ?? null);

        // Vlastní firma → 200 + správné id
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertSame($this->supplierA, $body['id'] ?? null);
    }

    public function testMembershipFallbackWithoutHeaderLandsOnAssignedSupplier(): void
    {
        // Membership {B} bez X-Supplier-Id → fallback musí jít na B (přiřazenou),
        // ne na globální MIN(id) = A.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierB, null]]);
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/settings/supplier', $token, null);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertSame($this->supplierB, $body['id'] ?? null,
            'Fallback bez headeru musí zvolit přiřazený supplier, ne globální MIN');
    }

    public function testHistoricalForeignDefaultCurrencyDoesNotHideSupplierOrExposeCurrency(): void
    {
        $pdo = $this->db->pdo();
        $query = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $query->execute([$this->supplierA]);
        $currencyId = (int) $query->fetchColumn();
        self::assertGreaterThan(0, $currencyId);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $this->supplierB]);
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierB, null]]);
        $token = $this->mkToken($userId);
        $response = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($this->supplierB, $body['id']);
        self::assertArrayHasKey('default_currency', $body);
        self::assertNull($body['default_currency']);
    }

    public function testUserWithoutMembershipHasNoSupplierAccess(): void
    {
        $userId = $this->mkUser('accountant');
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(403, $res->getStatusCode());
        self::assertSame('forbidden_supplier', $this->json($res)['error']['code'] ?? null);
    }

    public function testSuppliersListFilteredByMembership(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/suppliers', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertSame([$this->supplierA], $ids, 'Uživatel s membership {A} smí vidět jen firmu A');

        // Admin bez membership vidí plný seznam (vč. B)
        $adminId = $this->mkUser('admin');
        $adminToken = $this->mkToken($adminId);
        $res = $this->request('GET', '/api/suppliers', $adminToken, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids);
    }

    public function testBoundTokenCanSeeOnlyItsSupplierInListAndDetail(): void
    {
        // Bound PAT je tenantová autorita i pro globálního admina: token vydaný
        // pro firmu A nesmí přes switcher ani detail odhalit firmu B.
        $adminId = $this->mkUser('admin');
        $bound = $this->mkBoundToken($adminId, $this->supplierA);

        $res = $this->request('GET', '/api/suppliers', $bound, null);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertSame([$this->supplierA], $ids);

        $res = $this->request('GET', '/api/suppliers/' . $this->supplierA, $bound, null);
        self::assertSame(200, $res->getStatusCode());

        $res = $this->request('GET', '/api/suppliers/' . $this->supplierB, $bound, null);
        self::assertSame(404, $res->getStatusCode());
        self::assertSame('not_found', $this->json($res)['error']['code'] ?? null);
    }

    public function testReadonlyOverrideBlocksWriteDespiteGlobalAccountant(): void
    {
        // Globální accountant, ale pro firmu A override 'readonly' → POST blokován;
        // pro firmu B (override NULL = zdědit accountant) POST projde.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, 'readonly'], [$this->supplierB, null]]);
        $token = $this->mkToken($userId);

        $payload = [
            'company_name' => '__TEST F0 client',
            'street' => 'Testovací 1', 'city' => 'Praha', 'zip' => '11000',
            'main_email' => 'f0-test-client@example.com',
        ];

        $beforeDenied = $this->clientCountByEmail($this->supplierA, $payload['main_email']);
        $res = $this->request('POST', '/api/clients', $token, $this->supplierA, $payload);
        self::assertSame(403, $res->getStatusCode(),
            'Per-supplier readonly override musí blokovat mutaci i pro globálního accountanta');
        $body = $this->json($res);
        self::assertSame('forbidden_permission', $body['error']['code'] ?? null);
        self::assertSame(
            $beforeDenied,
            $this->clientCountByEmail($this->supplierA, $payload['main_email']),
            'Zamítnutá mutace nesmí zapsat klienta ani částečně.',
        );

        $res = $this->request('POST', '/api/clients', $token, $this->supplierB, $payload);
        self::assertSame(201, $res->getStatusCode(),
            'Bez override (NULL) platí globální accountant → create musí projít');
        $body = $this->json($res);
        if (isset($body['id'])) {
            $this->clientIds[] = (int) $body['id'];
            // Klient musí vzniknout pod firmou B (scope z headeru)
            self::assertSame($this->supplierB, (int) ($body['supplier_id'] ?? 0));
        }
    }

    public function testAssignmentsEndpointAdminOnlyAndReplacesSet(): void
    {
        // /api/admin/* je pro bearer PAT zakázané (ApiScopeMiddleware path
        // allowlist) → testujeme session autentizací (cookie + CSRF).
        $accountantId = $this->mkUser('accountant');
        $accountantSession = $this->mkSession($accountantId);
        $adminId = $this->mkUser('admin');
        $adminSession = $this->mkSession($adminId);

        // Non-admin → 403 (RoleMiddleware admin-only fallback pro /api/admin/*)
        $res = $this->sessionRequest('GET', "/api/admin/users/$accountantId/suppliers", $accountantSession);
        self::assertSame(403, $res->getStatusCode());
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $accountantSession,
            ['assignments' => [['supplier_id' => $this->supplierA, 'role_id' => null]]]);
        self::assertSame(403, $res->getStatusCode());

        // Admin: PUT replace + GET
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession, [
            'assignments' => [
                ['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('readonly')],
                ['supplier_id' => $this->supplierB, 'role_id' => null],
            ],
        ]);
        self::assertSame(200, $res->getStatusCode());
        $list = $this->json($res);
        self::assertCount(2, $list);
        $bySupplier = array_column($list, null, 'supplier_id');
        self::assertSame($this->roleId('readonly'), $bySupplier[$this->supplierA]['role_id']);
        self::assertNull($bySupplier[$this->supplierB]['role_id']);
        self::assertNotSame('', (string) ($list[0]['name'] ?? ''));

        $res = $this->sessionRequest('GET', "/api/admin/users/$accountantId/suppliers", $adminSession);
        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $this->json($res));

        // Replace na jediné přiřazení — sada se NAHRAZUJE, ne přidává
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession, [
            'assignments' => [['supplier_id' => $this->supplierB, 'role_id' => $this->roleId('accountant')]],
        ]);
        self::assertSame(200, $res->getStatusCode());
        $list = $this->json($res);
        self::assertCount(1, $list);
        self::assertSame($this->supplierB, $list[0]['supplier_id']);
        self::assertSame($this->roleId('accountant'), $list[0]['role_id']);

        // Validace: neexistující supplier → 400; neplatná role → 400
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession,
            ['assignments' => [['supplier_id' => 999999999, 'role_id' => null]]]);
        self::assertSame(400, $res->getStatusCode());
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession,
            ['assignments' => [['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('admin')]]]);
        self::assertSame(400, $res->getStatusCode());
    }

    public function testGlobalAdminExemptFromMembership(): void
    {
        // Bezpečnostní regrese (Finding 4): globální admin s membership řádky {A}
        // NESMÍ být zamčen — vidí i firmu B (admin je vyjmut z membershipu).
        $adminId = $this->mkUser('admin');
        $this->assign($adminId, [[$this->supplierA, null]]);
        $token = $this->mkToken($adminId);

        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(200, $res->getStatusCode(),
            'Globální admin s membershipem musí i tak vidět firmu mimo něj');
        self::assertSame($this->supplierB, $this->json($res)['id'] ?? null);

        // A přepínač firem mu ukáže obě (ne jen přiřazenou A)
        $res = $this->request('GET', '/api/suppliers', $token, $this->supplierA);
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertContains($this->supplierB, $ids, 'Admin vidí plný seznam firem i s membershipem');
    }

    public function testBoundTokenOutsideMembershipDenied(): void
    {
        // Bezpečnostní regrese (Finding 2): non-admin s membershipem {A} a PAT
        // bound na firmu B (mimo membership) → 403, token nesmí obejít F0.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $bound = $this->mkBoundToken($userId, $this->supplierB);

        $res = $this->request('GET', '/api/settings/supplier', $bound, null);
        self::assertSame(403, $res->getStatusCode(),
            'Bound PAT na firmu mimo membership musí být odmítnut');
        self::assertSame('forbidden_supplier', $this->json($res)['error']['code'] ?? null);
    }

    public function testSuperadminTokenCreationIgnoresMembershipRows(): void
    {
        // Bezpečnostní regrese (Finding 2, create-time): uživatel s explicitním
        // membershipem {A} nesmí vytvořit token bound na firmu B. Správu tokenů
        // (POST /api/auth/tokens) pouští RoleMiddleware jen adminovi, proto testujeme
        // superadminem, jehož globální bypass membership řádky neomezují.
        $userId = $this->mkUser('admin');
        $this->assign($userId, [[$this->supplierA, null]]);
        $session = $this->mkSession($userId);

        $res = $this->sessionRequest('POST', '/api/auth/tokens', $session, [
            'name' => '__test_f0_bound_B', 'supplier_id' => $this->supplierB, 'scope' => 'read',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $res->getStatusCode(),
            'Superadmin smí vytvořit token pro libovolnou firmu');
        $id = $this->json($res)['id'] ?? null;
        if ($id !== null) $this->tokenIds[] = (int) $id;

        // Tentýž token na přiřazenou firmu A → 201
        $res = $this->sessionRequest('POST', '/api/auth/tokens', $session, [
            'name' => '__test_f0_bound_A', 'supplier_id' => $this->supplierA, 'scope' => 'read',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(201, $res->getStatusCode(),
            'Token na přiřazenou firmu se musí vytvořit');
        $id = $this->json($res)['id'] ?? null;
        if ($id !== null) $this->tokenIds[] = (int) $id;
    }

    // ------------------------------------------------------------- fixtures

    /**
     * Heslo se hashuje SKUTEČNÝM {@see PasswordHasher} (včetně pepperu), ne
     * podvrženým řetězcem — od zavedení step-up auth u POST /api/auth/tokens
     * musí jít ověřit, jinak dostane test 401 `invalid_password`.
     */
    private function mkUser(string $role): int
    {
        $email = '__test_f0_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $email,
            (new PasswordHasher($this->config))->hash(self::PASSWORD),
            '__TEST F0',
            $this->roleId($role),
            'cs',
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @param list<array{0:int,1:?string}> $rows [[supplier_id, system_key|null], …] */
    private function assign(int $userId, array $rows): void
    {
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)'
        );
        foreach ($rows as [$sid, $role]) {
            $ins->execute([$userId, $sid, $role !== null ? $this->roleId($role) : null]);
        }
    }

    private function roleId(string $legacy): int
    {
        $key = $legacy === 'admin' ? 'superadmin' : $legacy;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    private function clientCountByEmail(int $supplierId, string $email): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND main_email = ?');
        $stmt->execute([$supplierId, $email]);
        return (int) $stmt->fetchColumn();
    }

    private function mkToken(int $userId): string
    {
        $out = $this->svc->generate($userId, null, '__test_f0_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    /** PAT bound na konkrétní supplier (obchází header/query — testuje membership u bound tokenu). */
    private function mkBoundToken(int $userId, int $supplierId): string
    {
        $out = $this->svc->generate($userId, $supplierId, '__test_f0_bound_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSession(int $userId): array
    {
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f0');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    private function request(
        string $method,
        string $path,
        string $bearer,
        ?int $supplierId,
        ?array $body = null,
    ): ResponseInterface {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    /**
     * Session-based request (cookie + Origin + X-CSRF-Token) — nutné pro
     * /api/admin/* endpointy, které jsou pro bearer PAT zakázané.
     *
     * @param array{token:string, csrf_token:string} $session
     */
    private function sessionRequest(
        string $method,
        string $path,
        array $session,
        ?array $body = null,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
