<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Auth\PasswordTokenAction;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\PasswordLoginVerifier;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * POST /api/v1/auth/password-token — výměna hesla (a TOTP) za API token.
 *
 * Akce běží nad skutečnými službami a testovací DB; ochrany (zámek pokusů sdílený
 * s webem, limit endpointu, MFA podle politiky, stejná odpověď pro neexistující
 * účet) se ověřují výsledkem, ne voláním mocků.
 *
 * Spustit: vendor/bin/phpunit --filter=PasswordTokenTest
 */
#[Group('integration')]
final class PasswordTokenTest extends TestCase
{
    /** Musí projít PasswordHasher::validate() (min. 12 znaků). */
    private const PASSWORD = 'Str0ng-Test-Pwd-2026';

    private Connection $db;
    private Config $config;
    private RedisFactory $redis;
    private string $ip;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $emails = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
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
        $this->redis = new RedisFactory($this->config);
        // Vlastní IP pro každý test: počítadla (e-mail, IP) se mezi testy nepletou.
        $this->ip = '203.0.113.' . random_int(1, 254);
        $this->resetGlobalLimit();
    }

    protected function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            // api_tokens a webauthn_credentials visí na uživateli přes FK ON DELETE CASCADE.
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        foreach ($this->emails as $email) {
            (new BruteForceGuard($this->config, $this->redis, $this->db))->recordSuccess($email, $this->ip);
            $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
        }
        $this->resetGlobalLimit();
    }

    public function testDisabledByDefaultAnswersNotFound(): void
    {
        [, $email] = $this->createUser();

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $this->action(enabled: false));

        self::assertSame(404, $res->getStatusCode(), 'Bez auth.password_token.enabled endpoint neexistuje');
        self::assertSame(0, $this->tokenCount($email));
        self::assertSame(0, $this->loginAttempts($email), 'Vypnutý endpoint heslo vůbec neověřuje');
    }

    public function testRoleWithoutTokenWritePermissionGetsNoToken(): void
    {
        // Účetní a Pouze pro čtení mají ve výchozí matici profile.tokens jen ke čtení:
        // ve webu token nevytvoří, heslem přes API také ne.
        foreach (['accountant', 'readonly'] as $roleKey) {
            [, $email] = $this->createUser(roleKey: $roleKey);

            $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);

            self::assertSame(403, $res->getStatusCode(), $roleKey);
            self::assertSame('forbidden', $this->decode($res)['error']['code'] ?? null, $roleKey);
            self::assertSame(0, $this->tokenCount($email), $roleKey);
        }
    }

    public function testInstanceAdminGetsNoToken(): void
    {
        [, $email] = $this->createUser(roleKey: 'instance_admin');

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testRecoveryCodeIsNotAcceptedInsteadOfTotp(): void
    {
        $secret = TotpService::generateSecret();
        [$userId, $email] = $this->createUser(totpSecret: $secret);
        $codes = (new MfaRecoveryCodeService($this->db))->generate($userId);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'recovery_code' => $codes[0]]);

        self::assertSame(401, $res->getStatusCode(), 'Záložní kód slouží jen k obnovení přístupu ve webu');
        self::assertSame('totp_required', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testNewLoginOfSameDeviceReplacesItsPreviousToken(): void
    {
        [, $email] = $this->createUser();

        $first = $this->decode($this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => 'iPhone']));
        $other = $this->decode($this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => 'iPad']));
        $second = $this->decode($this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => 'iPhone']));

        self::assertNotNull($this->tokenRow((int) $first['id'])['revoked_at'], 'Předchozí token zařízení je zrušený');
        self::assertNull($this->tokenRow((int) $other['id'])['revoked_at'], 'Token jiného zařízení zůstává');
        self::assertNull($this->tokenRow((int) $second['id'])['revoked_at']);
    }

    public function testTokenCreationLimitPerUserIsSharedWithWeb(): void
    {
        [$userId, $email] = $this->createUser();
        $this->extraLimitKeys[] = 'rl:pat:user:' . $userId;
        $action = $this->action(config: ['rate_limits' => ['enabled' => true, 'token_create_per_hour' => 1]]);

        self::assertSame(201, $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => 'A'], $action)->getStatusCode());
        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => 'B'], $action);

        self::assertSame(429, $res->getStatusCode());
        self::assertSame('rate_limited', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(1, $this->tokenCount($email));
    }

    public function testAccountFailureCapAppliesAcrossIpAddresses(): void
    {
        [, $email] = $this->createUser();
        $action = $this->action(config: ['rate_limits' => ['enabled' => true, 'password_token_failures_per_hour_per_account' => 3]]);

        for ($i = 0; $i < 3; $i++) {
            $this->ip = '10.' . $i . '.0.1';
            self::assertSame(401, $this->invoke(['email' => $email, 'password' => 'Spatne-Heslo-2026'], $action)->getStatusCode());
        }
        $this->ip = '100.64.0.9';
        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $action);

        self::assertSame(429, $res->getStatusCode(), 'Strop účtu platí přes různé sítě /24');
        self::assertSame('rate_limited', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testPasswordReturnsWorkingReadWriteTokenNamedAfterDevice(): void
    {
        [$userId, $email] = $this->createUser();

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'device_name' => "MyÚčto iPhone\n(test)"]);

        self::assertSame(201, $res->getStatusCode());
        self::assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $body = $this->decode($res);
        self::assertSame('read_write', $body['scope']);
        self::assertSame('MyÚčto iPhone (test)', $body['name'], 'Řídicí znaky z názvu zařízení pryč');

        $token = (new ApiTokenService($this->db, $this->redis))->validate((string) $body['token']);
        self::assertNotNull($token, 'Vrácený token musí projít ověřením bearer tokenu');
        self::assertSame($userId, $token['user_id']);
        self::assertSame('read_write', $token['scope']);
        self::assertNull($token['supplier_id']);

        $row = $this->tokenRow((int) $body['id']);
        self::assertSame('MyÚčto iPhone (test)', $row['name'], 'Token je ve webu vidět pod názvem zařízení');
        self::assertSame('password', $row['issued_auth_method']);
        $days = (strtotime((string) $row['expires_at']) - time()) / 86400;
        self::assertEqualsWithDelta(ApiTokenService::DEFAULT_EXPIRY_DAYS, $days, 1.0, 'Platnost jako u tokenu z webu');

        $log = $this->lastLog($userId, 'auth.login');
        self::assertSame('password_token', $log['channel'] ?? null);
        self::assertSame('MyÚčto iPhone (test)', $log['device_name'] ?? null);
        self::assertStringNotContainsString(self::PASSWORD, $this->allLogsJson($userId), 'Heslo se nikam neloguje');
    }

    public function testUnknownEmailAndWrongPasswordGetTheSameAnswer(): void
    {
        [, $email] = $this->createUser();

        $wrong = $this->invoke(['email' => $email, 'password' => 'Spatne-Heslo-2026']);
        $unknown = $this->invoke(['email' => 'nikdo-' . bin2hex(random_bytes(4)) . '@example.test', 'password' => 'Spatne-Heslo-2026']);

        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame($wrong->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->decode($wrong), $this->decode($unknown), 'Odpověď nesmí prozradit, jestli účet existuje');
        self::assertSame('invalid_credentials', $this->decode($wrong)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testDemoWithoutExceptionRefusesToken(): void
    {
        [, $email] = $this->createUser();

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $this->action(['demo' => ['enabled' => true]]));

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('demo_read_only', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testDemoIssuesTokenToListedReviewAccount(): void
    {
        [, $email] = $this->createUser();
        // Seznam porovnává bez ohledu na velikost písmen a okrajové mezery.
        $action = $this->action(['demo' => ['enabled' => true, 'password_token_emails' => ['  ' . strtoupper($email) . ' ']]]);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $action);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(1, $this->tokenCount($email));
    }

    public function testDemoRefusesOtherAccountsBeforeCheckingPassword(): void
    {
        [, $listed] = $this->createUser();
        [, $other] = $this->createUser();
        $action = $this->action(['demo' => ['enabled' => true, 'password_token_emails' => [$listed]]]);

        $wrong = $this->invoke(['email' => $other, 'password' => 'Spatne-Heslo-2026'], $action);
        $right = $this->invoke(['email' => $other, 'password' => self::PASSWORD], $action);

        // Správné i špatné heslo jiného účtu dostane stejnou odpověď a nepřibude pokus v zámku.
        self::assertSame(403, $wrong->getStatusCode());
        self::assertSame($this->decode($wrong), $this->decode($right));
        self::assertSame('demo_read_only', $this->decode($right)['error']['code'] ?? null);
        self::assertSame(0, $this->loginAttempts($other));
        self::assertSame(0, $this->tokenCount($other));
    }

    public function testFailedAttemptsLockTheEndpointTogetherWithWebLogin(): void
    {
        [, $email] = $this->createUser();
        $guard = new BruteForceGuard($this->config, $this->redis, $this->db);

        // Tři neúspěchy ve webu (LoginAction zapisuje do téhož počítadla) a dva přes API.
        for ($i = 0; $i < 3; $i++) {
            $guard->recordFailure($email, $this->ip);
        }
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(401, $this->invoke(['email' => $email, 'password' => 'Spatne-Heslo-2026'])->getStatusCode());
        }

        // Pět selhání = web by chtěl CAPTCHA; API ji ověřit neumí, proto odmítá i správné heslo.
        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(429, $res->getStatusCode());
        self::assertSame('too_many_attempts', $this->decode($res)['error']['code'] ?? null);
        self::assertSame('300', $res->getHeaderLine('Retry-After'));
        self::assertSame(0, $this->tokenCount($email));

        for ($i = 0; $i < 5; $i++) {
            $guard->recordFailure($email, $this->ip);
        }
        $locked = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(429, $locked->getStatusCode());
        self::assertSame('900', $locked->getHeaderLine('Retry-After'), 'Zámek na 15 minut jako ve webu');
    }

    public function testGlobalFailureLimitReturns429WithRetryAfter(): void
    {
        [, $email] = $this->createUser();
        [, $okEmail] = $this->createUser();
        $action = $this->action(config: ['rate_limits' => ['enabled' => true, 'password_token_failures_per_min' => 2]]);

        // Úspěšná přihlášení strop neúspěchů nečerpají.
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(201, $this->invoke(['email' => $okEmail, 'password' => self::PASSWORD, 'device_name' => 'ok' . $i], $action)->getStatusCode());
        }
        for ($i = 0; $i < 2; $i++) {
            $this->ip = '198.51.100.' . ($i + 1);
            self::assertSame(401, $this->invoke(['email' => $email, 'password' => 'Spatne-Heslo-2026'], $action)->getStatusCode());
        }
        $this->ip = '198.51.100.3';
        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $action);

        self::assertSame(429, $res->getStatusCode(), 'Strop platí přes všechny IP adresy');
        self::assertSame('rate_limited', $this->decode($res)['error']['code'] ?? null);
        self::assertGreaterThan(0, (int) $res->getHeaderLine('Retry-After'));
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testTotpIsRequiredAndVerified(): void
    {
        $secret = TotpService::generateSecret();
        [, $email] = $this->createUser(totpSecret: $secret);

        $missing = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);
        self::assertSame(401, $missing->getStatusCode());
        self::assertSame('totp_required', $this->decode($missing)['error']['code'] ?? null);
        self::assertSame(['totp'], $this->decode($missing)['error']['methods'] ?? null);

        $wrong = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'totp' => $this->wrongCode($secret)]);
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame('invalid_totp', $this->decode($wrong)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));

        $ok = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'totp' => (new TotpService())->currentCode($secret)]);
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame('totp', $this->tokenRow((int) $this->decode($ok)['id'])['issued_auth_method']);
    }

    public function testRequiredMfaWithoutStrongFactorIssuesNoToken(): void
    {
        [, $email] = $this->createUser();
        $action = $this->action(config: ['auth' => ['require_mfa' => true]]);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $action);

        self::assertSame(403, $res->getStatusCode(), 'Web by pustil jen do nastavení MFA, token by povinnost obešel');
        self::assertSame('mfa_setup_required', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testRequiredMfaIsSatisfiedByTotp(): void
    {
        $secret = TotpService::generateSecret();
        [, $email] = $this->createUser(totpSecret: $secret);
        $action = $this->action(config: ['auth' => ['require_mfa' => true]]);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD, 'totp' => (new TotpService())->currentCode($secret)], $action);

        self::assertSame(201, $res->getStatusCode());
    }

    public function testPasskeyOnlyAccountCannotTradePasswordForToken(): void
    {
        [$userId, $email] = $this->createUser();
        $this->db->pdo()->prepare(
            'INSERT INTO webauthn_credentials (user_id, credential_id, credential_id_hash, public_key, label, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
        )->execute([$userId, 'cred-' . $userId, hash('sha256', 'cred-' . $userId, true), 'pk', 'Klíč']);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);

        self::assertSame(403, $res->getStatusCode(), 'Heslo samo by obešlo passkey');
        self::assertSame('second_factor_unsupported', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testEmailOtpInstallationIssuesNoTokenForPasswordOnlyAccount(): void
    {
        [, $email] = $this->createUser();
        $action = $this->action(config: ['auth' => ['email_otp' => ['enabled' => true]]]);

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $action);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('second_factor_unsupported', $this->decode($res)['error']['code'] ?? null);
    }

    public function testRequiredIdentityProviderRejectsPassword(): void
    {
        [, $email] = $this->createUser();
        $policy = $this->createStub(LoginPolicyService::class);
        $policy->method('requiredProvider')->willReturn('entra');

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD], $this->action(loginPolicy: $policy));

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('sso_required', $this->decode($res)['error']['code'] ?? null);
        self::assertSame(0, $this->tokenCount($email));
    }

    public function testClientAccountGetsNoToken(): void
    {
        [, $email] = $this->createUser(roleKey: 'client');

        $res = $this->invoke(['email' => $email, 'password' => self::PASSWORD]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame(0, $this->tokenCount($email));
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $config přepíše hodnoty z cfg.php
     */
    private function action(array $config = [], ?LoginPolicyService $loginPolicy = null, bool $enabled = true): PasswordTokenAction
    {
        $config = array_replace_recursive(['auth' => ['password_token' => ['enabled' => $enabled]]], $config);
        $cfg = new Config(array_replace_recursive($this->config->all(), $config));
        $redis = new RedisFactory($cfg);
        $hasher = new PasswordHasher($cfg);
        $logger = new ActivityLogger($this->db);
        $bruteForce = new BruteForceGuard($cfg, $redis, $this->db);
        $recoveryCodes = new MfaRecoveryCodeService($this->db);
        if ($loginPolicy === null) {
            $loginPolicy = $this->createStub(LoginPolicyService::class);
            $loginPolicy->method('requiredProvider')->willReturn(null);
        }

        return new PasswordTokenAction(
            $this->db,
            new PasswordLoginVerifier($this->db, $hasher, $bruteForce, $logger, new TotpService(), new SecretEncryption($cfg), $recoveryCodes),
            $bruteForce,
            new ApiTokenService($this->db, $redis),
            $logger,
            new IpMatcher(),
            $cfg,
            new MfaPolicyService($cfg),
            $loginPolicy,
            new PasskeyCredentialRepository($this->db),
            new RateLimitMiddleware($cfg, $redis, new ResponseFactory(), new IpMatcher(), $this->db),
            new PermissionResolver(
                $this->db,
                new SupplierAccessResolver($this->db, new UserSupplierRepository($this->db)),
                new PermissionCatalog(),
            ),
        );
    }

    /** @param array<string,mixed> $body */
    private function invoke(array $body, ?PasswordTokenAction $action = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/auth/password-token', ['REMOTE_ADDR' => $this->ip])
            ->withHeader('User-Agent', 'PasswordTokenTest')
            ->withParsedBody($body);

        return ($action ?? $this->action())($request, (new ResponseFactory())->createResponse());
    }

    /** @return array{int,string} */
    private function createUser(?string $totpSecret = null, string $roleKey = 'admin'): array
    {
        $pdo = $this->db->pdo();
        $email = 'pwd-token-' . bin2hex(random_bytes(6)) . '@example.test';
        $hash = (new PasswordHasher($this->config))->hash(self::PASSWORD);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role_id, is_active, totp_secret, totp_enabled)
             SELECT ?, ?, ?, id, 1, ?, ? FROM roles WHERE system_key = ? LIMIT 1'
        )->execute([
            $email,
            $hash,
            'Password token test',
            $totpSecret !== null ? (new SecretEncryption($this->config))->encrypt($totpSecret) : null,
            $totpSecret !== null ? 1 : 0,
            $roleKey,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            $this->markTestSkipped('Nepodařilo se založit dočasného uživatele.');
        }
        $this->userIds[] = $id;
        $this->emails[] = $email;

        return [$id, $email];
    }

    private function wrongCode(string $secret): string
    {
        $current = (new TotpService())->currentCode($secret);
        return str_pad((string) (((int) $current + 500000) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody()->getContents(), true);
    }

    /** @return array<string,mixed> */
    private function tokenRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM api_tokens WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function loginAttempts(string $email): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    private function tokenCount(string $email): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE u.email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function lastLog(int $userId, string $action): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM activity_log WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $action]);
        return (array) json_decode((string) $stmt->fetchColumn(), true);
    }

    private function allLogsJson(int $userId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM activity_log WHERE user_id = ?');
        $stmt->execute([$userId]);
        return implode("\n", $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @var list<string> */
    private array $extraLimitKeys = [];

    private function resetGlobalLimit(): void
    {
        $keys = ['rl:password-token:fail:all', ...$this->extraLimitKeys];
        foreach ($this->emails as $email) {
            $keys[] = 'rl:password-token:fail:email:' . sha1(mb_strtolower($email));
        }
        foreach ($keys as $key) {
            $this->redis->run(fn ($r) => $r->del('myinvoice:ci:' . $key), null);
            $this->redis->run(fn ($r) => $r->del($key), null);
        }
        $this->db->pdo()->prepare('DELETE FROM rate_limit_counters WHERE bucket_key LIKE ?')->execute(['%password-token%']);
        foreach ($this->extraLimitKeys as $key) {
            $this->db->pdo()->prepare('DELETE FROM rate_limit_counters WHERE bucket_key LIKE ?')->execute(['%' . $key]);
        }
    }
}
