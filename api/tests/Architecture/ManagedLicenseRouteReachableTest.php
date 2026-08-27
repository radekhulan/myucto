<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Doručení licence do spravované instalace musí být opravdu dosažitelné.
 *
 * Endpoint volá licenční server, ne člověk — session nemá čím získat a
 * autentizace stojí na Ed25519 podpisu obálky. Výjimky jsou ale ČTYŘI a každá
 * jinde: `AuthMiddleware` (bez session), `RoutePermissionMap` (bez oprávnění),
 * `SupplierScopeMiddleware` (bez členství ve firmě) a `CsrfMiddleware`
 * (bez Origin/Referer). Chybět stačí jedna:
 *
 *   - po 5.28.2 chyběla v `AuthMiddleware`          → 401, doručení tiše mlčelo,
 *   - po 6.0.0 chyběla v `SupplierScopeMiddleware`  → 403 `forbidden_supplier`
 *     a čerstvě zřízená instalace zůstala bez licence, přestože bylo zaplaceno,
 *   - a hned za ní chyběla v `CsrfMiddleware`      → 403 `origin_mismatch`,
 *     protože server-to-server volání Origin ani Referer nemá odkud vzít.
 *
 * ⚠️ Tenhle test hlídá seznamy podle jména, takže o bráně, která teprve
 * přibude, neví. Na to je
 * {@see \MyInvoice\Tests\Integration\License\ManagedLicenseRouteUnauthenticatedTest},
 * který pustí skutečný požadavek přes celou pipeline.
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

    public function testRouteNeedsNoSupplierMembership(): void
    {
        self::assertStringContainsString(
            "'" . self::ROUTE . "'",
            $this->source('Middleware/SupplierScopeMiddleware.php'),
            'bez výjimky ve SupplierScopeMiddleware vrací endpoint 403 forbidden_supplier —'
            . ' server-to-server volání žádné členství ve firmě nemá a mít nemůže'
        );
    }

    public function testRouteNeedsNoBrowserOrigin(): void
    {
        self::assertStringContainsString(
            "'" . self::ROUTE . "'",
            $this->source('Middleware/CsrfMiddleware.php'),
            'bez výjimky v CsrfMiddleware vrací endpoint 403 origin_mismatch —'
            . ' server-to-server volání Origin ani Referer nemá odkud vzít'
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
