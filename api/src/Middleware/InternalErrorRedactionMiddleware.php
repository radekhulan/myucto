<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\I18n\Locale;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Produkční 500 nesmí nést surový text výjimky.
 *
 * Řada akcí vrací v `catch` větvi `Json::error(..., $e->getMessage(), 500)`.
 * Text výjimky přitom obsahuje SQLSTATE, jména omezení, hodnoty z dotazu nebo
 * absolutní cesty na serveru, tedy přesně to, co `app.debug = false` skrývá
 * u nezachycených výjimek. Oprava v ~70 akcích by se časem znovu rozešla,
 * proto se odpověď čistí centrálně tady.
 *
 * Co se mění: JSON odpověď se stavem 500 (a dalšími 5xx kromě výjimek níže)
 * dostane obecnou hlášku a `request_id`. Původní hláška jde do aplikačního logu
 * pod stejným `request_id`, takže ji správce dohledá. Kód chyby (`error.code`)
 * i ostatní pole zůstávají, frontend se podle nich dál rozhoduje.
 *
 * Co se NEmění:
 *   - 4xx (validace, 409 konflikty, 422 business pravidla) jsou záměrné hlášky,
 *   - 5xx z `Json::error()`, tedy hláška napsaná pro uživatele (například
 *     „Úložiště jobů není zapisovatelné."). Text výjimky posílá akce přes
 *     `Json::internalError()`, což hlídá InternalErrorMessageGuardTest,
 *   - 502/503/504 hlásí nedostupnost protistrany (SMTP, ARES, údržba) a jejich
 *     text uživatel potřebuje k nápravě nastavení,
 *   - 507 nese stav diskové kvóty,
 *   - vývojové prostředí (`app.env = development`) a zapnuté `app.debug`, kde
 *     je detail chyby žádoucí.
 *
 * Middleware sedí jako nejvnější uživatelská vrstva (hned pod ErrorMiddleware),
 * aby pokryl i odpovědi vrácené jinými middleware.
 */
final class InternalErrorRedactionMiddleware implements MiddlewareInterface
{
    /** Stavy 5xx, jejichž hláška je záměrná a uživatel ji potřebuje vidět. */
    private const PRESERVED_STATUSES = [502, 503, 504, 507];

    public const HEADER_REQUEST_ID = 'X-Request-Id';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        $public = $response->hasHeader(Json::HEADER_PUBLIC_MESSAGE);
        $response = $response->withoutHeader(Json::HEADER_PUBLIC_MESSAGE);

        $status = $response->getStatusCode();
        if ($status < 500 || in_array($status, self::PRESERVED_STATUSES, true) || $public) {
            return $response;
        }
        if ($this->exposesDetails()) {
            return $response;
        }
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) {
            return $response;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = (string) $body->getContents();

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->restoredBody($response, $raw);
        }
        if (!is_array($payload) || !isset($payload['error']) || !is_array($payload['error'])) {
            return $this->restoredBody($response, $raw);
        }

        $requestId = bin2hex(random_bytes(8));
        $original = $payload['error']['message'] ?? null;

        $this->logger->error('API vrátilo 500, detail chyby byl v odpovědi skryt.', [
            'request_id' => $requestId,
            'status' => $status,
            'code' => is_scalar($payload['error']['code'] ?? null) ? (string) $payload['error']['code'] : null,
            'message' => is_scalar($original) ? (string) $original : null,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
        ]);

        $payload['error']['message'] = self::genericMessage($requestId);
        $payload['error']['request_id'] = $requestId;

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode([
                'error' => [
                    'code' => 'internal_error',
                    'message' => self::genericMessage($requestId),
                    'request_id' => $requestId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return $response
            ->withBody((new StreamFactory())->createStream($encoded))
            ->withoutHeader('Content-Length')
            ->withHeader(self::HEADER_REQUEST_ID, $requestId);
    }

    /**
     * Detail chyby smí ven jen ve vývoji nebo se zapnutým `app.debug`. Ve
     * spravované instalaci `app.debug` neplatí (stejné pravidlo jako u
     * ErrorMiddleware v `Bootstrap::buildApp()`).
     */
    private function exposesDetails(): bool
    {
        if ((string) $this->config->get('app.env', 'production') === 'development') {
            return true;
        }

        return (new ManagedModeGuard($this->config))->effectiveFlag(
            ManagedModeGuard::KEY_APP_DEBUG,
            (bool) $this->config->get('app.debug', false),
        );
    }

    private static function genericMessage(string $requestId): string
    {
        return Locale::current() === 'en'
            ? sprintf('Internal server error. Details are in the application log under request ID %s.', $requestId)
            : sprintf('Interní chyba serveru. Podrobnosti jsou v logu aplikace pod kódem požadavku %s.', $requestId);
    }

    /** Body jsme přečetli; vrátíme ho beze změny, ať ho další vrstva umí odeslat. */
    private function restoredBody(Response $response, string $raw): Response
    {
        return $response->withBody((new StreamFactory())->createStream($raw));
    }
}
