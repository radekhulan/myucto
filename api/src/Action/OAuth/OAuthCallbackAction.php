<?php

declare(strict_types=1);

namespace MyInvoice\Action\OAuth;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\OAuth\OAuthCallbackDispatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/oauth/callback — společný návrat z autorizačního serveru pro napojení, kde je
 * MyÚčto klientem OAuth ({@see \MyInvoice\Service\OAuth\OAuthClientFlow}). Jen přihlášený
 * uživatel v prohlížeči; oprávnění ve firmě přihlášení ověřuje handler domény.
 */
final class OAuthCallbackAction
{
    public function __construct(private readonly OAuthCallbackDispatcher $dispatcher) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'forbidden', 'Přihlášení ke službě lze dokončit jen po přihlášení v aplikaci.', 403)
                ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        }
        return self::redirect($response, $this->dispatcher->dispatch($request) ?? '/?oauth=expired');
    }

    public static function redirect(Response $response, string $location): Response
    {
        return $response->withHeader('Location', $location)->withStatus(302)
            ->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
