<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollDocumentAccessService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Veřejná cesta zaměstnance k jeho vlastnímu mzdovému dokumentu. Bez přihlášení.
 *
 *   GET  /api/public/payroll-document/{token}               — stav (skoupý dokud neověřeno)
 *   POST /api/public/payroll-document/{token}/request-code  — poslat jednorázový kód
 *   POST /api/public/payroll-document/{token}/verify        — ověřit kód, založit relaci
 *   GET  /api/public/payroll-document/{token}/download      — vydat PDF (jen s relací)
 *
 * JEDNA ODPOVĚĎ NA VŠECHNO ŠPATNÉ. Neznámý token, prošlý, zneplatněný, ještě
 * neodeslaný i cizí tenant vrací shodné 404 `link_unavailable`. Z odpovědi tak
 * nejde poznat, jestli odkaz kdy existoval — bez toho by šlo hádáním mapovat,
 * kdo u koho pracuje.
 *
 * TOKEN SE NIKDY NELOGUJE ani nevrací v těle odpovědi. Do auditu jde `link_id`.
 *
 * Rate limit řeší {@see \MyInvoice\Middleware\RateLimitMiddleware} podle prefixu
 * cesty; POST má vlastní, přísnější kbelík, protože request-code odesílá poštu
 * a verify je plocha na hádání kódu.
 */
final class PublicPayrollDocumentAccessAction
{
    public function __construct(
        private readonly PayrollDocumentAccessService $access,
        private readonly Config $config,
        private readonly IpMatcher $ipMatcher,
        private readonly PublicTenantGuard $tenantGuard,
    ) {}

    /** @param array<string,string> $args */
    public function state(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $link = $this->live($request, $token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        return $this->noStore(Json::ok(
            $response,
            $this->access->state($link, $this->sessionCookie($request)),
        ));
    }

    /** @param array<string,string> $args */
    public function requestCode(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $link = $this->live($request, $token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        $result = $this->access->issueCode(
            $link,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
        );

        return $this->noStore(Json::ok($response, $result));
    }

    /** @param array<string,string> $args */
    public function verify(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $link = $this->live($request, $token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $sessionToken = $this->access->verifyCode(
            $link,
            (string) ($body['code'] ?? ''),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
        );
        if ($sessionToken === null) {
            // Jediná hláška na „špatný kód", „prošlý kód" i „vyčerpané pokusy".
            return $this->noStore(Json::error(
                $response,
                'invalid_code',
                'Kód není platný nebo už vypršel.',
                422,
            ));
        }

        $out = $this->noStore(Json::ok(
            $response,
            $this->access->state($link, $sessionToken),
        ));

        return $out->withHeader('Set-Cookie', $this->sessionCookieHeader($token, $sessionToken));
    }

    /** @param array<string,string> $args */
    public function download(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $link = $this->live($request, $token);
        if ($link === null) {
            return $this->unavailable($response);
        }

        try {
            $file = $this->access->download($link, $this->sessionCookie($request));
        } catch (PayrollDocumentKeyDestroyedException) {
            // Krypto-výmaz osobních údajů: řádek existuje, obsah je nevratně
            // nečitelný. 410 to říká přesně a nesvádí na chybu serveru.
            return $this->noStore(Json::error(
                $response,
                'payroll_document_erased',
                'Dokument už není k dispozici.',
                410,
            ));
        } catch (\Throwable) {
            return $this->unavailable($response);
        }

        $handle = fopen('php://temp', 'w+b');
        if ($handle === false
            || fwrite($handle, $file['bytes']) !== strlen($file['bytes'])
        ) {
            return $this->noStore(Json::error(
                $response,
                'download_failed',
                'Dokument nelze stáhnout.',
                500,
            ));
        }
        rewind($handle);

        return $this->noStore($response)
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->safeFilename($file['filename']) . '"',
            )
            ->withHeader('Content-Length', (string) strlen($file['bytes']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Dokument se nikdy nerenderuje jako aktivní obsah v kontextu aplikace.
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody(new Stream($handle));
    }

    /**
     * Živý odkaz, nebo null. Sjednocuje kontrolu formátu, stavu a tenantové
     * domény do jednoho místa, aby žádná z větví nemohla vrátit odlišnou odpověď.
     *
     * @return array<string,mixed>|null
     */
    private function live(Request $request, string $token): ?array
    {
        $link = $this->access->resolveLive($token);
        if ($link === null) {
            return null;
        }
        // Odkaz jedné firmy se nesmí otevřít na doméně jiné — jinak by cizí tenant
        // viděl ve svém kontextu naši výplatnici.
        if (!$this->tenantGuard->allows($request, (int) $link['supplier_id'])) {
            return null;
        }
        return $link;
    }

    private function unavailable(Response $response): Response
    {
        return $this->noStore(Json::error(
            $response,
            'link_unavailable',
            'Tento odkaz není platný. Požádejte o nový svou mzdovou účetní.',
            404,
        ));
    }

    private function sessionCookie(Request $request): ?string
    {
        $cookies = $request->getCookieParams();
        $value = $cookies[PayrollDocumentAccessService::COOKIE_NAME] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function sessionCookieHeader(string $token, string $sessionToken): string
    {
        // Path je scoped na konkrétní odkaz: relace k jedné pásce se nikdy
        // neposílá na URL jiné. SameSite=Strict, protože tuhle stránku nikdo
        // legitimně neotevírá cross-site POSTem.
        return sprintf(
            '%s=%s; HttpOnly; Path=/api/public/payroll-document/%s; Max-Age=%d; SameSite=Strict%s',
            PayrollDocumentAccessService::COOKIE_NAME,
            $sessionToken,
            $token,
            $this->sessionMaxAge(),
            (bool) $this->config->get('session.cookie_secure', true) ? '; Secure' : '',
        );
    }

    private function sessionMaxAge(): int
    {
        return (int) $this->config->get(
            'payroll.secure_delivery.session_ttl_seconds',
            7200,
        );
    }

    private function safeFilename(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'dokument.pdf';
        return $clean === '' ? 'dokument.pdf' : substr($clean, 0, 120);
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }
}
