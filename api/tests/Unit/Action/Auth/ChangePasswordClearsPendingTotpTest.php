<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Rozpracované (nezaktivované) TOTP zřízení je vázané na autorizaci, která ho
 * spustila. Změna hesla je reakce na podezření z kompromitace, takže pending
 * secret se zahodí spolu se sessions; aktivní faktor zůstává nedotčený.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChangePasswordClearsPendingTotpTest extends TestCase
{
    public function testPendingTotpSecretIsDiscardedOnPasswordChange(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES (17, 'synthetic17@example.test', 'old-hash', 'enc:PENDING', 0)");
        $pdo->exec("INSERT INTO users VALUES (18, 'synthetic18@example.test', 'old-hash', 'enc:ACTIVE', 1)");

        $response = $this->action($pdo)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/password')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
                ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
                ->withParsedBody([
                    'current_password' => 'Old-synthetic-password-1',
                    'new_password' => 'New-synthetic-password-1',
                    'new_password_confirm' => 'New-synthetic-password-1',
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($pdo->query('SELECT totp_secret FROM users WHERE id = 17')->fetchColumn() ?: null);
        self::assertSame('enc:ACTIVE', $pdo->query('SELECT totp_secret FROM users WHERE id = 18')->fetchColumn());
    }

    public function testActiveTotpSecretSurvivesPasswordChange(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES (17, 'synthetic17@example.test', 'old-hash', 'enc:ACTIVE', 1)");

        $response = $this->action($pdo)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/password')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
                ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
                ->withParsedBody([
                    'current_password' => 'Old-synthetic-password-1',
                    'new_password' => 'New-synthetic-password-1',
                    'new_password_confirm' => 'New-synthetic-password-1',
                ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('enc:ACTIVE', $pdo->query('SELECT totp_secret FROM users WHERE id = 17')->fetchColumn());
    }

    public function testWrongCurrentPasswordIsCountedIntoBruteForceGuard(): void
    {
        $pdo = $this->usersTable();
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        $bruteForce->expects(self::once())->method('recordFailure')
            ->with('synthetic17@example.test', self::anything());

        $response = $this->action($pdo, false, $bruteForce)($this->request(), new Response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('old-hash', $pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
    }

    public function testLockedBruteForceGuardRefusesBeforeVerifyingPassword(): void
    {
        $pdo = $this->usersTable();
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_LOCKED_15M);
        $bruteForce->expects(self::never())->method('recordFailure');
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::never())->method('verify');

        $response = $this->action($pdo, true, $bruteForce, $hasher)($this->request(), new Response());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('old-hash', $pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
    }

    public function testPasswordChangeKeepsApiTokensAndReportsTheirCount(): void
    {
        $pdo = $this->usersTable();
        $tokens = $this->createMock(ApiTokenService::class);
        $tokens->expects(self::never())->method('revokeAllForUser');
        $tokens->expects(self::once())->method('countActiveForUser')->with(17)->willReturn(2);

        $response = $this->action($pdo, true, null, null, $tokens)($this->request(), new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(2, $body['data']['active_api_tokens'] ?? $body['active_api_tokens'] ?? null);
    }

    private function usersTable(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES (17, 'synthetic17@example.test', 'old-hash', NULL, 0)");
        return $pdo;
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/change-password')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'session-token')
            ->withParsedBody([
                'current_password' => 'Old-synthetic-password-1',
                'new_password' => 'New-synthetic-password-1',
                'new_password_confirm' => 'New-synthetic-password-1',
            ]);
    }

    private function action(
        \PDO $pdo,
        bool $passwordMatches = true,
        ?BruteForceGuard $bruteForce = null,
        ?PasswordHasher $hasher = null,
        ?ApiTokenService $tokens = null,
    ): ChangePasswordAction {
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        if ($hasher === null) {
            $hasher = $this->createMock(PasswordHasher::class);
            $hasher->method('verify')->willReturn($passwordMatches);
            $hasher->method('hash')->willReturn('new-hash');
        }
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('destroyAllForUser')->willReturn(0);
        if ($bruteForce === null) {
            $bruteForce = $this->createMock(BruteForceGuard::class);
            $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        }

        return new ChangePasswordAction(
            $db,
            $hasher,
            $sessions,
            $this->createMock(ActivityLogger::class),
            new IpMatcher(),
            $bruteForce,
            $tokens ?? $this->createMock(ApiTokenService::class),
        );
    }
}
