<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\TechnicalSupplierAccessAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TechnicalSupplierAccessRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\PayrollUsagePolicy;
use MyInvoice\Service\License\SeatPolicy;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class InstanceAdminAccessTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private TechnicalSupplierAccessRepository $access;
    private int $technicalRole;
    private int $technician;
    private int $administrator;
    private int $supplierA;
    private int $supplierB;

    protected function setUp(): void
    {
        $this->config = Config::load(dirname(__DIR__, 3));
        $this->db = new Connection($this->config);
        $pdo = $this->db->pdo();
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $pdo->beginTransaction();
        $this->technicalRole = $this->roleId('instance_admin');
        self::assertGreaterThan(0, $this->technicalRole, 'Migrace 2135 musí být aplikovaná.');
        $this->technician = $this->user($this->technicalRole);
        $this->administrator = $this->user($this->roleId('superadmin'));
        $this->supplierA = $this->supplier('Synthetic technical A');
        $this->supplierB = $this->supplier('Synthetic technical B');
        $this->access = new TechnicalSupplierAccessRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testTechnicalScopeIsLiveSeparateFromMembershipAndFailsClosedWhenInactive(): void
    {
        self::assertSame(['all_suppliers' => false, 'supplier_ids' => []], $this->access->scopeForUser($this->technician));
        self::assertFalse($this->access->allows($this->technician, $this->supplierA));
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->technician, $this->supplierB, $this->roleId('accountant')]);
        self::assertFalse($this->access->allows($this->technician, $this->supplierB));
        $this->access->replaceForUser($this->technician, false, [$this->supplierA]);
        self::assertTrue($this->access->allows($this->technician, $this->supplierA));
        self::assertFalse($this->access->allows($this->technician, $this->supplierB));
        $listed = $this->access->listForUser($this->technician);
        self::assertSame([['supplier_id' => $this->supplierA, 'name' => 'Synthetic technical A']], $listed);
        $this->access->replaceForUser($this->technician, false, [$this->supplierB]);
        self::assertFalse($this->access->allows($this->technician, $this->supplierA));
        self::assertTrue($this->access->allows($this->technician, $this->supplierB));
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->technician]);
        self::assertFalse($this->access->allows($this->technician, $this->supplierB));
        self::assertSame([], $this->access->listForUser($this->technician));
        // Admin musí umět otevřít a změnit rozsah deaktivovaného účtu před jeho reaktivací.
        self::assertSame([$this->supplierB], $this->access->scopeForUser($this->technician)['supplier_ids']);
        $this->access->replaceForUser($this->technician, false, [$this->supplierA]);
        self::assertSame([$this->supplierA], $this->access->scopeForUser($this->technician)['supplier_ids']);
        $action = new TechnicalSupplierAccessAction($this->db, $this->access, new ActivityLogger($this->db));
        $response = $action->get($this->adminRequest('GET', '/'), (new ResponseFactory())->createResponse(), ['id' => $this->technician]);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([['supplier_id' => $this->supplierA, 'name' => 'Synthetic technical A']], $data['suppliers']);
    }

    public function testAllSupplierScopeIncludesFutureCompaniesAndSupportsLookaheadAtHundred(): void
    {
        $this->access->replaceForUser($this->technician, true, []);
        self::assertTrue($this->access->allows($this->technician, $this->supplierA));
        $future = $this->supplier('Synthetic future technical supplier');
        self::assertTrue($this->access->allows($this->technician, $future));
        $ids = [$this->supplierA, $this->supplierB, $future];
        for ($i = 0; $i < 99; $i++) $ids[] = $this->supplier('Synthetic technical page ' . $i);
        $this->access->replaceForUser($this->technician, false, $ids);
        $first = $this->access->listForUser($this->technician, 0, 101);
        self::assertCount(101, $first, 'API potřebuje stý první řádek pro další kurzor.');
        $next = $this->access->listForUser($this->technician, $first[99]['supplier_id'], 101);
        self::assertCount(2, $next);
        self::assertSame($ids, [...array_column(array_slice($first, 0, 100), 'supplier_id'), ...array_column($next, 'supplier_id')]);
    }

    public function testTechnicalUsersNeverOccupyBusinessOrPayrollSeatsEvenWithCorruptGrants(): void
    {
        $pdo = $this->db->pdo();
        $seats = new SeatPolicy($this->db);
        $payroll = new PayrollUsagePolicy($this->db);
        $before = $seats->countActiveSeats();
        $payrollBefore = $payroll->countActiveUsers();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, 'invoices', 2), (?, 'payroll', 2)")
            ->execute([$this->technicalRole, $this->technicalRole]);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$this->technician, $this->supplierA, $this->roleId('accountant')]);
        self::assertFalse($seats->roleGrantsWrite($this->technicalRole));
        self::assertFalse($seats->occupiesSeat($this->technicalRole, [$this->roleId('accountant')]));
        self::assertSame($before, $seats->countActiveSeats());
        self::assertSame($payrollBefore, $payroll->countActiveUsers());
        self::assertTrue($seats->roleGrantsWrite($this->roleId('accountant')));
        $this->user($this->roleId('accountant'));
        self::assertSame($before + 1, $seats->countActiveSeats());
    }

    public function testConversionRevokesOldAccessAtomicallyAndKeepsMfa(): void
    {
        $pdo = $this->db->pdo();
        $target = $this->user($this->roleId('accountant'));
        $pdo->prepare('UPDATE users SET default_supplier_id = ?, totp_enabled = 1, totp_secret = ? WHERE id = ?')
            ->execute([$this->supplierA, 'synthetic-preserved-mfa', $target]);
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)')
            ->execute([$target, $this->supplierA, $this->roleId('accountant')]);
        $session = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sessions (id,user_id,csrf_token,expires_at,ip,user_agent,last_user_activity_at,session_family_id)
            VALUES (?, ?, ?, NOW() + INTERVAL 1 DAY, INET6_ATON('127.0.0.1'), 'synthetic', UTC_TIMESTAMP(6), ?)")
            ->execute([$session, $target, bin2hex(random_bytes(32)), random_bytes(32)]);
        $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, prefix, name) VALUES (?, ?, 'synthetic', 'Synthetic token')")
            ->execute([$target, bin2hex(random_bytes(32))]);
        $license = $this->createStub(LicenseService::class);
        $license->method('current')->willReturn($this->licenseState());
        $capacity = new LicenseCapacityGate($this->db, $license, new SeatPolicy($this->db), new PayrollUsagePolicy($this->db));
        $action = new UserAdminAction($this->db, new ActivityLogger($this->db), new IpMatcher($this->config),
            new PasswordHasher($this->config), new SessionManager($this->db, $this->config, new DatabaseSecurityClock()),
            $capacity, new PasswordSetupLinkIssuer(), $this->createStub(Mailer::class), $this->config);
        $request = $this->adminRequest('PUT', '/')->withParsedBody(['role_id' => $this->technicalRole]);
        $response = $action->update($request, (new ResponseFactory())->createResponse(), ['id' => $target]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $pdo->query('SELECT role, role_id, default_supplier_id, totp_enabled, totp_secret FROM users WHERE id = ' . $target)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('readonly', $row['role']);
        self::assertSame($this->technicalRole, (int) $row['role_id']);
        self::assertNull($row['default_supplier_id']);
        self::assertSame(1, (int) $row['totp_enabled']);
        self::assertSame('synthetic-preserved-mfa', $row['totp_secret']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_suppliers WHERE user_id = ' . $target)->fetchColumn());
        self::assertNotFalse($pdo->query('SELECT revoked_at FROM sessions WHERE user_id = ' . $target)->fetchColumn());
        self::assertNotNull($pdo->query('SELECT revoked_at FROM sessions WHERE user_id = ' . $target)->fetchColumn());
        self::assertNotNull($pdo->query('SELECT revoked_at FROM api_tokens WHERE user_id = ' . $target)->fetchColumn());
        $this->access->replaceForUser($target, false, [$this->supplierA]);
        $back = $action->update($this->adminRequest('PUT', '/')->withParsedBody(['role_id' => $this->roleId('readonly')]),
            (new ResponseFactory())->createResponse(), ['id' => $target]);
        self::assertSame(200, $back->getStatusCode(), (string) $back->getBody());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_technical_suppliers WHERE user_id = ' . $target)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM user_technical_access WHERE user_id = ' . $target)->fetchColumn());
    }

    public function testFullSeatLimitAllowsTechnicalCreationAndActivationButRollsBackBusinessConversion(): void
    {
        $pdo = $this->db->pdo();
        $this->user($this->roleId('accountant'));
        $seats = new SeatPolicy($this->db);
        $before = $seats->countActiveSeats();
        self::assertGreaterThan(0, $before);
        $license = $this->createStub(LicenseService::class);
        $license->method('current')->willReturn($this->licenseState($before));
        $capacity = new LicenseCapacityGate($this->db, $license, $seats, new PayrollUsagePolicy($this->db));
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $action = new UserAdminAction($this->db, new ActivityLogger($this->db), new IpMatcher($this->config),
            new PasswordHasher($this->config), new SessionManager($this->db, $this->config, new DatabaseSecurityClock()),
            $capacity, new PasswordSetupLinkIssuer(), $mailer, $this->config);

        $created = $action->create($this->adminRequest('POST', '/')->withParsedBody([
            'email' => 'technical-capacity-' . bin2hex(random_bytes(6)) . '@example.invalid',
            'name' => 'Synthetic technical capacity', 'role_id' => $this->technicalRole,
            'password' => 'Synthetic-FR224-Password-42!', 'locale' => 'cs',
        ]), (new ResponseFactory())->createResponse());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $target = (int) json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
        self::assertGreaterThan(0, $target);
        self::assertSame($before, $seats->countActiveSeats());
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$target]);
        $activated = $action->update($this->adminRequest('PUT', '/')->withParsedBody(['is_active' => true]),
            (new ResponseFactory())->createResponse(), ['id' => $target]);
        self::assertSame(200, $activated->getStatusCode(), (string) $activated->getBody());
        self::assertSame($before, $seats->countActiveSeats());

        $this->access->replaceForUser($target, false, [$this->supplierA]);
        $session = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sessions (id,user_id,csrf_token,expires_at,ip,user_agent,last_user_activity_at,session_family_id)
            VALUES (?, ?, ?, NOW() + INTERVAL 1 DAY, INET6_ATON('127.0.0.1'), 'synthetic', UTC_TIMESTAMP(6), ?)")
            ->execute([$session, $target, bin2hex(random_bytes(32)), random_bytes(32)]);
        $blocked = $action->update($this->adminRequest('PUT', '/')->withParsedBody(['role_id' => $this->roleId('accountant')]),
            (new ResponseFactory())->createResponse(), ['id' => $target]);
        self::assertSame(403, $blocked->getStatusCode(), (string) $blocked->getBody());
        $error = json_decode((string) $blocked->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('license_user_limit', $error['error']['code']);
        self::assertSame($before, $seats->countActiveSeats());
        $stmt = $pdo->prepare('SELECT role_id, is_active FROM users WHERE id = ?');
        $stmt->execute([$target]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->technicalRole, (int) $user['role_id']);
        self::assertSame(1, (int) $user['is_active']);
        self::assertSame(['all_suppliers' => false, 'supplier_ids' => [$this->supplierA]], $this->access->scopeForUser($target));
        $stmt = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$session, $target]);
        self::assertNull($stmt->fetchColumn(), 'Zamítnutá konverze nesmí zrušit dosavadní přihlášení.');
    }

    private function roleId(string $key): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    private function user(int $roleId): int
    {
        $this->db->pdo()->prepare("INSERT INTO users (email,password_hash,name,role,role_id) VALUES (?, ?, 'Synthetic technician', 'readonly', ?)")
            ->execute(['technical-' . bin2hex(random_bytes(6)) . '@example.invalid', str_repeat('x', 60), $roleId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query('SELECT MIN(id) FROM currencies')->fetchColumn();
        if ($currency === 0) $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id)
                VALUES (?, '', '', '', (SELECT MIN(id) FROM countries), 'technical@example.invalid', ?, (SELECT MIN(id) FROM vat_rates))")
                ->execute([$name, $currency]);
            $supplierId = (int) $pdo->lastInsertId();
            if ($currency === 0) {
                $pdo->prepare("INSERT INTO currencies (supplier_id,code,label,name_cs,name_en,symbol) VALUES (?, 'CZK', 'Synthetic CZK', 'Koruna', 'Crown', 'Kč')")
                    ->execute([$supplierId]);
                $currency = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $supplierId]);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $supplierId;
    }

    private function adminRequest(string $method, string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->administrator, 'is_superadmin' => true])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
    }

    private function licenseState(int $usersLicensed = 100): LicenseState
    {
        return new LicenseState(state: LicenseState::ACTIVE, instanceId: 'synthetic', tier: 'team', maxCompanies: null,
            usersLicensed: $usersLicensed, usersActive: 0, companiesActive: 0, validUntil: time() + 86400, trialEndsAt: null,
            overageDeadline: null, licenseKey: null, lastCheckAt: null, lastCheckOk: true);
    }
}
