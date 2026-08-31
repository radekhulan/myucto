<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

#[Group('integration')]
final class RbacHttpRoleMatrixTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $roleIds = [];
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $emailProfileIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->supplierId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0 || $this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Chybí supplier nebo dynamické role.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->sessionTokens as $token) {
            try { $this->sessions->destroy($token); } catch (\Throwable) {}
        }
        if ($this->emailProfileIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->emailProfileIds), '?'));
            $pdo->prepare("DELETE FROM email_profiles WHERE id IN ({$placeholders})")->execute($this->emailProfileIds);
        }
        if ($this->clientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->clientIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ({$placeholders})")->execute($this->clientIds);
        }
        if ($this->userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ({$placeholders})")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ({$placeholders})")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($this->userIds);
        }
        if ($this->roleIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->roleIds), '?'));
            $pdo->prepare("DELETE FROM roles WHERE id IN ({$placeholders})")->execute($this->roleIds);
        }
        $this->db->close();
    }

    /** @return iterable<string, array{string,string,int,bool}> */
    public static function roleMatrix(): iterable
    {
        yield 'superadmin' => ['superadmin', 'superadmin', 2, true];
        yield 'staff read' => ['staff_read', 'staff', 1, true];
        yield 'staff write' => ['staff_write', 'staff', 2, true];
        yield 'client read' => ['client_read', 'client', 1, true];
        yield 'client write' => ['client_write', 'client', 2, true];
        yield 'without supplier' => ['no_supplier', 'staff', 2, false];
    }

    #[DataProvider('roleMatrix')]
    public function testRealHttpAuthorizationMatrix(
        string $variant,
        string $roleType,
        int $level,
        bool $hasSupplier,
    ): void {
        $userId = $this->createUser($variant, $roleType, $level, $hasSupplier);
        $session = $this->createSession($userId);
        $isSuperadmin = $variant === 'superadmin';
        $tenantBaseAllowed = $hasSupplier || $isSuperadmin;
        $staffModuleAllowed = $tenantBaseAllowed && ($roleType === 'staff' || $isSuperadmin);
        $clientModuleAllowed = $tenantBaseAllowed;
        $writeAllowed = $tenantBaseAllowed && $level === 2;
        $staffSettingsWriteAllowed = $tenantBaseAllowed
            && $level === 2
            && ($roleType === 'staff' || $isSuperadmin);

        foreach ([
            ['GET', '/api/invoices', null, $clientModuleAllowed],
            ['GET', '/api/purchase-invoices', null, $clientModuleAllowed],
            ['GET', '/api/documents', null, $staffModuleAllowed],
            ['GET', '/api/bank-statements', null, $staffModuleAllowed],
            ['GET', '/api/accounting/journal', null, $staffModuleAllowed],
            ['GET', '/api/reports/dph-book?year=2025', null, $staffModuleAllowed],
            ['GET', '/api/settings/supplier', null, $clientModuleAllowed],
            ['GET', '/api/settings/client/email-profiles', null, $writeAllowed],
            ['GET', '/api/settings/client/branding', null, $writeAllowed],
            ['PUT', '/api/settings/supplier', [], $staffSettingsWriteAllowed],
            ['POST', '/api/invoices/999999/send', [], $writeAllowed],
            ['POST', '/api/purchase-invoices', [], $writeAllowed],
        ] as [$method, $path, $body, $allowed]) {
            $response = $this->request($method, $path, $session, $body);
            $this->assertPermissionDecision($response, $allowed, "{$variant}: {$method} {$path}");
        }

        $adminResponse = $this->request('GET', '/api/admin/roles', $session);
        $this->assertPermissionDecision($adminResponse, $isSuperadmin, "{$variant}: superadmin-only endpoint");

        $email = '__test_rbac_matrix_' . bin2hex(random_bytes(5)) . '@example.test';
        $before = $this->clientCount($email);
        $create = $this->request('POST', '/api/clients', $session, [
            'company_name' => '__TEST RBAC matrix', 'street' => 'Testovací 1',
            'city' => 'Praha', 'zip' => '11000', 'main_email' => $email,
        ]);
        $this->assertPermissionDecision($create, $writeAllowed, "{$variant}: POST /api/clients");
        if ($writeAllowed) {
            $id = (int) ($this->json($create)['id'] ?? 0);
            if ($id > 0) $this->clientIds[] = $id;
        } else {
            self::assertSame($before, $this->clientCount($email), 'Zamítnutá mutace nesmí změnit DB.');
        }
    }

    public function testClientCompanyWriteUsesOnlyTheOperationalSettingsAllowlist(): void
    {
        $readerId = $this->createUser('client_operational_settings_read', 'client', 1, true);
        $readerSession = $this->createSession($readerId);
        $readerProfiles = $this->request('GET', '/api/settings/branding-profiles', $readerSession);
        self::assertSame(403, $readerProfiles->getStatusCode());
        self::assertSame('forbidden', $this->json($readerProfiles)['error']['code'] ?? null);

        $userId = $this->createUser('client_operational_settings', 'client', 2, true);
        $session = $this->createSession($userId);
        $secret = '__TEST_SMTP_SECRET_' . bin2hex(random_bytes(8));
        $code = 'client-' . bin2hex(random_bytes(5));

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client delegated profile',
            'code' => $code,
            'from_email' => 'delegated@example.test',
            'transport_type' => 'global',
            'smtp_password' => $secret,
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdBody = $this->json($created);
        $profileId = (int) ($createdBody['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);
        $this->emailProfileIds[] = $profileId;
        self::assertArrayNotHasKey('smtp_password', $createdBody);
        self::assertArrayNotHasKey('imap_password', $createdBody);
        self::assertArrayNotHasKey('signing_profile_id', $createdBody);
        self::assertTrue($createdBody['client_manageable'] ?? false);

        $audit = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT 1'
        );
        $audit->execute([$userId, 'email_profile.created']);
        self::assertStringNotContainsString($secret, (string) $audit->fetchColumn());

        $sendmail = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST forbidden sendmail',
            'code' => $code . '-sendmail',
            'from_email' => 'delegated@example.test',
            'transport_type' => 'sendmail',
            'sendmail_command' => '/usr/sbin/sendmail -bs',
        ]);
        self::assertSame(403, $sendmail->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($sendmail)['error']['code'] ?? null);

        $broad = $this->request('PUT', '/api/settings/supplier', $session, [
            'company_name' => '__TEST forbidden legal change',
        ]);
        self::assertSame(403, $broad->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($broad)['error']['code'] ?? null);

        $brandingMassAssignment = $this->request('PUT', '/api/settings/client/branding', $session, [
            'company_name' => '__TEST forbidden branding change',
        ]);
        self::assertSame(403, $brandingMassAssignment->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($brandingMassAssignment)['error']['code'] ?? null);
    }

    /**
     * Deaktivovaný odesílací profil nesmí zablokovat úpravu jiného pole
     * brandingového profilu. Editor posílá profil zpátky celý a nabídka je
     * filtrovaná na aktivní, takže by to nešlo spravit ani ručně.
     */
    public function testStaleEmailProfileLinkDoesNotBlockUnrelatedBrandingEdits(): void
    {
        $userId = $this->createUser('staff_branding_stale', 'staff', 2, true);
        $session = $this->createSession($userId);
        $pdo = $this->db->pdo();

        $flag = $pdo->prepare('SELECT branding_profiles_enabled FROM supplier WHERE id = ?');
        $flag->execute([$this->supplierId]);
        $previousFlag = (int) $flag->fetchColumn();
        $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);

        $brandingId = null;
        try {
            $email = $this->request('POST', '/api/settings/email-profiles', $session, [
                'name' => '__TEST branding link source',
                'code' => 'branding-link-' . bin2hex(random_bytes(5)),
                'from_email' => 'branding-link@example.test',
                'transport_type' => 'global',
                'is_default' => false,
                'is_active' => true,
            ]);
            self::assertSame(201, $email->getStatusCode(), (string) $email->getBody());
            $emailProfileId = (int) ($this->json($email)['id'] ?? 0);
            $this->emailProfileIds[] = $emailProfileId;

            $branding = $this->request('POST', '/api/settings/branding-profiles', $session, [
                'name' => '__TEST branding stale link',
                'code' => 'brand-stale-' . bin2hex(random_bytes(5)),
                'email_profile_id' => $emailProfileId,
            ]);
            self::assertSame(201, $branding->getStatusCode(), (string) $branding->getBody());
            $brandingId = (int) ($this->json($branding)['id'] ?? 0);
            self::assertGreaterThan(0, $brandingId);

            $pdo->prepare('UPDATE email_profiles SET is_active = 0 WHERE id = ?')->execute([$emailProfileId]);

            $kept = $this->request('PUT', "/api/settings/branding-profiles/{$brandingId}", $session, [
                'name' => '__TEST branding stale link renamed',
                'email_profile_id' => $emailProfileId,
            ]);
            self::assertSame(200, $kept->getStatusCode(), (string) $kept->getBody());
            self::assertSame('__TEST branding stale link renamed', $this->json($kept)['name'] ?? null);

            // Přepnout vazbu na JINÝ neaktivní profil se ale dál nesmí.
            $moved = $this->request('PUT', "/api/settings/branding-profiles/{$brandingId}", $session, [
                'email_profile_id' => $emailProfileId + 100000,
            ]);
            self::assertSame(400, $moved->getStatusCode());
            self::assertSame('validation_failed', $this->json($moved)['error']['code'] ?? null);
        } finally {
            if ($brandingId !== null && $brandingId > 0) {
                $pdo->prepare('DELETE FROM branding_profiles WHERE id = ?')->execute([$brandingId]);
            }
            $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = ? WHERE id = ?')
                ->execute([$previousFlag, $this->supplierId]);
        }
    }

    /**
     * Zákaz editace staff profilu musí platit i „zvenčí": klient si nesmí
     * vlastním profilem s `is_default` sesadit profil s S/MIME nebo sendmailem.
     */
    public function testClientCannotTakeOverTheDefaultFromASigningProfile(): void
    {
        $userId = $this->createUser('client_default_takeover', 'client', 2, true);
        $session = $this->createSession($userId);

        $this->createStaffOnlyDefaultProfile();

        $takeover = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client takeover',
            'code' => 'client-takeover-' . bin2hex(random_bytes(5)),
            'from_email' => 'takeover@example.test',
            'transport_type' => 'global',
            'is_default' => true,
            'is_active' => true,
        ]);
        self::assertSame(403, $takeover->getStatusCode());
        self::assertSame('profile_not_delegable', $this->json($takeover)['error']['code'] ?? null);

        // Bez `is_default` je založení profilu v pořádku — omezení míří na
        // převzetí výchozí pozice, ne na samotné zakládání.
        $plain = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client secondary',
            'code' => 'client-secondary-' . bin2hex(random_bytes(5)),
            'from_email' => 'secondary@example.test',
            'transport_type' => 'global',
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $plain->getStatusCode(), (string) $plain->getBody());
        $this->emailProfileIds[] = (int) ($this->json($plain)['id'] ?? 0);
    }

    /**
     * Prázdné `signing_profile_id` je round-trip celého profilu, ne pokus
     * o delegaci podpisu — API ho nesmí odmítat.
     */
    public function testClientMayRoundTripAnEmptySigningProfileField(): void
    {
        $userId = $this->createUser('client_signing_roundtrip', 'client', 2, true);
        $session = $this->createSession($userId);

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client roundtrip',
            'code' => 'client-roundtrip-' . bin2hex(random_bytes(5)),
            'from_email' => 'roundtrip@example.test',
            'transport_type' => 'global',
            'signing_profile_id' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->emailProfileIds[] = (int) ($this->json($created)['id'] ?? 0);

        $withValue = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client signing attempt',
            'code' => 'client-signing-' . bin2hex(random_bytes(5)),
            'from_email' => 'signing@example.test',
            'transport_type' => 'global',
            'signing_profile_id' => 1,
        ]);
        self::assertSame(403, $withValue->getStatusCode());
        self::assertSame('field_not_delegable', $this->json($withValue)['error']['code'] ?? null);
    }

    /**
     * Uložené SMTP heslo API nevrací — a nesmí ho jít ani „vytáhnout" tím, že si
     * klient přepíše host na vlastní server a pole hesla nechá prázdné.
     */
    public function testClientCannotForwardStoredSmtpSecretToItsOwnServer(): void
    {
        $userId = $this->createUser('client_secret_forward', 'client', 2, true);
        $session = $this->createSession($userId);
        $code = 'client-fwd-' . bin2hex(random_bytes(5));

        $created = $this->request('POST', '/api/settings/client/email-profiles', $session, [
            'name' => '__TEST client smtp profile',
            'code' => $code,
            'from_email' => 'forward@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp-legit.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '__TEST_SMTP_SECRET_' . bin2hex(random_bytes(8)),
            'is_default' => false,
            'is_active' => true,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $profileId = (int) ($this->json($created)['id'] ?? 0);
        self::assertGreaterThan(0, $profileId);
        $this->emailProfileIds[] = $profileId;

        $redirectedSave = $this->request('PUT', "/api/settings/client/email-profiles/{$profileId}", $session, [
            'transport_type' => 'smtp',
            'smtp_host' => 'attacker.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '',
        ]);
        self::assertSame(400, $redirectedSave->getStatusCode());
        self::assertSame('validation_failed', $this->json($redirectedSave)['error']['code'] ?? null);

        $redirectedTest = $this->request('POST', '/api/settings/client/email-profiles/test', $session, [
            'id' => $profileId,
            'name' => '__TEST redirected draft',
            'code' => $code . '-draft',
            'from_email' => 'forward@example.test',
            'transport_type' => 'smtp',
            'smtp_host' => 'attacker.example.test',
            'smtp_auth_enabled' => true,
            'smtp_username' => 'legit-user',
            'smtp_password' => '',
        ]);
        self::assertSame(400, $redirectedTest->getStatusCode());
        self::assertSame('validation_failed', $this->json($redirectedTest)['error']['code'] ?? null);

        $stored = $this->request('GET', '/api/settings/client/email-profiles', $session);
        self::assertSame(200, $stored->getStatusCode());
        $row = null;
        foreach ($this->json($stored) as $profile) {
            if (is_array($profile) && (int) ($profile['id'] ?? 0) === $profileId) $row = $profile;
        }
        self::assertIsArray($row);
        self::assertSame('smtp-legit.example.test', $row['smtp_host'] ?? null);
        self::assertArrayNotHasKey('smtp_password', $row);
        self::assertTrue($row['has_smtp_password'] ?? false);
    }

    /**
     * Výchozí profil, který klient spravovat nesmí. Přednost má vazba na
     * S/MIME; bez podpisového profilu v instalaci zastoupí systémový sendmail,
     * který spadá pod stejnou pojistku ({@see EmailProfilesAction}).
     */
    private function createStaffOnlyDefaultProfile(): int
    {
        $pdo = $this->db->pdo();
        $signing = $pdo->prepare('SELECT id FROM signing_profiles WHERE supplier_id = ? LIMIT 1');
        $signing->execute([$this->supplierId]);
        $signingId = $signing->fetchColumn();

        $pdo->prepare('UPDATE email_profiles SET is_default = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $insert = $pdo->prepare(
            'INSERT INTO email_profiles
                (supplier_id, name, code, from_email, signing_profile_id, transport_type, sendmail_command, is_default, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $insert->execute([
            $this->supplierId,
            '__TEST staff only default',
            'staff-only-' . bin2hex(random_bytes(5)),
            'staff-only@example.test',
            $signingId === false ? null : (int) $signingId,
            $signingId === false ? 'sendmail' : 'global',
            $signingId === false ? '/usr/sbin/sendmail -bs' : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->emailProfileIds[] = $id;
        return $id;
    }

    private function createUser(string $variant, string $roleType, int $level, bool $hasSupplier): int
    {
        if ($variant === 'superadmin') {
            $roleId = (int) $this->db->pdo()->query("SELECT id FROM roles WHERE system_key = 'superadmin'")->fetchColumn();
        } else {
            $stmt = $this->db->pdo()->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)');
            $stmt->execute(['__TEST RBAC ' . $variant . ' ' . bin2hex(random_bytes(3)), $roleType]);
            $roleId = (int) $this->db->pdo()->lastInsertId();
            $this->roleIds[] = $roleId;
            $keys = $roleType === 'client'
                ? ['clients', 'clients.create', 'invoices', 'invoices.send', 'purchase_invoices', 'purchase_invoices.create', 'settings.company']
                : ['clients', 'clients.create', 'invoices', 'invoices.send', 'purchase_invoices', 'purchase_invoices.create', 'documents', 'bank', 'accounting', 'reports', 'settings.company', 'settings.company.write', 'settings.branding'];
            $insert = $this->db->pdo()->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
            foreach ($keys as $key) $insert->execute([$roleId, $key, $level]);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            '__test_rbac_matrix_' . bin2hex(random_bytes(6)) . '@example.test',
            '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
            '__TEST RBAC matrix',
            $roleType === 'client' ? 'client' : ($variant === 'superadmin' ? 'admin' : 'readonly'),
            $roleId,
            'cs',
        ]);
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $userId;
        if ($hasSupplier && !$this->isSuperadminRole($roleId)) {
            $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')
                ->execute([$userId, $this->supplierId]);
        }
        return $userId;
    }

    private function isSuperadminRole(int $roleId): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM roles WHERE id = ? AND system_key = 'superadmin'");
        $stmt->execute([$roleId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array{token:string,csrf_token:string} */
    private function createSession(int $userId): array
    {
        $session = $this->sessions->create($userId, '127.0.0.1', '__test_rbac_matrix');
        $this->sessionTokens[] = (string) $session['token'];
        return ['token' => (string) $session['token'], 'csrf_token' => (string) $session['csrf_token']];
    }

    private function request(string $method, string $path, array $session, ?array $body = null): ResponseInterface
    {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', rtrim((string) $this->config->get('app.url', ''), '/'))
            ->withHeader('X-CSRF-Token', $session['csrf_token'])
            ->withHeader('X-Supplier-Id', (string) $this->supplierId);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(
                (new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)),
            );
        }
        return ($this->app ??= Bootstrap::buildApp())->handle($request);
    }

    private function assertPermissionDecision(ResponseInterface $response, bool $allowed, string $message): void
    {
        $code = $this->json($response)['error']['code'] ?? null;
        if ($allowed) {
            self::assertNotContains($code, ['forbidden_permission', 'forbidden_supplier'], $message . ': ' . (string) $response->getBody());
            return;
        }
        self::assertSame(403, $response->getStatusCode(), $message);
        self::assertContains($code, ['forbidden_permission', 'forbidden_supplier'], $message);
    }

    private function clientCount(string $email): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND main_email = ?');
        $stmt->execute([$this->supplierId, $email]);
        return (int) $stmt->fetchColumn();
    }

    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
