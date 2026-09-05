<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/license/purchase/start — založí serverový PKCE checkout handoff. */
final class PurchaseStartAction
{
    private const ERROR_MESSAGES = [
        'already_licensed'   => 'Tato instalace už má aktivní licenci. Nové předplatné by ji nenavýšilo.',
        'invalid_return_url' => 'Adresa aplikace není platná HTTPS adresa pro návrat z platby.',
        'schema_outdated'    => 'Databáze aplikace není aktualizovaná. Nejprve spusťte migrace.',
        'server_unreachable' => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'purchase_failed'    => 'Nákup se nepodařilo zahájit. Zkuste to prosím znovu.',
    ];

    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response, 'Tato operace vyžaduje přihlášení v prohlížeči.');
        }
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $result = $this->license->startPurchaseHandoff();
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'purchase_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['purchase_failed'];
            $status = match ($error) {
                'already_licensed' => 409,
                'server_unreachable' => 503,
                default => 422,
            };
            return Json::error($response, $error, $message, $status);
        }

        return Json::ok($response, [
            'buy_url'    => (string) $result['buy_url'],
            'expires_in' => (int) $result['expires_in'],
        ]);
    }
}
