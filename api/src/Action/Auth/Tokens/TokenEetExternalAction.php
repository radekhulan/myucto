<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/auth/tokens/{id}/eet-external { enabled } — volba „Úhrady z tohoto klíče
 * eviduje EET externí systém (e-shop)" (session-only, jako zbytek /api/auth/tokens).
 *
 * Úhrada faktury přijatá tímto tokenem bez `eet_context` a bez `channel` se pak bere
 * jako `eet_context = external`: MyÚčto ji zapíše účetně a tržbu jen zaznamená, ale
 * neodešle (e-shop ji evidoval sám). Změna mění, co MyÚčto hlásí správci daně, proto
 * jde do `activity_log`.
 */
final class TokenEetExternalAction
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        $tokenId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($tokenId <= 0 || !array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return Json::error($response, 'validation_failed', 'Pošlete enabled: true nebo false.', 400);
        }
        if (!$this->tokens->setEetExternalByDefault($tokenId, $userId, $body['enabled'])) {
            return Json::error($response, 'not_found', 'Token nenalezen nebo nepatří uživateli.', 404);
        }
        $this->activity->log(
            'api_token.eet_external_changed',
            $userId,
            'api_token',
            $tokenId,
            ['eet_external_by_default' => $body['enabled']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, ['id' => $tokenId, 'eet_external_by_default' => $body['enabled']]);
    }
}
