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
            return Json::error($response, $error, 'Změnu mzdového doplňku se nepodařilo spočítat.', $error === 'server_unreachable' ? 503 : 422);
        }
        unset($result['ok']);
        return Json::ok($response, $result);
    }
}
