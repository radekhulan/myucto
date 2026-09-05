<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\I18n\Locale;
use Psr\Http\Message\ResponseInterface as Response;

final class Json
{
    public static function ok(Response $response, mixed $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function error(Response $response, string $code, string $message, int $status = 400, array $extra = []): Response
    {
        $message = ErrorCatalog::lookup($message, Locale::current());
        return self::ok($response, [
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);
    }

    /**
     * Jednotná odpověď „tohle jde jen z přihlášené relace".
     *
     * ⚠️ JEDEN kód a JEDEN status pro celou plochu. Táž situace se dřív hlásila
     * jako `session_required`, `forbidden_via_token` i `authentication_required`,
     * jednou 401 a jinde 403, v deseti různých formulacích — klient si na to
     * nemohl napsat jednu větev. Middleware si svoje `token_endpoint_forbidden`
     * a `token_write_forbidden` drží dál: ty říkají „sem token nesmí vůbec",
     * kdežto tohle říká „tuhle operaci dělá člověk v aplikaci".
     *
     * @param ?string $why Věcné doplnění pro konkrétní agendu; bez něj obecný text.
     */
    public static function sessionRequired(Response $response, ?string $why = null): Response
    {
        return self::error(
            $response,
            'session_required',
            $why ?? 'Tento endpoint je dostupný pouze z přihlášené relace.',
            403,
        );
    }
}
