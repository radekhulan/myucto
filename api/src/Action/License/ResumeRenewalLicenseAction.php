<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/resume-renewal — obnova zrušeného předplatného (admin only).
 *
 * ⚠️ Odpověď nese `pay_url` a předplatné je do zaplacení pořád zrušené.
 * Zrušením se totiž zneplatnil mandát u platební brány; bez nové karty by se
 * příští obnova jen znovu nestrhla a zákazník by se to dozvěděl až tím, že
 * mu přestane fungovat instalace. Obrazovka proto na `pay_url` přesměruje.
 */
final class ResumeRenewalLicenseAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'              => 'Instalace nemá aktivní licenční klíč.',
        'not_bound'                => 'Licence není navázaná na tuto instalaci.',
        'no_subscription'          => 'K této licenci nepatří žádné předplatné.',
        'not_cancelled'            => 'Předplatné běží — obnovovat není co.',
        // ⚠️ Nepobízet k opakování: tohle samoobsluha nespraví.
        'instance_not_restorable'  => 'Provoz téhle instalace už jsme ukončili. Data ještě můžeme mít — '
            . 'ozvěte se prosím podpoře, obnovíme ji ručně.',
        'payments_disabled'        => 'Platby jsou dočasně pozastavené. Zkuste to prosím později.',
        'hosted_price_unavailable' => 'Cenu obnovy se nepodařilo spočítat. Ozvěte se prosím podpoře.',
        'server_unreachable'       => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'resume_failed'            => 'Obnovu se nepodařilo spustit. Zkuste to prosím znovu.',
    ];

    public static function message(string $error): string
    {
        return self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['resume_failed'];
    }

    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $result = $this->license->resumeRenewal();
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'resume_failed');
            $status = $error === 'server_unreachable' ? 503 : 422;

            return Json::error($response, $error, self::message($error), $status);
        }

        return Json::ok($response, [
            'pay_url'     => (string) ($result['pay_url'] ?? ''),
            'valid_until' => $result['valid_until'] ?? null,
        ]);
    }
}
