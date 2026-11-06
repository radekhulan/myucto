<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\PasskeyAction;
use MyInvoice\Action\Auth\SessionAction;
use MyInvoice\Routes;
use MyInvoice\Service\Auth\WebAuthnOperationPolicy;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

final class WebAuthnOperationPolicyParityTest extends TestCase
{
    private const HANDLERS = [
        'login.password_mfa_options' => LoginAction::class . '::__invoke',
        'login.passwordless_options' => LoginAction::class . '::passkeyOptions',
        'login.verify' => PasskeyAction::class . '::loginVerify',
        'credentials.list' => PasskeyAction::class . '::credentials',
        'registration.options' => PasskeyAction::class . '::registerOptions',
        'registration.verify' => PasskeyAction::class . '::registerVerify',
        'step_up.options' => PasskeyAction::class . '::stepUpOptions',
        'step_up.verify' => PasskeyAction::class . '::stepUpVerify',
        'credentials.rename' => PasskeyAction::class . '::rename',
        'credentials.revoke' => PasskeyAction::class . '::revoke',
        'credentials.test_options' => PasskeyAction::class . '::testOptions',
        'credentials.test_verify' => PasskeyAction::class . '::testVerify',
        'session_unlock.options' => SessionAction::class . '::unlockOptions',
        'session_unlock.verify' => SessionAction::class . '::unlockVerify',
    ];

    public function testSemanticInventoryMatchesRegisteredRoutesAndControllers(): void
    {
        $app = AppFactory::create();
        Routes::register($app);
        $registered = [];
        $semanticControllerRoutes = [];

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $handler = self::handlerName($route->getCallable());
            foreach ($route->getMethods() as $method) {
                if ($method === 'OPTIONS') continue;
                $key = $method . ' ' . $route->getPattern();
                $registered[$key] = $handler;
                if (str_starts_with($handler, PasskeyAction::class . '::')
                    || in_array($handler, [
                        LoginAction::class . '::__invoke',
                        LoginAction::class . '::passkeyOptions',
                        SessionAction::class . '::unlockOptions',
                        SessionAction::class . '::unlockVerify',
                    ], true)
                ) {
                    $semanticControllerRoutes[$key] = $handler;
                }
            }
        }

        $inventory = (new WebAuthnOperationPolicy())->inventory();
        self::assertSame(array_keys(self::HANDLERS), array_keys($inventory));

        $expected = [];
        foreach ($inventory as $name => $operation) {
            $key = $operation['method'] . ' ' . $operation['route_pattern'];
            self::assertArrayHasKey($key, $registered, "$name nemá zaregistrovanou route.");
            self::assertSame(self::HANDLERS[$name], $registered[$key], $name);
            $expected[$key] = self::HANDLERS[$name];
        }

        ksort($expected);
        ksort($semanticControllerRoutes);
        self::assertSame(
            $expected,
            $semanticControllerRoutes,
            'Route napojená na WebAuthn controller nesmí zůstat mimo origin policy.',
        );
    }

    private static function handlerName(mixed $handler): string
    {
        if (is_string($handler)) return $handler . '::__invoke';
        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            return $handler[0] . '::' . $handler[1];
        }
        return get_debug_type($handler);
    }
}
