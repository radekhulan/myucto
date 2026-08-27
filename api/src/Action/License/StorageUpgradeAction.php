<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/license/storage {quota_gb} — rozšíření úložiště. Licenční server
 * strhne poměrný doplatek z uložené karty a zvedne kvótu u provozovatele
 * (admin only).
 *
 * ⚠️ Odpověď nese `provisioning_pending`: zaplaceno, ale kvóta se ještě
 * nezvedla. Není to chyba — peníze odešly a obrazovka nesmí nabídnout nákup
 * znovu.
 */
final class StorageUpgradeAction
{
    private const ERROR_MESSAGES = [
        'invalid_key'           => 'Aktivní licence nenalezena. Nejprve aktivujte licenční klíč.',
        'not_upgradable'        => 'Tuto licenci nelze rozšířit.',
        'not_hosted'            => 'Rozšíření úložiště je možné jen u provozu zajištěného námi.',
        'not_managed'           => 'Úložiště se dokupuje jen u provozu zajištěného námi.',
        'instance_missing'      => 'K licenci není vedená žádná instance.',
        'instance_not_active'   => 'Instance právě neběží. Rozšíření je možné až po jejím obnovení.',
        'instance_required'     => 'U hostovaného provozu je pro rozšíření nutné ověření této instalace.',
        'not_bound'             => 'Tato instalace není k licenci aktivně přiřazená.',
        'invalid_quota'         => 'Zvolte prosím jednu z nabízených velikostí úložiště.',
        'not_an_upgrade'        => 'Zvolená velikost není větší než ta, kterou už máte.',
        'subscription_inactive' => 'Předplatné není aktivní. Nejdřív je potřeba srovnat platbu.',
        'no_parent_payment'     => 'Rozšíření je možné jen u předplatného s uloženou kartou.',
        'cannot_prorate'        => 'Doplatek se nepodařilo spočítat. Ozvěte se prosím podpoře.',
        'charge_failed'         => 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
            . 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
        'charge_pending'        => 'Platba se zpracovává. Nekupujte prosím znovu — jakmile ji brána potvrdí, změna se projeví sama.',
        'payments_disabled'     => 'Platby jsou dočasně pozastavené. Zkuste to prosím později.',
        'server_unreachable'    => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        // ⚠️ Nepobízet k opakování: platba mohla proběhnout a ztratila se jen odpověď.
        'result_unknown'        => 'Nevíme, jak platba dopadla. Nezkoušejte to prosím znovu — '
            . 'za chvíli obnovte stránku, a pokud se nic nezmění, ozvěte se podpoře.',
        'upgrade_failed'        => 'Rozšíření se nezdařilo. Zkuste to prosím znovu.',
    ];

    /**
     * Odkaz na zaplacení doplatku, když ho licenční server u odmítnutí poslal.
     *
     * ⚠️ Bez něj obrazovka po neprojité kartě jen řekne „zkuste to znovu" —
     * a druhý pokus se stejnou kartou dopadne stejně. Odkaz vede na platbu
     * téže objednávky, takže se nic neduplikuje.
     *
     * @param  array<string,mixed> $result
     * @return array<string,string>
     */
    private static function payLink(array $result): array
    {
        $payUrl = isset($result['pay_url']) ? (string) $result['pay_url'] : '';

        return $payUrl === '' ? [] : ['pay_url' => $payUrl];
    }

    public static function message(string $error): string
    {
        return self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['upgrade_failed'];
    }

    public function __construct(
        private readonly LicenseService $license,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        if (!$this->managed->isManaged()) {
            return Json::error($response, 'not_managed', self::message('not_managed'), 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $quotaGb = (int) ($body['quota_gb'] ?? 0);
        $quoteToken = trim((string) ($body['quote_token'] ?? ''));
        if ($quotaGb < 1) {
            return Json::error($response, 'validation_failed', 'Zadejte cílovou velikost úložiště.', 400);
        }
        if ($quoteToken === '') {
            return Json::error($response, 'quote_required', 'Nejdříve si nechte spočítat aktuální cenu.', 400);
        }

        $result = $this->license->storageUpgrade($quotaGb, $quoteToken);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'upgrade_failed');
            $status = $error === 'server_unreachable' ? 503 : 422;

            return Json::error($response, $error, self::message($error), $status, self::payLink($result));
        }

        return Json::ok($response, [
            'new_quota_gb'         => $result['new_quota_gb'] ?? $quotaGb,
            'amount_charged'       => $result['amount_charged'] ?? null,
            'provisioning_pending' => $result['provisioning_pending'] ?? false,
            'scheduled'            => (bool) ($result['scheduled'] ?? false),
            'effective_at'         => $result['effective_at'] ?? null,
            'pending'              => (bool) ($result['pending'] ?? false),
            'order_id'             => isset($result['order_id']) ? (string) $result['order_id'] : null,
            'state'                => $result['state']->toArray($this->license->buyUrl()),
        ]);
    }
}
