<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class ResetPasswordEnrollmentTest extends TestCase
{
    #[TestWith(['setup', false])]
    #[TestWith(['setup', true])]
    #[TestWith(['reset', false])]
    #[TestWith(['setup', false, true])]
    public function testOnlyInitialSetupPassesTheJustSetPasswordAuthorization(string $purpose, bool $concurrentChange, bool $concurrentConsume = false): void
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:');
        $pdo->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, role TEXT, role_id INTEGER, locale TEXT, password_hash TEXT, is_active INTEGER, totp_secret TEXT, totp_enabled INTEGER, session_lock_after_minutes INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (17, 'synthetic@example.test', 'Synthetic', 'admin', 1, 'cs', 'old-hash', 1, 'pending', 0, NULL)");
        $pdo->exec('CREATE TABLE roles (id INTEGER, name TEXT, role_type TEXT, is_active INTEGER, system_key TEXT)');
        $pdo->exec("INSERT INTO roles VALUES (1, 'Synthetic', 'staff', 1, 'admin')");
        $pdo->exec('CREATE TABLE password_resets (id INTEGER, user_id INTEGER, purpose TEXT, token_hash TEXT, expires_at TEXT, used_at TEXT)');
        $pdo->prepare('INSERT INTO password_resets VALUES (1, 17, ?, ?, ?, NULL)')
            ->execute([$purpose, hash('sha256', 'synthetic-token'), date('Y-m-d H:i:s', time() + 3600)]);
        $pdo->exec('CREATE TABLE trusted_devices (user_id INTEGER)');
        $pdo->exec('CREATE TABLE login_otps (user_id INTEGER)');
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->method('hash')->willReturnCallback(static function () use ($pdo, $concurrentConsume): string {
            if ($concurrentConsume) $pdo->exec('UPDATE password_resets SET used_at = NOW()');
            return 'just-set-hash';
        });
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects($concurrentConsume ? self::never() : self::once())->method('destroyAllForUser')->with(17)
            ->willReturnCallback(static function () use ($pdo, $concurrentChange): int {
                if ($concurrentChange) $pdo->exec("UPDATE users SET password_hash = 'concurrent-hash' WHERE id = 17");
                return 1;
            });
        $issuer = $this->createMock(LoginSessionIssuer::class);
        if ($purpose === 'setup' && !$concurrentConsume) {
            $issuer->expects(self::once())->method('issue')->with(
                self::anything(), self::isArray(), self::isString(), self::isString(),
                self::callback(static fn (SessionAuthContext $context): bool => $context->assuranceLevel === 'setup'),
                false, 'just-set-hash',
            )->willReturn(new Response());
        } else {
            $issuer->expects(self::never())->method('issue');
        }
        $action = new ResetPasswordAction(
            $db, $hasher, $sessions, $this->createMock(ActivityLogger::class), new IpMatcher(), $issuer,
            new MfaPolicyService(new Config(['auth' => ['require_mfa' => true]])),
            $this->createStub(\MyInvoice\Service\Auth\LoginPolicyService::class),
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/reset', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['token' => 'synthetic-token', 'password' => 'Synthetic-password-42', 'password_confirm' => 'Synthetic-password-42']);
        if ($concurrentConsume) {
            self::assertSame(410, $action($request, new Response())->getStatusCode());
            self::assertSame('old-hash', $pdo->query('SELECT password_hash FROM users')->fetchColumn());
            self::assertSame('pending', $pdo->query('SELECT totp_secret FROM users')->fetchColumn());
            return;
        }
        self::assertSame(200, $action($request, new Response())->getStatusCode());
        self::assertNull($pdo->query('SELECT totp_secret FROM users')->fetchColumn());
        self::assertSame(410, $action($request, new Response())->getStatusCode());
    }
}
