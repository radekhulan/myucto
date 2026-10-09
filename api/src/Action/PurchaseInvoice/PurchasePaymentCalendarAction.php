<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchasePaymentCalendarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/payment-calendar
 *
 * Z přijatého dokladu (první platba platebního kalendáře) založí další platby se stejným
 * číslem dokladu, každou se svou splatností a částkou (issue #140). Tělo:
 * `{ installments: [{ due_date: 'YYYY-MM-DD', amount: 1234.00 }, …] }`.
 */
final class PurchasePaymentCalendarAction
{
    public function __construct(
        private readonly PurchasePaymentCalendarService $calendar,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($supplierId === 0 || $id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $v = PurchasePaymentCalendarService::validate($body['installments'] ?? null);
        if ($v['errors'] !== []) {
            return Json::error($response, 'validation_failed', implode(' ', $v['errors']), 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        try {
            $ids = $this->calendar->createInstallments($supplierId, $id, $v['installments'], $userId);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'payment_calendar_failed', $e->getMessage(), 409);
        }
        $this->logger->log('purchase_invoice.payment_calendar_created', $userId, 'purchase_invoice', $id, [
            'created_ids' => $ids,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['created_ids' => $ids], 201);
    }
}
