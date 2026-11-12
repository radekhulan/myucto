<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth\Tokens;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SystemWriteTokenFeature;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/tokens — výpis vlastních API tokenů (bez plaintextu).
 *
 * `system_write_available` říká formuláři, zda instance povoluje plný zápis (#371).
 */
final class ListTokensAction
{
    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly SystemWriteTokenFeature $systemWrite,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return Json::ok($response, [
            'tokens' => $this->tokens->listForUser($userId),
            'system_write_available' => $this->systemWrite->isEnabled(),
        ]);
    }
}
