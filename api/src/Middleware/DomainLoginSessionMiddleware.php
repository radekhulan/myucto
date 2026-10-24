<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\DomainLoginSessionCookie;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/** Odděluje canonical účet od nového přihlášení otevřeného z klientské domény. */
final class DomainLoginSessionMiddleware implements MiddlewareInterface
{
    public const ATTR_VERIFIED_FLOW = 'auth.domain_login_verified_flow';
    public const REQUEST_HEADER = 'X-Domain-Login-Request';
    public const STATE_HEADER = 'X-Domain-Login-State';

    public function __construct(
        private readonly Connection $db,
        private readonly DomainLoginSessionCookie $cookies,
        private readonly ClockInterface $clock,
        private readonly ResponseFactory $responses,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!$request->hasHeader(self::REQUEST_HEADER) && !$request->hasHeader(self::STATE_HEADER)) {
            return $handler->handle($request);
        }
        $requestToken = $request->getHeaderLine(self::REQUEST_HEADER);
        $state = $request->getHeaderLine(self::STATE_HEADER);
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        if (!$context instanceof TenantDomainContext || $context->mode !== TenantDomainContext::CANONICAL
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $requestToken) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) !== 1
            || $request->hasHeader('Authorization')
        ) return $this->invalid();

        $standardName = $this->cookies->standardName();
        $flowName = $this->cookies->name($requestToken);
        $cookieParams = $request->getCookieParams();
        $token = $this->cookies->open((string) ($cookieParams[$flowName] ?? ''), $requestToken, $state);
        unset($cookieParams[$standardName]);
        // Žádný fallback na canonical cookie, ani při chybné či chybějící obálce.
        if ($token !== null) {
            $cookieParams[$standardName] = $token;
            $request = $request->withAttribute(self::ATTR_VERIFIED_FLOW, [
                'request_token_hash' => hash('sha256', $requestToken),
                'state_hash' => hash('sha256', $state),
            ]);
        }
        $request = $request->withCookieParams($cookieParams);
        $path = RequestPath::normalize($request->getUri()->getPath());
        $method = strtoupper($request->getMethod());
        // Úklid vlastní dočasné session musí jít opakovat i po exchange či expiraci requestu.
        // Cookie může expirovat současně s requestem; bez ní AuthMiddleware vrátí 401,
        // takže klient dokončí úklid, aniž by mohl použít canonical přihlášení.
        $cleanup = ($method === 'POST' && $path === '/api/auth/logout')
            || ($method === 'GET' && $path === '/api/auth/session/status');
        $expiresAt = $this->clock->now()->getTimestamp();
        if (!$cleanup) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT UNIX_TIMESTAMP(r.expires_at) AS expires_at
                   FROM supplier_domain_login_requests r
                   JOIN supplier_domains d ON d.id = r.supplier_domain_id AND d.supplier_id = r.supplier_id
                  WHERE r.request_token_hash = ? AND r.state_hash = ?
                    AND r.expires_at > UTC_TIMESTAMP(6) AND r.used_at IS NULL
                    AND d.status = 'active' AND d.purpose IN ('portal', 'all')
                  LIMIT 1"
            );
            $stmt->execute([hash('sha256', $requestToken), hash('sha256', $state)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) return $this->invalid();
            $expiresAt = (int) $row['expires_at'];
        }

        $response = $handler->handle($request);
        $freshLogin = $method === 'POST' && in_array($path, [
            '/api/auth/login', '/api/auth/webauthn/login/verify',
        ], true) && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        $rewritten = [];
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (!str_starts_with($cookie, $standardName . '=')) {
                $rewritten[] = $cookie;
                continue;
            }
            [$pair, $attributes] = array_pad(explode(';', $cookie, 2), 2, '');
            $value = substr($pair, strlen($standardName) + 1);
            if ($value === '') {
                $rewritten[] = $flowName . '=;' . $attributes;
                continue;
            }
            // Pouze nové ověření nebo rotace už ověřené flow smí vytvořit obálku.
            if (!$freshLogin && $token === null) continue;
            $value = $this->cookies->seal($value, $requestToken, $state);
            $maxAge = max(0, min(600, $expiresAt - $this->clock->now()->getTimestamp()));
            $attributes = preg_replace('/;?\s*(Max-Age|Expires)=[^;]*/i', '', ';' . $attributes) ?? '';
            $rewritten[] = $flowName . '=' . $value . $attributes . '; Max-Age=' . $maxAge
                . '; Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $expiresAt);
        }
        $response = $rewritten === []
            ? $response->withoutHeader('Set-Cookie')
            : $response->withHeader('Set-Cookie', $rewritten);
        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function invalid(): Response
    {
        return Json::error($this->responses->createResponse(), 'invalid_domain_login_request',
            'Požadavek na přihlášení je neplatný nebo expiroval.', 400);
    }
}
