<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Per-request log volání veřejného API bearer tokenem (→ `api_request_log`).
 *
 * Session (browser SPA) se NELOGUJE — ta má vlastní `activity_log` a zdvojení by
 * tabulku zbytečně nafouklo.
 *
 * Běží HNED PO AuthMiddleware, tedy nad všemi ostatními kontrolami (scope, práva,
 * licence, rate limit). Díky tomu se do logu dostanou i zamítnutá volání — což je
 * ta zajímavější polovina: „MCP sáhl na endpoint, na který token nemá scope".
 * Zamítnutí kvůli neplatnému tokenu / zakázané IP loguje sám AuthMiddleware,
 * protože k nim dojde dřív, než se sem request vůbec dostane.
 */
final class ApiRequestLogMiddleware implements MiddlewareInterface
{
    /** Nad tenhle limit už tělo chybové odpovědi nečteme kvůli `error.code`. */
    private const MAX_ERROR_BODY = 8192;

    public function __construct(
        private readonly ApiRequestLogger $log,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!RequestAuthorization::isBearerAuth($request)) {
            return $handler->handle($request);
        }

        $startedAt = hrtime(true);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // Neúspěch se musí do logu dostat taky, jinak by tam po pádu zůstala
            // díra a vypadalo by to, že MCP nic nevolal.
            $this->write($request, 500, $startedAt, 'exception');
            throw $e;
        }

        $status = $response->getStatusCode();
        $this->write($request, $status, $startedAt, $status >= 400 ? self::errorCode($response) : '');

        return $response;
    }

    private function write(Request $request, int $status, int $startedAt, string $errorCode): void
    {
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $user  = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        // Token vázaný na firmu určuje supplier autoritativně (SupplierScopeMiddleware
        // pak hlavičku ignoruje); u nevázaného tokenu je hlavička jediný signál.
        $supplierId = $token['supplier_id'] ?? null;
        if ($supplierId === null) {
            $header = trim($request->getHeaderLine('X-Supplier-Id'));
            $supplierId = ctype_digit($header) ? (int) $header : null;
        }

        $this->log->log([
            'token_id'       => isset($token['id']) ? (int) $token['id'] : null,
            'user_id'        => isset($user['id']) ? (int) $user['id'] : null,
            'supplier_id'    => $supplierId,
            'ip'             => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'method'         => $request->getMethod(),
            // Schválně SYROVÁ cesta (bez RequestPath::normalize) — do logu patří to,
            // co klient reálně poslal, včetně percent-encodingu.
            'route'          => $request->getUri()->getPath(),
            'query'          => $request->getUri()->getQuery(),
            'status'         => $status,
            'duration_ms'    => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'scope_used'     => (string) ($token['scope'] ?? ''),
            'client'         => $request->getHeaderLine('X-MyUcto-Client'),
            'client_version' => $request->getHeaderLine('X-MyUcto-Client-Version'),
            'tool'           => $request->getHeaderLine('X-MyUcto-Tool'),
            'error_code'     => $errorCode,
        ]);
    }

    /**
     * Vytáhne `error.code` z těla chybové odpovědi (tvar {@see \MyInvoice\Http\Json::error}).
     * Tělo se po přečtení převine zpět, aby se odpověď klientovi neodeslala prázdná.
     */
    private static function errorCode(Response $response): string
    {
        $body = $response->getBody();
        if (!$body->isSeekable() || ($body->getSize() ?? 0) > self::MAX_ERROR_BODY) {
            return '';
        }

        try {
            $body->rewind();
            $raw = $body->getContents();
            $body->rewind();
        } catch (\Throwable) {
            return '';
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : '';
    }
}
