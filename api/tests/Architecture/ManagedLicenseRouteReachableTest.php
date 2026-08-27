<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Doručení licence do spravované instalace musí být opravdu dosažitelné.
 *
 * Endpoint volá licenční server, ne člověk — session nemá čím získat a
 * autentizace stojí na Ed25519 podpisu obálky. Výjimky jsou ale DVĚ a na
 * různých místech: `AuthMiddleware` (bez session) a `RoutePermissionMap`
 * (bez oprávnění). Po vydání 5.28.2 byla routa jen v druhém seznamu, takže
 * ji `AuthMiddleware` odmítal 401 ještě před akcí a doručení tiše mlčelo.
 */
final class ManagedLicenseRouteReachableTest extends TestCase
{
    private const ROUTE = '/api/managed/license';

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/src/' . $relative;
        $code = file_get_contents($path);
        self::assertNotFalse($code, 'nenačten ' . $relative);

        return (string) $code;
    }

    public function testRouteIsRegistered(): void
    {
        self::assertStringContainsString(
            "'" . self::ROUTE . "'",
            $this->source('Routes.php'),
            'routa musí být zaregistrovaná'
        );
    }

    public function testRouteNeedsNoSession(): void
    {
        self::assertStringContainsString(
            "'" . self::ROUTE . "'",
            $this->source('Middleware/AuthMiddleware.php'),
            'bez výjimky v AuthMiddleware vrací endpoint 401 ještě před akcí'
        );
    }

    public function testRouteNeedsNoPermission(): void
    {
        self::assertStringContainsString(
            "'" . self::ROUTE . "'",
            $this->source('Security/RoutePermissionMap.php'),
            'bez výjimky v RoutePermissionMap ji deny-by-default guard odmítne'
        );
    }

    public function testActionExists(): void
    {
        self::assertFileExists(
            dirname(__DIR__, 2) . '/src/Action/License/ManagedLicenseAction.php',
            'routa bez akce je 500'
        );
    }
}
