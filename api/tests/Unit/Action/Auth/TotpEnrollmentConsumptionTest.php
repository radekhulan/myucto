<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\MfaRecoveryCodeService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TotpEnrollmentConsumptionTest extends TestCase
{
    public function testEnrollmentCodeCannotAuthorizeAnotherOperation(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER, email TEXT, totp_secret TEXT, totp_enabled INTEGER)');
        $pdo->exec("INSERT INTO users VALUES (1, 'synthetic@example.test', 'synthetic-encrypted-secret', 0)");
        $pdo->exec('CREATE TABLE totp_used_steps (secret_fingerprint TEXT, time_step INTEGER, PRIMARY KEY (secret_fingerprint, time_step))');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $secret = TotpService::generateSecret();
        $totp = new TotpService();
        $code = $totp->currentCode($secret);
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn($secret);
        $policy = $this->createStub(MfaPolicyService::class);
        $policy->method('isMethodAllowed')->willReturn(true);
        $action = new TotpAction(
            $db,
            $totp,
            new Config([]),
            $this->createStub(ActivityLogger::class),
            new IpMatcher(),
            $crypto,
            $policy,
            $this->createStub(MfaRecoveryCodeService::class),
            $this->createStub(SessionManager::class),
            $this->createStub(SessionCookieFactory::class),
            $this->createStub(ClockInterface::class),
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/totp/enable')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1])
            ->withParsedBody(['code' => $code]);
        self::assertSame(200, $action->enable($request, new Response())->getStatusCode());
        self::assertSame(1, (int) $pdo->query('SELECT totp_enabled FROM users')->fetchColumn());
        self::assertFalse($totp->verifyAndConsume($db, $secret, $code));
        self::assertSame(400, $action->enable($request, new Response())->getStatusCode());
    }
}
