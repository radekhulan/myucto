<?php

declare(strict_types=1);

namespace MyInvoice\Action\License;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\License\LicenseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TierChangeAction
{
    private const TIERS = ['single', 'multi10', 'unlimited'];

    public function __construct(private readonly LicenseService $license) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $tier = trim((string) ($body['tier'] ?? ''));
        $quoteToken = trim((string) ($body['quote_token'] ?? ''));
        if (!in_array($tier, self::TIERS, true)) {
            return Json::error($response, 'validation_failed', 'Zvolte platný tarif.', 400);
        }
        if ($quoteToken === '') {
            return Json::error($response, 'quote_required', 'Nejdříve si nechte spočítat aktuální cenu.', 400);
        }
        $result = $this->license->changeTier($tier, $quoteToken);
        if (($result['ok'] ?? false) !== true) {
            $error = (string) ($result['error'] ?? 'change_failed');
            $message = $error === 'charge_failed'
                ? 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte jinou kartou '
                    . 'přes odkaz níž — poslali jsme ho i e-mailem.'
                : 'Změna tarifu se nezdařila.';
            // ⚠️ `pay_url` musí projít až na obrazovku: po neprojité kartě je to
            // jediná nabídka, která dává smysl — opakovat totéž nepomůže.
            $extra = isset($result['pay_url']) ? ['pay_url' => (string) $result['pay_url']] : [];
            return Json::error($response, $error, $message, $error === 'server_unreachable' ? 503 : 422, $extra);
        }
        $state = $result['state_local'] ?? $this->license->current();
        unset($result['ok'], $result['state_local']);
        if (isset($result['order_id'])) {
            $result['order_id'] = (string) $result['order_id'];
        }
        $result['state'] = $state->toArray($this->license->buyUrl());
        return Json::ok($response, $result);
    }
}
