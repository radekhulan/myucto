<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Invoice\OverduePolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SetupStatusActionTest extends TestCase
{
    public function testPasswordlessLoginIsExposedOnlyWhenConfiguredAndAvailable(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(true);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $action = new SetupStatusAction(
            $lock,
            new Config([
                'auth' => ['passwordless_login' => ['enabled' => true]],
                'captcha' => ['provider' => 'none'],
            ]),
            $passkeys,
            $policy,
            new ManagedModeGuard(new Config([])),
            new OverduePolicy(new Config(['invoices' => ['overdue_includes_today' => true]])),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['passwordless_login_enabled']);
        self::assertTrue($body['overdue_includes_today']);
    }

    public function testPasswordlessLoginDefaultsToHiddenWithoutProbingWebAuthn(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $action = new SetupStatusAction(
            $lock,
            new Config(['captcha' => ['provider' => 'none']]),
            $passkeys,
            $policy,
            new ManagedModeGuard(new Config([])),
            new OverduePolicy(new Config([])),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['passwordless_login_enabled']);
        self::assertFalse($body['overdue_includes_today']);
    }
}
