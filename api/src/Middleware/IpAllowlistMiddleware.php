<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * První middleware v pipeline. Pokud je `cfg.ip_allowlist.enabled = true`
 * a request přijde z IP mimo allowlist, vrací 403 (mode=block) nebo
 * jen logguje warning (mode=log_only).
 */
final class IpAllowlistMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly IpMatcher $ipMatcher,
        private readonly ActivityLogger $logger,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if (!$this->config->get('ip_allowlist.enabled', false)) {
            return $handler->handle($request);
        }

        $rules    = (array) $this->config->get('ip_allowlist.allow', []);
        $proxies  = (array) $this->config->get('ip_allowlist.trusted_proxies', []);
        $header   = (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For');
        $applyTo  = (string) $this->config->get('ip_allowlist.apply_to', 'all');
        $mode     = (string) $this->config->get('ip_allowlist.mode', 'block');

        $ip = $this->ipMatcher->clientIp($request->getServerParams(), $proxies, $header);

        // Rozhodovací prefixy porovnáváme na normalizované cestě (jedno rawurldecode
        // jako router). Bez toho `/api/%61dmin/users` na str_starts_with nesedělo,
        // IP kontrola se v režimu admin_only PŘESKOČILA a router Action stejně doručil.
        $path = RequestPath::normalize($request->getUri()->getPath());

        // apply_to=mutations_only — povol GET/HEAD/OPTIONS bez kontroly
        if ($applyTo === 'mutations_only' && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        // Technická správa instance patří pod stejnou IP bránu jako ostatní administrace.
        if ($applyTo === 'admin_only' && !str_starts_with($path, '/api/admin/')
            && !str_starts_with($path, '/api/instance-admin/')) {
            return $handler->handle($request);
        }

        // /api/public/* — vždy povolíme (zákazníci přicházejí z libovolných IP přes
        // schvalovací email link). Anti-bot ochrana = token v URL + CAPTCHA.
        if (str_starts_with($path, '/api/public/')) {
            return $handler->handle($request);
        }

        if ($this->ipMatcher->matches($ip, $rules)) {
            return $handler->handle($request);
        }

        // Mimo allowlist
        // Logujeme i hash UA pro snazší clustering opakovaných pokusů (privacy: full UA jde do plain-text payloadu zvlášť)
        $this->logger->log('security.ip_blocked', null, null, null, [
            'ip'      => $ip,
            'ua_hash' => substr(sha1($request->getHeaderLine('User-Agent')), 0, 12),
            'method'  => $request->getMethod(),
            // Do logu schválně SYROVÁ cesta — chceme vidět, co klient reálně poslal.
            'path'    => $request->getUri()->getPath(),
            'mode'    => $mode,
        ], $ip, $request->getHeaderLine('User-Agent'));

        if ($mode === 'log_only') {
            return $handler->handle($request);
        }

        $response = $this->responseFactory->createResponse(403);

        // Detekce: API klient (chce JSON) vs prohlížeč (chce HTML)
        $accept   = $request->getHeaderLine('Accept');
        $isApi    = str_starts_with($path, '/api/')
                 || str_contains($accept, 'application/json');

        if ($isApi) {
            return Json::error($response, 'ip_not_allowed', 'Tato IP adresa nemá přístup k aplikaci.', 403, ['ip' => $ip]);
        }

        // HTML 403 stránka
        $htmlPath = Bootstrap::rootDir() . '/styles/blocked.html';
        $html = is_file($htmlPath) ? (string) file_get_contents($htmlPath) : '<h1>403 Forbidden</h1>';
        $html = str_replace('__CLIENT_IP__', htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'), $html);
        // Pro statickou stránku, kde IP zobrazujeme přímo:
        $html = preg_replace('/(<div class="ip" id="ip">)—(<\/div>)/', '$1' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '$2', $html);

        $response->getBody()->write($html);
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
