<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
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
 * Epic F6 — admin správa uživatelů s rolí client (§8.4 specu):
 *   - create/update s role='client' projde (UserAdminAction rozšířený výčet rolí)
 *   - UserSupplierAdminAction: non-NULL role override pro client uživatele → 400,
 *     membership klienta se zakládá s role = NULL
 *   - create bez role → vznikne 'readonly' (API default i DB DEFAULT — změna M3)
 */
#[Group('integration')]
final class UserAdminTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $supplierA = 0;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];
    /** @var list<int> */
    private array $templateIds = [];
    /** @var list<int> */
    private array $credentialIds = [];
    /** @var list<int> */
    private array $cardIds = [];

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
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        if ($this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }
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

        foreach ($this->templateIds as $id) {
            $pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
        }
        foreach ($this->credentialIds as $id) {
            $pdo->prepare('DELETE FROM epo_signing_credentials WHERE id = ?')->execute([$id]);
        }
        foreach ($this->cardIds as $id) {
            $pdo->prepare('DELETE FROM payment_cards WHERE id = ?')->execute([$id]);
        }

        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM activity_log WHERE entity_type = 'user' AND entity_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }

        $this->userIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testCreateAndUpdateClientUser(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);

        $email = $this->mkEmail();
        $res = $this->sessionRequest('POST', '/api/admin/users', $session, [
            'email'    => $email,
            'name'     => '__TEST F6 client',
            'role_id'  => $this->roleId('client'),
            'locale'   => 'cs',
            'password' => 'SuperTajneHeslo123',
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->json($res);
        self::assertSame('client', $body['role']['type'] ?? null);
        $id = (int) ($body['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->userIds[] = $id;

        // Update client → accountant → client (obě strany výčtu)
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => $this->roleId('accountant')]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('staff', $this->json($res)['role']['type'] ?? null);

        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => $this->roleId('client')]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('client', $this->json($res)['role']['type'] ?? null);

        // Neplatná role dál padá
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => 999999999]);
        self::assertSame(400, $res->getStatusCode());
        self::assertSame('validation_failed', $this->json($res)['error']['code'] ?? null);
    }

    public function testSupplierRoleOverrideForClientUserRejected(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $client = $this->mkUser('client');

        // non-NULL role v assignments pro client uživatele → 400 validation_failed
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $client . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('accountant')]],
        ]);
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('role_type_mismatch', $this->json($res)['error']['code'] ?? null);

        // role NULL projde — membership klienta se zakládá se zděděnou (NULL) rolí
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $client . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => null]],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $rows = $this->json($res);
        self::assertCount(1, $rows);
        self::assertSame($this->supplierA, (int) ($rows[0]['supplier_id'] ?? 0));
        self::assertArrayHasKey('role_id', $rows[0]);
        self::assertNull($rows[0]['role_id']);

        // Pro legacy roli override dál funguje (ROLES beze změny)
        $readonly = $this->mkUser('readonly');
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $readonly . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('accountant')]],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $rows = $this->json($res);
        self::assertSame($this->roleId('accountant'), $rows[0]['role_id'] ?? null);
    }

    public function testCreateWithoutRoleIsRejected(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);

        // API create bez role → 'readonly' (UserAdminAction posílá roli vždy explicitně, M3)
        $res = $this->sessionRequest('POST', '/api/admin/users', $session, [
            'email'    => $this->mkEmail(),
            'name'     => '__TEST F6 norole',
            'password' => 'SuperTajneHeslo123',
        ]);
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
    }

    public function testAdminPasswordChangeClearsOnlyPendingTotpEnrollment(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $pending = $this->mkUser('readonly');
        $active = $this->mkUser('readonly');
        $this->db->pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?')
            ->execute(['enc:PENDING', 0, $pending]);
        $this->db->pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?')
            ->execute(['enc:ACTIVE', 1, $active]);

        foreach ([$pending, $active] as $id) {
            $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, [
                'password' => 'New-synthetic-password-42',
            ]);
            self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        }

        $stmt = $this->db->pdo()->prepare('SELECT totp_secret FROM users WHERE id = ?');
        $stmt->execute([$pending]);
        self::assertNull($stmt->fetchColumn() ?: null);
        $stmt->execute([$active]);
        self::assertSame('enc:ACTIVE', $stmt->fetchColumn());
    }

    public function testInactiveUserCanBePermanentlyDeletedAfterDeactivation(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $target = $this->mkUser('readonly');
        $this->mkSession($target);
        $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')
            ->execute([$target, $this->supplierA]);

        $activeDeletion = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(409, $activeDeletion->getStatusCode());
        self::assertSame('user_active', $this->json($activeDeletion)['error']['code']);

        $deactivated = $this->sessionRequest('DELETE', '/api/admin/users/' . $target, $session);
        self::assertSame(200, $deactivated->getStatusCode(), (string) $deactivated->getBody());
        self::assertTrue($this->json($deactivated)['deactivated']);
        $stmt = $this->db->pdo()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$target]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        $deleted = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertTrue($this->json($deleted)['deleted']);
        $stmt->execute([$target]);
        self::assertFalse($stmt->fetchColumn());
    }

    public function testInactiveUserWithLinkedDataCannotBeDeleted(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $target = $this->mkUser('readonly');
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$target]);
        $this->db->pdo()->prepare(
            'INSERT INTO email_templates (code, locale, subject, body_html, body_text, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['__test_user_delete_' . bin2hex(random_bytes(4)), 'cs', 'Synthetic', 'Synthetic', 'Synthetic', $target]);
        $this->templateIds[] = (int) $this->db->pdo()->lastInsertId();

        $blocked = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(409, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame('user_in_use', $this->json($blocked)['error']['code']);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$target]);
        self::assertSame($target, (int) $stmt->fetchColumn());

        $this->db->pdo()->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$this->templateIds[0]]);
        $this->db->pdo()->prepare(
            'INSERT INTO epo_signing_credentials
             (owner_user_id, label, pfx_ciphertext, passphrase_ciphertext, fingerprint_sha256,
              subject_dn, issuer_dn, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $target, 'Synthetic certificate', 'synthetic-ciphertext', 'synthetic-passphrase',
            hash('sha256', random_bytes(16)), 'CN=synthetic', 'CN=synthetic issuer',
            '2026-01-01 00:00:00', '2027-01-01 00:00:00',
        ]);
        $this->credentialIds[] = (int) $this->db->pdo()->lastInsertId();
        $certificateBlocked = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(409, $certificateBlocked->getStatusCode());
        self::assertSame('user_in_use', $this->json($certificateBlocked)['error']['code']);

    }

    public function testAuditTrailDoesNotBlockDeletionAndKeepsItsAuthor(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $target = $this->mkUser('readonly');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$target]);

        $chain = new ActivityLogHashChain($this->db);
        $logger = new ActivityLogger($this->db, $chain);
        $pdo->beginTransaction();
        $logger->log('__test_user_delete_audit', $target);
        $pdo->commit();
        $find = $pdo->prepare("SELECT id FROM activity_log WHERE user_id = ? AND action = '__test_user_delete_audit'");
        $find->execute([$target]);
        $logId = (int) $find->fetchColumn();
        self::assertGreaterThan(0, $logId);

        $deleted = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());

        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$target]);
        self::assertFalse($stmt->fetchColumn());

        $author = $pdo->prepare('SELECT user_id, hash FROM activity_log WHERE id = ?');
        $author->execute([$logId]);
        $row = $author->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($target, (int) $row['user_id'], 'Auditní záznam musí dál nést ID smazaného autora.');

        self::assertNotNull($row['hash']);
        $broken = array_column($chain->verify($logId)['broken'], 'id');
        self::assertNotContains($logId, $broken, 'Smazání uživatele nesmí rozbít hash auditního záznamu.');
    }

    public function testInactiveUserWithImplicitCardReferenceCannotBeDeleted(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $target = $this->mkUser('readonly');
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$target]);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_cards (supplier_id, label, last4, user_id) VALUES (?, ?, ?, ?)'
        )->execute([$this->supplierA, 'Synthetic card', '1234', $target]);
        $this->cardIds[] = (int) $this->db->pdo()->lastInsertId();

        $blocked = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(409, $blocked->getStatusCode(), (string) $blocked->getBody());
        self::assertSame('user_in_use', $this->json($blocked)['error']['code']);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$target]);
        self::assertSame($target, (int) $stmt->fetchColumn());
    }

    /**
     * Hledání vazeb na uživatele čte metadata jen vlastní databáze. Dotaz, který
     * otevírá tabulky všech databází na serveru, trvá se sousedními instalacemi
     * (hosting, paralelní testy) minuty a čeká na jejich DDL.
     */
    public function testUserReferenceDiscoveryReadsOnlyOwnDatabase(): void
    {
        foreach (['USER_FOREIGN_KEYS_SQL', 'USER_IMPLICIT_COLUMNS_SQL'] as $constant) {
            $sql = (string) (new \ReflectionClassConstant(\MyInvoice\Action\Admin\UserAdminAction::class, $constant))->getValue();
            $plan = $this->db->pdo()->query('EXPLAIN ' . $sql)->fetchAll(\PDO::FETCH_ASSOC);
            self::assertNotSame([], $plan);
            foreach ($plan as $row) {
                self::assertStringNotContainsString(
                    'Scanned all databases',
                    (string) ($row['Extra'] ?? ''),
                    $constant . ' prochází information_schema všech databází na serveru.',
                );
            }
        }
    }

    public function testLockedInactiveUserDeletionReturnsConflictInsteadOfServerError(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $target = $this->mkUser('readonly');
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$target]);

        $other = Connection::withoutSharedTestConnection(fn (): Connection => new Connection($this->config));
        $holder = $other->pdo();
        $holder->beginTransaction();
        try {
            $holder->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$target]);
            $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

            $busy = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        } finally {
            $holder->rollBack();
            $other->close();
            $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertSame(409, $busy->getStatusCode(), (string) $busy->getBody());
        self::assertSame('user_busy', $this->json($busy)['error']['code']);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$target]);
        self::assertSame($target, (int) $stmt->fetchColumn());

        $deleted = $this->sessionRequest('DELETE', '/api/admin/users/' . $target . '/permanent', $session);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
    }

    // ------------------------------------------------------------- fixtures

    private function mkEmail(): string
    {
        return '__test_f6_admin_' . bin2hex(random_bytes(6)) . '@example.com';
    }

    private function mkUser(string $role): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6', ?, 'cs', 1)"
        );
        $stmt->execute([$this->mkEmail(), $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function roleId(string $legacy): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$legacy === 'admin' ? 'superadmin' : $legacy]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSession(int $userId): array
    {
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f6');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    /** @param array{token:string, csrf_token:string} $session */
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
