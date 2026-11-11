<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payment\RefundPaymentOrderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vratka odběrateli z detailu dokladu k vyplacení.
 *
 *   GET  /api/invoices/{id}/refund-order/prefill → účty klienta, účty plátce, částka, VS
 *   POST /api/invoices/{id}/refund-order         → platební příkaz s jednou položkou
 *
 * Soubor se pak stahuje přes /api/purchase-invoices/payment-orders/{id}/download
 * a do banky odesílá přes .../{id}/submit. Oprávnění stejné jako u platebních příkazů.
 */
final class RefundPaymentOrderAction
{
    private const PERMISSION = 'purchase_invoices.payment_orders';

    public function __construct(
        private readonly RefundPaymentOrderService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function prefill(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        try {
            $data = $this->service->prefill((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        } catch (\DomainException $e) {
            return $this->conflict($response, $e);
        }
        if ($data === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }
        return Json::ok($response, $data);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::WRITE)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($args['id'] ?? 0);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->service->create($invoiceId, $supplierId, [
                'payer_currency_id' => (int) ($body['payer_currency_id'] ?? 0),
                'payment_date'      => (string) ($body['payment_date'] ?? ''),
                'account_number'    => (string) ($body['account_number'] ?? ''),
                'bank_code'         => (string) ($body['bank_code'] ?? ''),
                'iban'              => $body['iban'] ?? null,
                'save_to_client'    => (bool) ($body['save_to_client'] ?? false),
                'note'              => $body['note'] ?? null,
            ], $userId);
        } catch (\DomainException $e) {
            return $this->conflict($response, $e);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if ($result === null) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        $this->logger->log('payment_order.created', $userId, 'payment_order', $result['order_id'], [
            'item_count'        => $result['view']['item_count'] ?? 0,
            'total'             => $result['view']['total_amount'] ?? 0,
            'currency'          => $result['view']['currency'] ?? null,
            'refund_invoice_id' => $invoiceId,
            'saved_account'     => $result['saved_account'] !== null,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $result, 201);
    }

    private function conflict(Response $response, \DomainException $e): Response
    {
        return $e->getMessage() === RefundPaymentOrderService::REFUND_DISABLED
            ? Json::error($response, 'refund_disabled', 'Vyplácení přeplatků není u firmy zapnuté.', 409)
            : Json::error($response, 'not_refundable', 'Doklad není otevřený k vyplacení převodem.', 409);
    }

    private function denied(Request $request, Response $response, AccessLevel $minimum): ?Response
    {
        if (RequestAuthorization::allows($request, self::PERMISSION, $minimum)) {
            return null;
        }
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
    }
}
