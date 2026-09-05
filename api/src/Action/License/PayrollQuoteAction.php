<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollQuoteAction
{
    /**
     * ⚠️ Jedna společná hláška nestačí. „Předplatné nemá uloženou kartu" a
     * „zvolili jste míň, než kolik opravdu používáte" jsou dvě různé situace
     * s dvěma různými řešeními — bez rozlišení zákazník vidí jen to, že se
     * cena nespočítala, a nemá co udělat.
     */
    public const ERROR_MESSAGES = [
        'invalid_key' => 'Aktivní licence nenalezena. Nejprve aktivujte licenční klíč.',
        'subscription_inactive' => 'Poměrný doplatek jde strhnout jen z předplatného s uloženou kartou. '
            . 'Mzdový doplněk si objednejte na myucto.cz.',
        'target_below_active' => 'Cílové počty nesmí být nižší než skutečné využití. '
            . 'Nejprve deaktivujte zaměstnance nebo odeberte práva k mzdám.',
        'invalid_target' => 'Cílové počty jsou mimo povolený rozsah.',
        'invalid_telemetry' => 'Skutečné využití mezd se nepodařilo změřit. Zkuste to prosím znovu.',
        'payroll_price_unavailable' => 'Ceník mzdového doplňku je momentálně nedostupný. Zkuste to prosím za chvíli.',
        'upgrade_in_progress' => 'Předchozí změna licence se ještě zpracovává. Zkuste to prosím za chvíli.',
        'server_unreachable' => 'Licenční server je nedostupný. Zkuste to prosím za chvíli.',
        'quote_failed' => 'Změnu mzdového doplňku se nepodařilo spočítat.',
    ];

    /** Chyby, ze kterých vede cesta ven jen novým nákupem na webu. */
    public const BUY_URL_ERRORS = ['subscription_inactive', 'invalid_key'];

    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            return Json::error($response, 'validation_failed', 'enabled musí být boolean.', 400);
        }
        if (array_key_exists('payroll_employees_target', $body)
            && (!is_int($body['payroll_employees_target'])
                || $body['payroll_employees_target'] < 0
                || $body['payroll_employees_target'] > LicenseService::MAX_PAYROLL_EMPLOYEES_TARGET)
        ) {
            return Json::error($response, 'validation_failed', 'payroll_employees_target je mimo povolený rozsah.', 422);
        }
        if (array_key_exists('payroll_users_target', $body)
            && (!is_int($body['payroll_users_target'])
                || $body['payroll_users_target'] < 1
                || $body['payroll_users_target'] > LicenseService::MAX_PAYROLL_USERS_TARGET)
        ) {
            return Json::error($response, 'validation_failed', 'payroll_users_target musí být celé číslo od 1 do 100.', 422);
        }
        $result = $this->license->payrollQuote(
            $body['enabled'],
            $body['payroll_employees_target'] ?? null,
            $body['payroll_users_target'] ?? null,
        );
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'quote_failed');
            return Json::error(
                $response,
                $error,
                self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['quote_failed'],
                $error === 'server_unreachable' ? 503 : 422,
                in_array($error, self::BUY_URL_ERRORS, true) ? ['buy_url' => $this->license->buyUrl()] : [],
            );
        }
        unset($result['ok']);
        return Json::ok($response, $result);
    }
}
