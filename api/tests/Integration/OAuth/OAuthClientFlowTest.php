<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\OAuth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Repository\OAuthSessionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\OAuth\OAuthClientFlow;
use MyInvoice\Service\OAuth\OAuthException;
use MyInvoice\Service\OAuth\OAuthProviderConfig;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use MyInvoice\Service\OAuth\Pkce;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Společná vrstva OAuth klienta proti falešnému autorizačnímu serveru (Guzzle MockHandler):
 * state jen jako hash, jednorázové převzetí, izolace firem a uživatelů, PKCE S256,
 * Device Code Flow a obnova tokenu pod zámkem napojení.
 */
#[Group('integration')]
final class OAuthClientFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OAuthClientFlow $flow;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    /** @var list<array<string,mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Testovací DB nemá firmu nebo uživatele.');
        }
        $this->pdo->beginTransaction();
        $this->supplierA = $this->createIsolatedSupplier($this->pdo, $source);
        $this->supplierB = $this->createIsolatedSupplier($this->pdo, $source);
        $this->flow = new OAuthClientFlow(new OAuthSessionRepository($this->db), self::secrets(), $this->db,
            new Config(['app' => ['url' => 'https://example.test']]));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testStoredLoginKeepsOnlyStateHashAndEncryptedSecret(): void
    {
        $auth = $this->flow->authorize();
        $this->store($auth, ['client_secret' => 'synthetic-client-secret']);

        $row = $this->row($auth->stateHash());
        self::assertSame(hash('sha256', $auth->state), $row['state_hash']);
        self::assertStringStartsWith('enc:v2:', $row['secret_ciphertext']);
        foreach ([$auth->state, (string) $auth->codeVerifier, 'synthetic-client-secret'] as $secret) {
            self::assertStringNotContainsString($secret, implode('|', array_map('strval', $row)));
        }
        self::assertSame(['bank', 'test_provider', 'redirect', 'pending'], [$row['domain'], $row['provider'], $row['flow'], $row['status']]);
    }

    public function testStateCanBeClaimedOnlyOnceAndSecretIsWipedAfterFinish(): void
    {
        $auth = $this->flow->authorize();
        $this->store($auth, ['client_secret' => 'synthetic-client-secret']);

        $session = $this->claim($auth->state);
        self::assertNotNull($session);
        self::assertSame(['client_secret' => 'synthetic-client-secret'], $session->secret);
        self::assertSame($auth->codeVerifier, $session->codeVerifier);
        self::assertNull($this->claim($auth->state), 'Opakovaně použitý state se nesmí převzít podruhé.');

        $this->flow->finish($session, true);
        self::assertSame(['completed', ''], [$this->row($auth->stateHash())['status'], $this->row($auth->stateHash())['secret_ciphertext']]);
    }

    public function testUnknownMalformedAndExpiredStateIsRejected(): void
    {
        self::assertNull($this->claim(bin2hex(random_bytes(32))));
        self::assertNull($this->claim('not-a-state'));
        self::assertNull($this->flow->find('not-a-state'));

        $auth = $this->flow->authorize();
        $this->store($auth, ['k' => 'v']);
        $this->pdo->prepare('UPDATE oauth_sessions SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE state_hash = ?')
            ->execute([$auth->stateHash()]);

        self::assertNull($this->claim($auth->state));
        self::assertTrue($this->flow->find($auth->state)['expired']);
    }

    public function testLoginOfOtherSupplierUserDomainOrProviderCannotBeClaimed(): void
    {
        $auth = $this->flow->authorize();
        $this->store($auth, ['k' => 'v']);

        self::assertNull($this->flow->claim($auth->state, $this->supplierB, $this->userId, 'bank', 'test_provider'));
        self::assertNull($this->flow->claim($auth->state, $this->supplierA, $this->userId + 100000, 'bank', 'test_provider'));
        self::assertNull($this->flow->claim($auth->state, $this->supplierA, $this->userId, 'odometer', 'test_provider'));
        self::assertNull($this->flow->claim($auth->state, $this->supplierA, $this->userId, 'bank', 'other_provider'));
        self::assertNull($this->flow->claim($auth->state, $this->supplierA, $this->userId, 'bank', 'test_provider', 'registration'));
        self::assertNull($this->flow->open($auth->stateHash(), $this->supplierB, $this->userId, 'bank', 'test_provider', 3));
        self::assertNull($this->flow->open($auth->stateHash(), $this->supplierA, $this->userId + 100000, 'bank', 'test_provider', 3));
        self::assertNotNull($this->flow->open($auth->stateHash(), $this->supplierA, $this->userId, 'bank', 'test_provider', 3));

        self::assertNotNull($this->claim($auth->state), 'Odmítnuté pokusy nesmí přihlášení spotřebovat.');
    }

    public function testCiphertextMovedToAnotherLoginCannotBeOpened(): void
    {
        $foreign = $this->flow->authorize();
        $this->flow->store($foreign, $this->supplierB, $this->userId, 'bank', 'test_provider', 3, OAuthClientFlow::FLOW_REDIRECT,
            ['client_secret' => 'synthetic-foreign-secret'], 600);
        $auth = $this->flow->authorize();
        $this->store($auth, ['k' => 'v']);
        $this->pdo->prepare('UPDATE oauth_sessions SET secret_ciphertext = (SELECT c FROM (SELECT secret_ciphertext AS c FROM oauth_sessions WHERE state_hash = ?) x) WHERE state_hash = ?')
            ->execute([$foreign->stateHash(), $auth->stateHash()]);

        try {
            $this->claim($auth->state);
            self::fail('Ciphertext jiného přihlášení se nesmí rozšifrovat.');
        } catch (OAuthException $e) {
            self::assertSame(OAuthException::INVALID_STATE, $e->errorCode);
        }
        self::assertSame('failed', $this->row($auth->stateHash())['status']);
    }

    public function testNewLoginEndsThePreviousOneOfTheSameConnection(): void
    {
        $first = $this->flow->authorize();
        $this->store($first, ['k' => 'v']);
        $second = $this->flow->authorize();
        $this->store($second, ['k' => 'w']);

        self::assertNull($this->claim($first->state));
        self::assertSame(['k' => 'w'], $this->claim($second->state)?->secret);
    }

    public function testPkceUsesS256AndVerifierGoesOnlyToTokenEndpoint(): void
    {
        $auth = $this->flow->authorize();
        self::assertTrue(Pkce::isValidVerifier((string) $auth->codeVerifier));
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', (string) $auth->codeVerifier, true)), '+/', '-_'), '='), $auth->codeChallenge());
        self::assertNull($this->flow->authorize(false)->codeChallenge());

        $client = $this->client([$this->tokens('synthetic-access', 'synthetic-refresh')]);
        $config = $this->config();
        $url = $client->authorizationUrl($config, $this->flow->callbackUrl(), $auth);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['code', 'synthetic-client', 'https://example.test/api/oauth/callback', 'S256', $auth->codeChallenge(), $auth->state],
            [$query['response_type'], $query['client_id'], $query['redirect_uri'], $query['code_challenge_method'], $query['code_challenge'], $query['state']]);
        self::assertStringNotContainsString((string) $auth->codeVerifier, $url);

        $tokens = $client->exchangeCode($config, 'synthetic-code', $this->flow->callbackUrl(), $auth->codeVerifier);
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(['authorization_code', 'synthetic-code', $auth->codeVerifier, 'synthetic-client-secret'],
            [$form['grant_type'], $form['code'], $form['code_verifier'], $form['client_secret']]);
        self::assertSame(['synthetic-access', 'synthetic-refresh'], [$tokens->accessToken, $tokens->refreshToken]);
    }

    public function testDeviceCodeLoginKeepsPollingStateUntilApproved(): void
    {
        $client = $this->client([
            new Response(200, [], (string) json_encode(['device_code' => 'synthetic-device', 'user_code' => 'ABCD-1234',
                'verification_uri' => 'https://login.example.test/device', 'interval' => 5, 'expires_in' => 600])),
            new Response(400, [], (string) json_encode(['error' => 'authorization_pending'])),
            new Response(400, [], (string) json_encode(['error' => 'slow_down'])),
            $this->tokens('synthetic-access', 'synthetic-refresh'),
        ]);
        $config = $this->config();
        $auth = $this->flow->authorize();
        $device = $client->requestDeviceCode($config, $auth->codeChallenge());
        $this->flow->store($auth, $this->supplierA, $this->userId, 'odometer', 'test_provider', 3, OAuthClientFlow::FLOW_DEVICE_CODE,
            ['device_code' => $device->deviceCode, 'interval' => $device->interval], $device->expiresIn);
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame([$auth->codeChallenge(), 'S256'], [$form['code_challenge'], $form['code_challenge_method']]);

        $errors = [];
        foreach ([OAuthException::AUTHORIZATION_PENDING, OAuthException::SLOW_DOWN] as $expected) {
            $session = $this->flow->open($auth->stateHash(), $this->supplierA, $this->userId, 'odometer', 'test_provider', 3);
            try {
                $client->pollDeviceCode($config, (string) $session->secret['device_code'], $session->codeVerifier);
            } catch (OAuthException $e) {
                $errors[] = $e->errorCode;
                $this->flow->update($session, ['interval' => (int) $session->secret['interval'] + 5] + $session->secret);
            }
        }
        self::assertSame([OAuthException::AUTHORIZATION_PENDING, OAuthException::SLOW_DOWN], $errors);

        $session = $this->flow->open($auth->stateHash(), $this->supplierA, $this->userId, 'odometer', 'test_provider', 3);
        self::assertSame(15, $session->secret['interval']);
        $tokens = $client->pollDeviceCode($config, (string) $session->secret['device_code'], $session->codeVerifier);
        parse_str((string) $this->history[3]['request']->getBody(), $poll);
        self::assertSame(['urn:ietf:params:oauth:grant-type:device_code', 'synthetic-device', $auth->codeVerifier],
            [$poll['grant_type'], $poll['device_code'], $poll['code_verifier']]);
        self::assertSame('synthetic-refresh', $tokens->refreshToken);
        $this->flow->finish($session, true);
        self::assertNull($this->flow->open($auth->stateHash(), $this->supplierA, $this->userId, 'odometer', 'test_provider', 3));
    }

    public function testRotatedRefreshTokenIsStoredBeforeItIsUsed(): void
    {
        $stored = ['access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'expires_at' => time() - 1];
        $client = $this->client([$this->tokens('new-access', 'new-refresh')]);
        $config = $this->config();
        $lockName = NamedLockName::for($this->db, 'oauth-test', 7);
        $heldDuringRefresh = null;

        $result = $this->flow->rotate('oauth-test', 7,
            fn (): array => $stored,
            fn (array $c): bool => $c['expires_at'] > time() + 30,
            function (array $c) use ($client, $config, $lockName, &$heldDuringRefresh): array {
                $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                $stmt->execute([$lockName]);
                $heldDuringRefresh = (int) $stmt->fetchColumn();
                $tokens = $client->refresh($config, $c['refresh_token']);
                return ['access_token' => $tokens->accessToken, 'refresh_token' => $tokens->refreshToken ?? $c['refresh_token'],
                    'expires_at' => time() + $tokens->expiresIn];
            },
            function (array $c) use (&$stored): bool {
                $stored = $c;
                return true;
            },
        );

        self::assertSame(1, $heldDuringRefresh, 'Obnova musí běžet pod zámkem napojení.');
        self::assertSame(['new-access', 'new-refresh'], [$result['access_token'], $stored['refresh_token']]);
        parse_str((string) $this->history[0]['request']->getBody(), $form);
        self::assertSame(['refresh_token', 'old-refresh'], [$form['grant_type'], $form['refresh_token']]);
    }

    public function testFailedPersistenceBlocksUseOfRotatedToken(): void
    {
        $client = $this->client([$this->tokens('new-access', 'new-refresh')]);
        try {
            $this->flow->rotate('oauth-test', 8, fn (): array => ['refresh_token' => 'old-refresh'], fn (): bool => false,
                fn (array $c): array => ['refresh_token' => (string) $client->refresh($this->config(), $c['refresh_token'])->refreshToken],
                fn (): bool => false);
            self::fail('Neuložený obnovený token se nesmí použít.');
        } catch (OAuthException $e) {
            self::assertSame(OAuthException::ROTATION_FAILED, $e->errorCode);
        }
    }

    public function testConcurrentRefreshReusesTokenRotatedByTheFirstRequest(): void
    {
        $stored = ['refresh_token' => 'old-refresh', 'expires_at' => time() - 1];
        $refreshes = 0;
        $rotate = function () use (&$stored, &$refreshes): array {
            return $this->flow->rotate('oauth-test', 9,
                function () use (&$stored): array {
                    return $stored;
                },
                fn (array $c): bool => $c['expires_at'] > time() + 30,
                function () use (&$refreshes): array {
                    $refreshes++;
                    return ['refresh_token' => 'new-refresh-' . $refreshes, 'expires_at' => time() + 3600];
                },
                function (array $c) use (&$stored): bool {
                    $stored = $c;
                    return true;
                });
        };

        // Druhý požadavek čekal na zámek a načte přístupy, které první mezitím obnovil.
        $first = $rotate();
        $second = $rotate();

        self::assertSame(1, $refreshes, 'Rotující refresh token se smí použít jen jednou.');
        self::assertSame(['new-refresh-1', 'new-refresh-1'], [$first['refresh_token'], $second['refresh_token']]);
    }

    public function testLockedConnectionIsReportedAsBusy(): void
    {
        $config = Bootstrap::buildContainer()->get(Config::class);
        $other = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($config));
        $name = NamedLockName::for($this->db, 'oauth-test', 10);
        $other->pdo()->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);
        try {
            $this->flow->withConnectionLock('oauth-test', 10, fn () => self::fail('Zamčené napojení se nesmí obnovit.'),
                fn () => new OAuthException('busy_marker'), 0);
            self::fail('Očekávána výjimka.');
        } catch (OAuthException $e) {
            self::assertSame('busy_marker', $e->errorCode);
        } finally {
            $other->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            $other->close();
        }
    }

    private function store(\MyInvoice\Service\OAuth\OAuthAuthorization $auth, array $secret): void
    {
        $this->flow->store($auth, $this->supplierA, $this->userId, 'bank', 'test_provider', 3, OAuthClientFlow::FLOW_REDIRECT, $secret, 600);
    }

    private function claim(string $state): ?\MyInvoice\Service\OAuth\OAuthSession
    {
        return $this->flow->claim($state, $this->supplierA, $this->userId, 'bank', 'test_provider');
    }

    /** @return array<string,mixed> */
    private function row(string $hash): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_sessions WHERE state_hash = ?');
        $stmt->execute([$hash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function config(): OAuthProviderConfig
    {
        return new OAuthProviderConfig('synthetic-client', 'https://login.example.test/token', 'https://login.example.test/authorize',
            'https://login.example.test/device', 'read', 'synthetic-client-secret');
    }

    /** @param list<Response> $responses */
    private function client(array $responses): OAuthTokenClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new OAuthTokenClient(new Client(['handler' => $stack]));
    }

    private function tokens(string $access, string $refresh): Response
    {
        return new Response(200, [], (string) json_encode(['access_token' => $access, 'refresh_token' => $refresh,
            'token_type' => 'Bearer', 'expires_in' => 3600]));
    }

    private static function secrets(): SecretEncryption
    {
        return new SecretEncryption(new Config(['app' => [
            'pepper' => 'synthetic-pepper', 'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
        ]]));
    }
}
