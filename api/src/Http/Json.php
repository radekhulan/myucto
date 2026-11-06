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

    /**
     * Interní značka „hláška u 5xx je napsaná pro uživatele". Odstraní ji
     * {@see \MyInvoice\Middleware\InternalErrorRedactionMiddleware}, ven nejde.
     */
    public const HEADER_PUBLIC_MESSAGE = 'X-MyInvoice-Public-Error';

    public static function error(Response $response, string $code, string $message, int $status = 400, array $extra = []): Response
    {
        $message = ErrorCatalog::lookup($message, Locale::current());
        $response = self::ok($response, [
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);

        return $status >= 500 ? $response->withHeader(self::HEADER_PUBLIC_MESSAGE, '1') : $response;
    }

    /**
     * Chyba, jejíž text pochází z výjimky (SQLSTATE, cesty, hodnoty z dotazu).
     * V produkci ji middleware nahradí obecnou hláškou a původní text zapíše
     * do logu. Pro 5xx s textem výjimky používej tuhle metodu, ne error().
     */
    public static function internalError(Response $response, string $code, string $message, int $status = 500, array $extra = []): Response
    {
        return self::error($response, $code, $message, $status, $extra)->withoutHeader(self::HEADER_PUBLIC_MESSAGE);
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
