<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\MfaOfferService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Odmítnutí dobrovolné nabídky MFA (migrace 1572).
 *
 *   POST /api/auth/mfa/offer/dismiss   „pokračovat bez dvoufázového ověření"
 *
 * Endpoint jen zhasíná nabídku; nic nepovoluje ani neodemyká. Přesto vyžaduje
 * session (ne API token) ze stejného důvodu jako zbytek self-service MFA API:
 * dlouhodobé pověření nemá co rozhodovat o faktorech, kterými se jeho vlastník
 * přihlašuje.
 *
 * Při `auth.require_mfa = true` vrací 409 — vynucené MFA odmítnout nelze a tichý
 * úspěch by budil dojem, že to šlo (viz MfaOfferService::dismiss).
 */
final class MfaOfferAction
{
    public function __construct(
        private readonly MfaOfferService $offers,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function dismiss(Request $request, Response $response): Response
    {
        $userId = $this->sessionUserId($request);
        if ($userId === null) {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }

        if (!$this->offers->dismiss($userId)) {
            return Json::error(
                $response,
                'mfa_required',
                'Dvoufázové ověření je na této instalaci povinné, odmítnout ho nelze.',
                409,
            );
        }

        $this->logger->log(
            'auth.mfa_offer_dismissed',
            $userId,
            'user',
            $userId,
            [],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            (string) ($request->getServerParams()['HTTP_USER_AGENT'] ?? ''),
        );

        return Json::ok($response, ['dismissed' => true]);
    }

    private function sessionUserId(Request $request): ?int
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return null;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
