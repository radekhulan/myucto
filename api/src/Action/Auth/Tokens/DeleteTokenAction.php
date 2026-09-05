<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/auth/tokens/{id}/purge — trvale smaže vlastní API token.
 *
 * Doplněk k {@see RevokeTokenAction}, ne jeho náhrada. Zrušení nechá v přehledu
 * náhrobek a v logu volání celou historii; mazání uklidí i ten náhrobek a řádky
 * `api_request_log` po něm zůstanou bez přiřazení k tokenu (FK je SET NULL).
 * Kdo chce vědět, kdo co volal, ruší; kdo chce čistý přehled, maže.
 *
 * ⚠️ Session-only, stejně jako vytváření tokenu. `ApiScopeMiddleware` bearer
 * na `/api/auth/*` nepouští, ale eskalační brána patří i přímo sem: token,
 * který by uměl smazat sám sebe nebo své sourozence, maže i stopu po tom,
 * co dělal.
 */
final class DeleteTokenAction
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'API tokeny lze spravovat jen z webového rozhraní.');
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $tokenId = (int) ($args['id'] ?? 0);
        if ($tokenId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí ID tokenu.', 400);
        }

        $deleted = $this->tokens->delete($tokenId, $userId);
        if ($deleted === null) {
            return Json::error($response, 'not_found', 'Token nenalezen nebo nepatří uživateli.', 404);
        }

        // Snímek do auditu: po smazání řádku je tohle jediné, z čeho se dá zjistit,
        // co za token zmizelo a co uměl.
        $ip = $this->ipMatcher->clientIp($request->getServerParams(), [], 'X-Forwarded-For');
        $this->activity->log(
            'api_token.deleted',
            $userId,
            'api_token',
            $tokenId,
            [
                'name'   => $deleted['name'],
                'prefix' => $deleted['prefix'],
                'scope'  => $deleted['scope'],
                'allow_payroll_submission_docs' => $deleted['allow_payroll_submission_docs'],
                // Mazání aktivního tokenu je zároveň jeho odstřižením — integrace
                // přestane fungovat okamžitě a v přehledu po ní nezbyde stopa.
                'was_revoked' => $deleted['revoked_at'] !== null,
            ],
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['ok' => true]);
    }
}
