<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Přesměrování u volání AI poskytovatelů.
 *
 * Endpoint za proxy (typicky vlastní APIM na `*.azure-api.net`) smí odpovědět
 * jedním přesměrováním v rámci téhož hostu, například na kanonickou cestu.
 * Přesměrování na jiný host se nenásleduje: s požadavkem by odešel klíč
 * (`api-key`, `x-api-key` ani `x-goog-api-key` Guzzle při změně hostu neodebere)
 * i obsah dotazu, a to i do interní sítě provozovatele.
 */
final class AiRedirectPolicy
{
    public const MAX_REDIRECTS = 1;

    /** @return array<string,mixed> hodnota volby `allow_redirects` pro Guzzle */
    public static function options(): array
    {
        return [
            'max' => self::MAX_REDIRECTS,
            'strict' => true,
            'referer' => false,
            'protocols' => ['https'],
            'track_redirects' => false,
            'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $target): void {
                if (!self::sameHost($request->getUri(), $target)) {
                    throw new RequestException('ai_redirect_foreign_host', $request, $response);
                }
            },
        ];
    }

    public static function sameHost(UriInterface $from, UriInterface $to): bool
    {
        return strtolower($from->getHost()) === strtolower($to->getHost())
            && $from->getPort() === $to->getPort();
    }
}
