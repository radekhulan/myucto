<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\CertificateVaultAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Trezor certifikátů se dosud nikdy nezavolal z testu, a tak se stalo tohle:
 * `guard()` deklaroval `string $level`, dostával enum `AccessLevel` a endpoint
 * padal na TypeError s HTTP 500. Frontend chybu spolkl a uživateli tvrdil, že
 * trezor je prázdný — přitom certifikát v něm celou dobu byl.
 *
 * Test proto nezkoumá obsah trezoru (ten závisí na datech), ale to, že se
 * cesta vůbec projde a odpoví smysluplným stavem. Kdyby se signatura zase
 * rozešla, spadne tady, ne až u uživatele.
 */
#[Group('integration')]
final class CertificateVaultActionTest extends TestCase
{
    private CertificateVaultAction $action;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->action = Bootstrap::buildApp()
                ->getContainer()
                ->get(CertificateVaultAction::class);
        } catch (\Throwable $exception) {
            self::markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
    }

    public function testListingDoesNotBlowUpOnTheAuthorizationGuard(): void
    {
        $response = $this->action->list($this->request('admin'), new Response());

        // 200 nebo 403 podle práv role — obojí je korektní odpověď. Cokoli
        // z řady 5xx znamená, že cesta vůbec neprošla.
        self::assertLessThan(
            500,
            $response->getStatusCode(),
            'Výpis certifikátů skončil chybou serveru.',
        );
    }

    /**
     * Soukromý klíč se nikdy nespravuje tokenem: token se dá odcizit a na
     * rozdíl od relace u něj není druhý faktor.
     */
    public function testTokenAuthenticationIsRefused(): void
    {
        $response = $this->action->list(
            $this->request('admin', 'bearer'),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'session_required',
            (string) $response->getBody(),
        );
    }

    public function testUnauthenticatedRequestIsRefused(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/certificates')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        $response = $this->action->list($request, new Response());

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertLessThan(500, $response->getStatusCode());
    }

    private function request(
        string $role,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/settings/certificates')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }
}
