<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** POST /api/license/purchase/complete {purchase,state} — claim a aktivace zaplacené licence. */
final class PurchaseCompleteAction
{
    private const ERROR_MESSAGES = [
        'invalid_request'       => 'Návrat z objednávky není platný.',
        'handoff_not_started'   => 'V této instalaci není rozpracovaný nákup.',
        'handoff_expired'       => 'Platnost automatického návratu vypršela. Aktivujte licenci ručně.',
        'invalid_handoff'       => 'Návrat z objednávky nepatří této instalaci.',
        'payment_pending'       => 'Platba se ještě zpracovává. Zkuste to prosím za chvíli.',
        'payment_failed'        => 'Platba nebyla dokončena.',
        'license_unavailable'   => 'Zaplacenou licenci se nepodařilo načíst. Kontaktujte podporu.',
        'invalid_token'         => 'Licenční server vrátil neplatný token.',
        'schema_outdated'       => 'Databáze aplikace není aktualizovaná. Nejprve spusťte migrace.',
        'server_unreachable'    => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'activation_failed'     => 'Automatická aktivace se nezdařila. Aktivujte licenci ručně.',
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

        $body = (array) ($request->getParsedBody() ?? []);
        $purchase = trim((string) ($body['purchase'] ?? ''));
        $state = trim((string) ($body['state'] ?? ''));
        if ($purchase === '' || $state === '') {
            return Json::error($response, 'invalid_request', self::ERROR_MESSAGES['invalid_request'], 400);
        }

        $result = $this->license->completePurchaseHandoff($purchase, $state);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'activation_failed');
            $message = self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['activation_failed'];
            $status = match ($error) {
                'invalid_request' => 400,
                'handoff_not_started', 'handoff_expired' => 410,
                'payment_pending' => 409,
                'server_unreachable' => 503,
                default => 422,
            };
            return Json::error($response, $error, $message, $status);
        }

        return Json::ok($response, $result['state']->toArray($this->license->buyUrl()));
    }
}
