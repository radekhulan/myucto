<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\Admin\RoleAdminAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Admin\UserSupplierAdminAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RoleRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Licenční limity (E4) na úrovni Action tříd:
 *  - seat limit: UserAdminAction::create blokuje nového provozního uživatele nad
 *    počet míst; bez business WRITE se role nepočítá; trial je bez limitu.
 *  - max_companies: SettingsAction::createSupplier blokuje nad limit; null (unlimited)
 *    i trial projdou.
 *  - LicenseService::countActiveUsers() — počítací dotaz vč. JOIN na roles.
 */
#[Group('integration')]
final class LicenseLimitsTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseService $service;
    private UserAdminAction $userAdmin;
    private UserSupplierAdminAction $userSuppliers;
    private RoleAdminAction $roleAdmin;
    private RoleRepository $roles;
    private SettingsAction $settings;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            if (!$this->db->ping() || !$this->db->hasTable('license')) {
                $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
            }
            // Vstříkni LicenseService s testovacím veřejným klíčem, ať jdou podepsat tokeny.
            $this->service = new LicenseService(
                $this->db,
                new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
                new LicenseTokenVerifier(),
                $this->createStub(LicenseClient::class),
            );
            $container->set(LicenseService::class, $this->service);
            $this->userAdmin = $container->get(UserAdminAction::class);
            $this->userSuppliers = $container->get(UserSupplierAdminAction::class);
            $this->roleAdmin = $container->get(RoleAdminAction::class);
            $this->roles = $container->get(RoleRepository::class);
            $this->settings  = $container->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
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

    // ── countActiveUsers: JOIN na roles ──────────────────────────────────────

    public function testCountActiveUsersUsesWritePermissionRegardlessOfRoleType(): void
    {
        $roles = $this->roleIds();
        $baseline = $this->service->countActiveUsers();

        // Readonly má jen self-service zápis a místo nezabírá.
        $this->insertUser('readonly', $roles['readonly']);
        self::assertSame($baseline, $this->service->countActiveUsers(), 'Readonly role nezabírá místo.');

        // Systémová client role má business WRITE a typ role ji z licence nevyjímá.
        $this->insertUser('client', $roles['client']);
        self::assertSame($baseline + 1, $this->service->countActiveUsers(), 'Zapisující client role zabírá místo.');

        // accountant (provozní staff role) místo zabírá.
        $this->insertUser('accountant', $roles['accountant']);
        self::assertSame($baseline + 2, $this->service->countActiveUsers(), 'Accountant zabírá licenční místo.');

        // Deaktivovaný uživatel se nepočítá.
        $this->insertUser('accountant', $roles['accountant'], active: false);
        self::assertSame($baseline + 2, $this->service->countActiveUsers(), 'Neaktivní uživatel se nepočítá.');
    }

    // ── seat limit přes UserAdminAction::create ─────────────────────────────

    public function testCreateUserBlockedOverSeatLimit(): void
    {
        $roles = $this->roleIds();
        // Token licencuje přesně tolik míst, kolik jich je obsazeno → žádné volné.
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        $resp = $this->createUser($roles['accountant']);

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('license_user_limit', $this->error($resp));
    }

    public function testReadonlyRoleNotSubjectToSeatLimit(): void
    {
        $roles = $this->roleIds();
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        // readonly role licenční kontrolu přeskočí → propadne až na validaci hesla (400).
        $resp = $this->createUser($roles['readonly'], password: 'x');

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    public function testWritingClientRoleIsSubjectToSeatLimit(): void
    {
        $roles = $this->roleIds();
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        $resp = $this->createUser($roles['client']);

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('license_user_limit', $this->error($resp));
    }

    public function testTrialHasNoSeatLimit(): void
    {
        $this->trialLicense();
        $roles = $this->roleIds();

        // Trial → bez limitu; provozní role projde licenční branou (padne až na hesle).
        $resp = $this->createUser($roles['accountant'], password: 'x');

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    public function testActivationWithWritingOverrideIsBlockedAndRolledBack(): void
    {
        $roles = $this->roleIds();
        $userId = $this->insertUser('readonly', $roles['readonly'], active: false);
        $supplierId = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)'
        )->execute([$userId, $supplierId, $roles['accountant']]);
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        $resp = $this->updateUser($userId, ['is_active' => true]);

        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('license_user_limit', $this->error($resp));
        self::assertSame(0, (int) $this->db->pdo()->query('SELECT is_active FROM users WHERE id = ' . $userId)->fetchColumn());
    }

    public function testWritingOverrideForActiveUserIsBlockedAndRolledBack(): void
    {
        $roles = $this->roleIds();
        $userId = $this->insertUser('readonly', $roles['readonly']);
        $supplierId = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        $resp = $this->replaceAssignments($userId, $supplierId, $roles['accountant']);

        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('license_user_limit', $this->error($resp));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM user_suppliers WHERE user_id = ?');
        $stmt->execute([$userId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Zakázaná override role se musí rollbacknout.');
    }

    public function testWritingOverrideForInactiveUserDoesNotConsumeSeat(): void
    {
        $roles = $this->roleIds();
        $userId = $this->insertUser('readonly', $roles['readonly'], active: false);
        $supplierId = (int) $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $before = $this->service->countActiveUsers();
        $this->licenseWithToken($this->token(['users' => $before]));

        $resp = $this->replaceAssignments($userId, $supplierId, $roles['accountant']);

        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame($before, $this->service->countActiveUsers());
    }

    public function testAddingWriteToUsedRoleIsBlockedAndRolledBack(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, 'staff', 1)")
            ->execute(['Capacity role ' . bin2hex(random_bytes(4))]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'invoices', 1)")
            ->execute([$roleId]);
        $this->insertUser('readonly', $roleId);
        $this->insertUser('readonly', $roleId);
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers() + 1]));
        $role = $this->roles->find($roleId);
        self::assertIsArray($role);

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/admin/roles/' . $roleId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody([
                'name' => $role['name'],
                'is_active' => true,
                'permissions' => ['invoices' => 2],
                'revision' => $role['updated_at'],
            ]);
        $resp = $this->roleAdmin->update($request, new Psr7Response(), ['id' => (string) $roleId]);

        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('license_user_limit', $this->error($resp));
        $level = $pdo->query(
            "SELECT access_level FROM role_permissions WHERE role_id = {$roleId} AND permission_key = 'invoices'"
        )->fetchColumn();
        self::assertSame(1, (int) $level, 'Oprávnění používané role se musí rollbacknout.');
    }

    public function testActivatingUsedWritingRoleIsBlockedAndRolledBack(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO roles (system_key, name, role_type, is_active) VALUES (NULL, ?, 'staff', 0)")
            ->execute(['Inactive capacity role ' . bin2hex(random_bytes(4))]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'invoices', 2)")
            ->execute([$roleId]);
        $this->insertUser('readonly', $roleId);
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));
        $role = $this->roles->find($roleId);
        self::assertIsArray($role);

        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/admin/roles/' . $roleId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody([
                'name' => $role['name'],
                'is_active' => true,
                'permissions' => ['invoices' => 2],
                'revision' => $role['updated_at'],
            ]);
        $resp = $this->roleAdmin->update($request, new Psr7Response(), ['id' => (string) $roleId]);

        self::assertSame(403, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame('license_user_limit', $this->error($resp));
        self::assertSame(0, (int) $pdo->query('SELECT is_active FROM roles WHERE id = ' . $roleId)->fetchColumn());
    }

    // ── max_companies přes SettingsAction::createSupplier ────────────────────

    public function testCreateSupplierBlockedOverCompanyLimit(): void
    {
        $companies = $this->companyCount();
        $this->licenseWithToken($this->token(['max_companies' => $companies])); // obsazeno na doraz

        $resp = $this->createSupplier(valid: true);

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('license_company_limit', $this->error($resp));
    }

    public function testUnlimitedCompaniesCanBeCreatedThroughAtomicGate(): void
    {
        $this->licenseWithToken($this->token(['max_companies' => null])); // null = neomezeno
        $before = $this->companyCount();

        $resp = $this->createSupplier(valid: true);

        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame($before + 1, $this->companyCount());
    }

    public function testAdminPlusGetsAccessAndBankTemplatesForCreatedCompany(): void
    {
        $this->licenseWithToken($this->token(['max_companies' => null]));
        $roles = $this->roleIds();
        $userId = $this->insertUser('accountant', $roles['admin_plus']);
        $adminPlus = new EffectiveRole(
            $roles['admin_plus'],
            'Admin Plus',
            'staff',
            true,
            ['settings.company.write' => 2],
            'admin_plus',
        );

        $resp = $this->createSupplier(valid: true, role: $adminPlus, userId: $userId);

        self::assertSame(201, $resp->getStatusCode(), (string) $resp->getBody());
        $supplierId = (int) (json_decode((string) $resp->getBody(), true)['id'] ?? 0);
        self::assertGreaterThan(0, $supplierId);
        $membership = $this->db->pdo()->prepare(
            'SELECT role_id FROM user_suppliers WHERE user_id = ? AND supplier_id = ?'
        );
        $membership->execute([$userId, $supplierId]);
        self::assertSame([['role_id' => null]], $membership->fetchAll(\PDO::FETCH_ASSOC));

        $defaults = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_rule_template_defaults')->fetchColumn();
        $templates = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_rule_templates WHERE supplier_id = ?');
        $templates->execute([$supplierId]);
        self::assertGreaterThan(0, $defaults);
        self::assertSame($defaults, (int) $templates->fetchColumn());
        self::assertTrue($adminPlus->isCompanyAdmin());
    }

    public function testTrialHasNoCompanyLimit(): void
    {
        $this->trialLicense();

        $resp = $this->createSupplier();

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createUser(int $roleId, string $password = 'Sup3rSecret!123'): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/users')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody([
                'email'    => 'seat_' . uniqid('', true) . '@example.test',
                'name'     => 'Seat Test',
                'role_id'  => $roleId,
                'locale'   => 'cs',
                'password' => $password,
            ]);

        return $this->userAdmin->create($request, new Psr7Response());
    }

    /** @param array<string,mixed> $body */
    private function updateUser(int $userId, array $body): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/admin/users/' . $userId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody($body);

        return $this->userAdmin->update($request, new Psr7Response(), ['id' => (string) $userId]);
    }

    private function replaceAssignments(int $userId, int $supplierId, int $roleId): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/admin/users/' . $userId . '/suppliers')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody(['assignments' => [['supplier_id' => $supplierId, 'role_id' => $roleId]]]);

        return $this->userSuppliers->replace($request, new Psr7Response(), ['id' => (string) $userId]);
    }

    private function createSupplier(
        bool $valid = false,
        ?EffectiveRole $role = null,
        int $userId = 1,
    ): Psr7Response
    {
        $body = $valid ? [
            'company_name' => 'License Gate s.r.o.',
            'street' => 'Testovací 1',
            'city' => 'Praha',
            'zip' => '10000',
            'email' => 'license-gate@example.test',
        ] : [];
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/suppliers')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => $role === null ? 'admin' : 'accountant'])
            ->withParsedBody($body);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }

        return $this->settings->createSupplier($request, new Psr7Response());
    }

    private function insertUser(string $legacyRole, int $roleId, bool $active = true): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'lic_' . uniqid('', true) . '@example.test',
            str_repeat('a', 60),
            'License Fixture',
            $legacyRole,
            $roleId,
            'cs',
            $active ? 1 : 0,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{accountant:int,readonly:int,client:int,admin_plus:int} */
    private function roleIds(): array
    {
        $pdo = $this->db->pdo();
        $ids = [];
        foreach (['accountant', 'readonly', 'client', 'admin_plus'] as $key) {
            $stmt = $pdo->prepare('SELECT id FROM roles WHERE system_key = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$key]);
            $id = (int) $stmt->fetchColumn();
            if ($id === 0) {
                $this->markTestSkipped("Systémová role '{$key}' v test DB chybí.");
            }
            $ids[$key] = $id;
        }
        /** @var array{accountant:int,readonly:int,client:int,admin_plus:int} $ids */
        return $ids;
    }

    private function companyCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
    }

    private function licenseWithToken(string $token): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, token_payload = NULL WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $token]);
    }

    private function trialLicense(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL,
                    trial_started_at = NOW() WHERE id = 1'
        )->execute();
    }

    private function error(Psr7Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }
}
