<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Bootstrap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Doručení licence do spravované instalace musí projít CELOU pipeline.
 *
 * ⚠️ Endpoint volá licenční server, ne člověk: session nemá čím získat
 * a pravost stojí na Ed25519 podpisu obálky. Výjimek je ale víc a každá jinde —
 * `AuthMiddleware` (bez session), `RoutePermissionMap` (bez oprávnění)
 * a `SupplierScopeMiddleware` (bez členství ve firmě). Chybět stačí jedna:
 *
 *   - po 5.28.2 chyběla v `AuthMiddleware`     → 401 a doručení tiše mlčelo,
 *   - po 6.0.0 chyběla v `SupplierScopeMiddleware` → 403 `forbidden_supplier`
 *     a čerstvě zřízená instalace zůstala bez licence, přestože bylo zaplaceno.
 *
 * Sesterský {@see \MyInvoice\Tests\Architecture\ManagedLicenseRouteReachableTest}
 * hlídá jednotlivé seznamy podle jména. Tenhle test je nehlídá vůbec — pustí
 * skutečný požadavek přes `Bootstrap::buildApp()` a ptá se jen na to, na čem
 * záleží: že ho nezastavila autentizace ani oprávnění. Chytí proto i bránu,
 * která teprve přibude.
 */
#[Group('integration')]
final class ManagedLicenseRouteUnauthenticatedTest extends TestCase
{
    private ?App $app = null;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje sestavenou aplikaci.');
        }
    }

    /**
     * ⚠️ Kontroluje se KÓD odmítnutí, ne stavový kód.
     *
     * Syntetický požadavek neprojde branami, které se v provozu řeší samy
     * (filtr adres, shoda originu), takže očekávat konkrétní status by test
     * jen rozbilo prostředí. Rozhoduje, ČÍM byl odmítnut: přihlášením,
     * oprávněním nebo členstvím ve firmě tuhle routu zastavit nesmí nic.
     */
    public function testRouteIsNeverRejectedByAuthenticationPermissionOrScope(): void
    {
        // Obálka je schválně nesmyslná: nejde o to, aby licence prošla, ale
        // o to, čí branou požadavek neprojde.
        $response = $this->post(['envelope' => 'nesmysl']);
        $body = (array) json_decode((string) $response->getBody(), true);
        $code = (string) ($body['error']['code'] ?? '');

        self::assertNotSame('forbidden_supplier', $code, 'scope firmy tuhle routu hlídat nemá — licence není doklad žádné firmy');
        self::assertNotSame('unauthenticated', $code, 'licenční server se sem nemá čím přihlásit');
        self::assertNotSame('unauthorized', $code);
        self::assertNotSame('forbidden', $code);
        self::assertNotSame(404, $response->getStatusCode(), 'routa musí být zaregistrovaná');
    }

    /** @param array<string,mixed> $payload */
    private function post(array $payload): \Psr\Http\Message\ResponseInterface
    {
        $this->app ??= Bootstrap::buildApp();
        $body = (new StreamFactory())->createStream(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        // ⚠️ REMOTE_ADDR musí být vyplněná. Syntetický požadavek ji nemá, a filtr
        // povolených adres pak odpoví 403 `ip_not_allowed` — což by test spletlo
        // s tím, co hlídá (autentizace a scope), a hlásil by chybu i po opravě.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/managed/license', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);

        return $this->app->handle($request);
    }
}
