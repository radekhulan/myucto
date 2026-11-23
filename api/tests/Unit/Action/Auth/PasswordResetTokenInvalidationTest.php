<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class PasswordResetTokenInvalidationTest extends TestCase
{
    private \Pdo\Sqlite $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, role TEXT, role_id INTEGER, locale TEXT, password_hash TEXT, is_active INTEGER, totp_secret TEXT, totp_enabled INTEGER, session_lock_after_minutes INTEGER)');
        $this->pdo->exec("INSERT INTO users VALUES (17, 'synthetic17@example.test', 'Synthetic', 'admin', 1, 'cs', 'hash-Initial-password-42', 1, 'pending', 0, NULL)");
        $this->pdo->exec('CREATE TABLE roles (id INTEGER, name TEXT, role_type TEXT, is_active INTEGER, system_key TEXT)');
        $this->pdo->exec("INSERT INTO roles VALUES (1, 'Synthetic', 'staff', 1, 'admin')");
        $this->pdo->exec('CREATE TABLE password_resets (id INTEGER PRIMARY KEY, user_id INTEGER, purpose TEXT, token_hash TEXT UNIQUE, expires_at TEXT, used_at TEXT)');
        $this->pdo->exec('CREATE TABLE trusted_devices (user_id INTEGER)');
        $this->pdo->exec('CREATE TABLE login_otps (user_id INTEGER)');
        $this->token(1, 17, 'old-token', 'reset');
        $this->token(2, 17, 'new-token', 'reset');
        $this->token(3, 17, 'setup-token', 'setup');
        $this->token(4, 18, 'other-user-token', 'reset');
    }

    #[TestWith(['reset'])]
    #[TestWith(['setup'])]
    public function testSuccessfulPasswordResetInvalidatesEveryOtherUserToken(string $purpose): void
    {
        $this->pdo->prepare('UPDATE password_resets SET purpose = ? WHERE id = 2')->execute([$purpose]);
        $action = $this->reset();
        self::assertSame(200, $action($this->resetRequest('new-token', 'Owner-password-42'), new Response())->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id = 17 AND used_at IS NULL')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT used_at FROM password_resets WHERE id = 4')->fetchColumn());
        self::assertSame(410, $action($this->resetRequest('old-token', 'Unwanted-password-42'), new Response())->getStatusCode());
        self::assertSame('hash-Owner-password-42', $this->pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
    }

    public function testPasswordChangeInvalidatesResetAndSetupTokens(): void
    {
        $response = $this->change()($this->changeRequest(), new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id = 17 AND used_at IS NULL')->fetchColumn());
        self::assertNull($this->pdo->query('SELECT used_at FROM password_resets WHERE id = 4')->fetchColumn());
        self::assertSame(410, $this->reset()($this->resetRequest('old-token', 'Unwanted-password-42'), new Response())->getStatusCode());
    }

    public function testResetReadBeforeAnotherResetCannotOverwriteCompletedPassword(): void
    {
        $hasher = $this->hasher(function (): string {
            self::assertSame(200, $this->reset()($this->resetRequest('new-token', 'Completed-password-42'), new Response())->getStatusCode());
            return 'hash-Stale-password-42';
        });
        $response = $this->reset($hasher)($this->resetRequest('old-token', 'Stale-password-42'), new Response());
        self::assertSame(410, $response->getStatusCode());
        self::assertSame('hash-Completed-password-42', $this->pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
    }

    public function testPasswordChangeCannotOverwriteResetCompletedAfterPasswordVerification(): void
    {
        $hasher = $this->hasher(function (): string {
            self::assertSame(200, $this->reset()($this->resetRequest('new-token', 'Completed-password-42'), new Response())->getStatusCode());
            return 'hash-Stale-change-password-42';
        });
        $response = $this->change($hasher)($this->changeRequest(), new Response());
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('hash-Completed-password-42', $this->pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
    }

    public function testResetRollsBackPasswordAndConsumedTokenIfInvalidationFails(): void
    {
        $this->pdo->exec("CREATE TRIGGER reject_old_reset_invalidation BEFORE UPDATE ON password_resets WHEN OLD.id = 1 BEGIN SELECT RAISE(ABORT, 'synthetic invalidation failure'); END");
        $response = $this->reset()($this->resetRequest('new-token', 'Owner-password-42'), new Response());
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('hash-Initial-password-42', $this->pdo->query('SELECT password_hash FROM users WHERE id = 17')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id = 17 AND used_at IS NULL')->fetchColumn());
    }

    private function token(int $id, int $userId, string $token, string $purpose): void
    {
        $this->pdo->prepare('INSERT INTO password_resets VALUES (?, ?, ?, ?, ?, NULL)')
            ->execute([$id, $userId, $purpose, hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
    }

    private function database(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        return $db;
    }

    private function hasher(?\Closure $onHash = null): PasswordHasher
    {
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->method('hash')->willReturnCallback($onHash ?? static fn (string $password): string => 'hash-' . $password);
        $hasher->method('verify')->willReturnCallback(static fn (string $password, string $hash): bool => $hash === 'hash-' . $password);
        return $hasher;
    }

    private function reset(?PasswordHasher $hasher = null): ResetPasswordAction
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('destroyAllForUser')->willReturn(0);
        $issuer = $this->createMock(LoginSessionIssuer::class);
        $issuer->method('issue')->willReturn(new Response());
        return new ResetPasswordAction(
            $this->database(), $hasher ?? $this->hasher(), $sessions,
            $this->createMock(ActivityLogger::class), new IpMatcher(), $issuer,
            new MfaPolicyService(new Config(['auth' => ['require_mfa' => false]])),
            $this->createStub(\MyInvoice\Service\Auth\LoginPolicyService::class),
        );
    }

    private function change(?PasswordHasher $hasher = null): ChangePasswordAction
    {
        $sessions = $this->createMock(SessionManager::class);
        $sessions->method('destroyAllForUser')->willReturn(0);
        $bruteForce = $this->createMock(BruteForceGuard::class);
        $bruteForce->method('check')->willReturn(BruteForceGuard::STATE_OK);
        return new ChangePasswordAction(
            $this->database(), $hasher ?? $this->hasher(), $sessions,
            $this->createMock(ActivityLogger::class), new IpMatcher(), $bruteForce,
            $this->createMock(ApiTokenService::class),
            $this->createStub(\MyInvoice\Service\Auth\LoginPolicyService::class),
        );
    }

    private function resetRequest(string $token, string $password): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/reset', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['token' => $token, 'password' => $password, 'password_confirm' => $password]);
    }

    private function changeRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/change-password', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, 'synthetic-session')
            ->withParsedBody([
                'current_password' => 'Initial-password-42', 'new_password' => 'Changed-password-42',
                'new_password_confirm' => 'Changed-password-42',
            ]);
    }
}
