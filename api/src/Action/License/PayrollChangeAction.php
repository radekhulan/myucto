<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollChangeAction
{
    /** Hlášky kalkulace plus to, co může selhat až u stržení. */
    public const ERROR_MESSAGES = PayrollQuoteAction::ERROR_MESSAGES + [
        'charge_failed' => 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
            . 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
        'charge_pending' => 'Platba se zpracovává. Nekupujte prosím znovu — jakmile ji brána potvrdí, změna se projeví sama.',
        'result_unknown' => 'Odpověď licenčního serveru nedorazila. Než to zkusíte znovu, ověřte prosím stav licence — '
            . 'změna mohla proběhnout.',
        'quote_expired' => 'Kalkulace vypršela. Nechte si prosím spočítat aktuální cenu znovu.',
        'quote_changed' => 'Cena se mezitím změnila. Nechte si prosím spočítat aktuální cenu znovu.',
        'quote_invalid' => 'Kalkulace neodpovídá zadání. Nechte si prosím spočítat cenu znovu.',
        'not_bound' => 'Tato instalace není k licenci aktivně přiřazená.',
        'change_failed' => 'Změna mzdového doplňku se nezdařila. Zkuste to prosím znovu.',
    ];

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
        $quoteToken = trim((string) ($body['quote_token'] ?? ''));
        if ($quoteToken === '') {
            return Json::error($response, 'quote_required', 'Nejdříve si nechte spočítat aktuální cenu.', 400);
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
        $result = $this->license->changePayroll(
            $body['enabled'],
            $quoteToken,
            $body['payroll_employees_target'] ?? null,
            $body['payroll_users_target'] ?? null,
        );
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'change_failed');
            // ⚠️ `pay_url` musí projít až na obrazovku: po neprojité kartě je to
            // jediná nabídka, která dává smysl — opakovat totéž nepomůže.
            // U předplatného bez karty je tou nabídkou nákup na webu.
            $extra = isset($result['pay_url']) ? ['pay_url' => (string) $result['pay_url']] : [];
            if (in_array($error, PayrollQuoteAction::BUY_URL_ERRORS, true)) {
                $extra['buy_url'] = $this->license->buyUrl();
            }
            return Json::error(
                $response,
                $error,
                self::ERROR_MESSAGES[$error] ?? self::ERROR_MESSAGES['change_failed'],
                $error === 'server_unreachable' ? 503 : 422,
                $extra,
            );
        }
        $state = $result['state_local'] ?? $this->license->current();
        unset($result['ok'], $result['state_local']);
        $result['state'] = $state->toArray($this->license->buyUrl());
        return Json::ok($response, $result);
    }
}
