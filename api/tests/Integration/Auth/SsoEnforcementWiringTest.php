<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\LoginPolicyService;
use MyInvoice\Service\Auth\MfaStepUpService;
use PHPUnit\Framework\TestCase;

/**
 * AuthMiddleware i MfaStepUpService berou politiku přihlášení jako volitelný
 * parametr kvůli testům. Autowiring volitelné parametry vynechá, takže bez
 * výslovné definice v Bootstrapu by vynucení přihlášení přes poskytovatele
 * identity tiše neplatilo.
 */
final class SsoEnforcementWiringTest extends TestCase
{
    public function testContainerInjectsLoginPolicy(): void
    {
        $container = Bootstrap::buildContainer();
        foreach ([AuthMiddleware::class, MfaStepUpService::class] as $class) {
            $property = new \ReflectionProperty($class, 'loginPolicy');
            self::assertInstanceOf(LoginPolicyService::class, $property->getValue($container->get($class)), $class);
        }
    }
}
