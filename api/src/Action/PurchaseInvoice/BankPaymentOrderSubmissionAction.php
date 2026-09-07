<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankPaymentOrderSubmissionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankPaymentOrderSubmissionAction
{
    public function __construct(private readonly BankPaymentOrderSubmissionService $service) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'purchase_invoices.payment_orders', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        return Json::ok($response, [
            'submission' => $this->service->find(
                SupplierGuard::currentId($request),
                (int) ($args['orderId'] ?? 0),
            ),
        ]);
    }

    public function post(Request $request, Response $response, array $args): Response
    {
        if (
            !RequestAuthorization::allows($request, 'purchase_invoices.payment_orders', AccessLevel::WRITE)
            || !RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::WRITE)
        ) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $connectionId = (int) ($body['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            return Json::error($response, 'validation_failed', 'Vyberte bankovní spojení.', 422);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $result = $this->service->submit(
                $supplierId,
                (int) ($args['orderId'] ?? 0),
                $connectionId,
                (int) ($user['id'] ?? 0) ?: null,
            );
        } catch (BankConnectorOperationException $e) {
            $status = match ($e->errorCode) {
                'payment_order_not_found', 'connection_not_found' => 404,
                'bank_connection_busy', 'bank_rate_limited' => 409,
                'submission_failed' => 502,
                default => 422,
            };
            return Json::error(
                $response,
                $e->errorCode,
                'Platební příkaz nelze bezpečně předat bance.',
                $status,
            );
        }

        if (!$result['created']) {
            return Json::error(
                $response,
                'payment_order_already_submitted',
                'Tento platební příkaz už byl bance předán nebo má nejistý výsledek.',
                409,
                ['submission' => $result['submission']],
            );
        }

        $status = match ($result['submission']['status']) {
            'accepted_awaiting_authorization' => 202,
            'rejected' => 422,
            default => 502,
        };
        return Json::ok($response, ['submission' => $result['submission']], $status);
    }
}
