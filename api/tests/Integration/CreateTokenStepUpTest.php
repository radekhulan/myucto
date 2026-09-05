<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaProtectedOperationService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * SEC-09 — vydání Personal Access Tokenu vyžaduje re-auth současným heslem.
 *
 * Dřív stačila samotná session: ukradená session (XSS, nezamčený stroj) šla
 * vyměnit za dlouhodobý bearer token, který přežije i odhlášení a změnu hesla.
 * Heslo se navíc nedalo hádat — chybný pokus nikam nezapisoval.
 *
 * Spustit: vendor/bin/phpunit --filter=CreateTokenStepUpTest
 */
#[Group('integration')]
final class CreateTokenStepUpTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private int $userId = 0;
    private string $email = '';

    /** Musí projít PasswordHasher::validate() (min. 12 znaků). */
    private const PASSWORD = 'Str0ng-Test-Pwd-2026';

    private const TEST_IP = '203.0.113.91';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->db->pdo()->query('SELECT 1 FROM users LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB nedostupná: ' . $e->getMessage());
        }

        $this->createTempUser();
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            // api_tokens visí na uživateli přes FK ON DELETE CASCADE, stačí smazat usera.
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
            $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$this->email]);
        }
    }

    /**
     * Dedikovaný dočasný uživatel — test nesmí sahat na reálné účty ve sdílené
     * dev DB (a hlavně jim nesmí přepsat password_hash).
     */
    private function createTempUser(): void
    {
        $pdo = $this->db->pdo();
        $this->email = 'sec09-' . bin2hex(random_bytes(6)) . '@example.test';
        $hash = (new PasswordHasher($this->config))->hash(self::PASSWORD);

        $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(\PDO::FETCH_COLUMN);

        // Dynamické role (role_id) přibyly až pozdější migrací — když sloupec
        // existuje, zkopírujeme role_id z libovolného existujícího uživatele,
        // ať nemusíme znát aktuální obsah číselníku rolí.
        if (in_array('role_id', $columns, true)) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, name, role_id, is_active)
                 SELECT ?, ?, ?, role_id, 1 FROM users WHERE role_id IS NOT NULL ORDER BY id LIMIT 1'
            );
            $stmt->execute([$this->email, $hash, 'SEC-09 test']);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, name, is_active) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$this->email, $hash, 'SEC-09 test']);
        }

        $this->userId = (int) $pdo->lastInsertId();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Nepodařilo se založit dočasného uživatele.');
        }
    }

    private function action(): CreateTokenAction
    {
        $redis = new RedisFactory($this->config);

        return new CreateTokenAction(
            $this->db,
            new ApiTokenService($this->db, $redis),
            new TotpService(),
            new SecretEncryption($this->config),
            new ActivityLogger($this->db),
            new IpMatcher(),
            new UserSupplierRepository($this->db),
            new PasswordHasher($this->config),
            new PasskeyCredentialRepository($this->db),
            new MfaPolicyService($this->config),
            new BruteForceGuard($this->config, $redis, $this->db),
            // Stub, ne mock: step-up se v těchhle testech neověřuje voláním na
            // službě, ale výsledkem akce — mock bez expectations hlásí notice.
            $this->createStub(MfaProtectedOperationService::class),
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/tokens', ['REMOTE_ADDR' => self::TEST_IP])
            ->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'email' => $this->email,
                // 'accountant' = staff, ne klient a ne superadmin.
                'role' => 'accountant',
                'is_superadmin' => false,
            ]);
    }

    private function invoke(array $body): ResponseInterface
    {
        return ($this->action())($this->request($body), (new ResponseFactory())->createResponse());
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody()->getContents(), true);
    }

    public function testTokenCannotBeCreatedWithoutPassword(): void
    {
        $res = $this->invoke(['name' => 'bez hesla', 'scope' => 'read']);

        self::assertSame(401, $res->getStatusCode(),
            'Samotná session nesmí stačit — dřív token vznikl bez jakéhokoli re-authu');
        self::assertSame('invalid_password', $this->decode($res)['error']['code'] ?? null);

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Nesmí vzniknout žádný token');
    }

    public function testWrongPasswordIsCountedIntoBruteForceGuard(): void
    {
        $bf = new BruteForceGuard($this->config, new RedisFactory($this->config), $this->db);
        $before = $bf->check($this->email, self::TEST_IP);
        self::assertSame(BruteForceGuard::STATE_OK, $before);

        // captcha_after je default 5 → 5 selhání musí stav posunout z OK.
        for ($i = 0; $i < 5; $i++) {
            $res = $this->invoke(['name' => 'hádám heslo', 'scope' => 'read', 'password' => 'ŠpatnéHeslo123']);
            self::assertSame(401, $res->getStatusCode());
        }

        self::assertNotSame(BruteForceGuard::STATE_OK, $bf->check($this->email, self::TEST_IP),
            'Chybné heslo se musí započítat do sdíleného brute-force guardu');
    }

    public function testCorrectPasswordCreatesTokenWithDefaultExpiry(): void
    {
        $res = $this->invoke(['name' => 'se správným heslem', 'scope' => 'read', 'password' => self::PASSWORD]);

        self::assertSame(201, $res->getStatusCode());

        $stmt = $this->db->pdo()->prepare('SELECT expires_at FROM api_tokens WHERE user_id = ?');
        $stmt->execute([$this->userId]);
        $expiresAt = $stmt->fetchColumn();

        self::assertNotFalse($expiresAt, 'Token musí vzniknout');
        self::assertNotNull($expiresAt,
            'Token bez zadané expirace nesmí být věčný — musí dostat výchozí expiraci');
    }

    /** Věčný read_write token je privilegovaná volba, běžný uživatel na něj nemá. */
    public function testNeverExpiringWriteTokenIsRejectedForNonSuperadmin(): void
    {
        $res = $this->invoke([
            'name' => 'věčný zápis',
            'scope' => 'read_write',
            'password' => self::PASSWORD,
            'never_expires' => true,
        ]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('expiry_required', $this->decode($res)['error']['code'] ?? null);
    }

    /**
     * Omezení na superadmina platilo dřív JEN pro scope `read_write`, takže si
     * běžný uživatel pořád vyrobil nesmrtelný `read` token — trvalý čtecí přístup
     * k celému účetnictví, který přežije odhlášení i změnu hesla.
     */
    public function testNeverExpiringReadTokenIsRejectedForNonSuperadmin(): void
    {
        $res = $this->invoke([
            'name' => 'věčné čtení',
            'scope' => 'read',
            'password' => self::PASSWORD,
            'never_expires' => true,
        ]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('expiry_required', $this->decode($res)['error']['code'] ?? null);

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND expires_at IS NULL'
        );
        $stmt->execute([$this->userId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Věčný token nesmí vzniknout');
    }

    /** Explicitní expirace za horizontem stropu = fakticky věčný token. */
    public function testAbsurdlyDistantExpiryIsRejected(): void
    {
        $res = $this->invoke([
            'name' => 'rok 2999',
            'scope' => 'read',
            'password' => self::PASSWORD,
            'expires_at' => '2999-01-01',
        ]);

        self::assertSame(400, $res->getStatusCode());
        self::assertSame('validation_failed', $this->decode($res)['error']['code'] ?? null);
    }

    /** Bearer nesmí razit další tokeny ani s platným heslem (escalation guard). */
    public function testBearerCannotCreateToken(): void
    {
        $request = $this->request(['name' => 'z tokenu', 'scope' => 'read', 'password' => self::PASSWORD])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');

        $res = ($this->action())($request, (new ResponseFactory())->createResponse());

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('session_required', $this->decode($res)['error']['code'] ?? null);
    }
}
