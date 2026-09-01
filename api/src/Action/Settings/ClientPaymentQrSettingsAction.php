<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Úzké provozní nastavení QR delegovatelné klientské roli. */
final class ClientPaymentQrSettingsAction
{
    public function __construct(
        private readonly SupplierPaymentQrSettingsRepository $settings,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::paymentQr($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit provozní nastavení firmy.', 403);
        }
        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }
        $settings = $this->settings->find($supplierId);
        return $settings === null
            ? Json::error($response, 'not_found', 'Supplier nenalezen.', 404)
            : Json::ok($response, $settings);
    }

    public function update(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::paymentQr($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit provozní nastavení firmy.', 403);
        }

        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $unknown = array_values(array_diff(array_keys($body), SupplierPaymentQrSettingsRepository::FIELDS));
        if ($unknown !== []) {
            return Json::error(
                $response,
                'field_not_delegable',
                'Toto nastavení nelze klientské roli delegovat.',
                403,
                ['fields' => $unknown],
            );
        }
        foreach ($body as $field => $value) {
            if (!is_bool($value) && !in_array($value, [0, 1], true)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Přepínače musí mít logickou hodnotu.',
                    400,
                    ['fields' => [$field]],
                );
            }
            $body[$field] = (bool) $value;
        }

        try {
            $result = $this->settings->update($supplierId, $body);
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $invalidated = 0;
        if (SupplierPaymentQrSettingsRepository::invalidatesInvoicePdfs($result['changed'])) {
            $invalidated = $this->pdf->invalidatePaymentQrBySupplier($supplierId);
        }
        if ($result['changed'] !== []) {
            $this->log($request, $supplierId, $result, $invalidated);
        }
        return Json::ok($response, $result['settings']);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    /** @param array<string,mixed> $result */
    private function log(Request $request, int $supplierId, array $result, int $invalidated): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $changes = [];
        foreach ($result['changed'] as $field) {
            $changes[$field] = [
                'before' => $result['before'][$field],
                'after' => $result['settings'][$field],
            ];
        }
        $this->logger->log(
            'supplier.payment_qr_settings_updated',
            isset($user['id']) ? (int) $user['id'] : null,
            'supplier',
            $supplierId,
            ['changes' => $changes, 'invalidated_invoice_pdfs' => $invalidated],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
