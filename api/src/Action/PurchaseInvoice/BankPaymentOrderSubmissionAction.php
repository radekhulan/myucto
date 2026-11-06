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
use MyInvoice\Service\Bank\Connector\BankPaymentSignatureService;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionHttp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BankPaymentOrderSubmissionAction
{
    public function __construct(
        private readonly BankPaymentOrderSubmissionService $service,
        private readonly BankPaymentSignatureService $signatures,
    ) {}

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
        if (!$this->mayWrite($request)) {
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
            return $this->failure($response, $e);
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

        return Json::ok($response, ['submission' => $result['submission']],
            BankPaymentSubmissionHttp::forStatus((string) $result['submission']['status']));
    }

    /**
     * Vydá adresu podpisu dávky v bance (Česká spořitelna). Podpis vyžaduje přesměrování
     * prohlížeče, proto jen pro přihlášeného uživatele, ne pro API token.
     */
    public function sign(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request) || !$this->mayWrite($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $result = $this->signatures->startSignature(SupplierGuard::currentId($request), (int) ($args['orderId'] ?? 0),
                'purchase_invoice', (int) ($user['id'] ?? 0));
        } catch (BankConnectorOperationException $e) {
            return $this->failure($response, $e);
        }
        return Json::ok($response, $result)->withHeader('Cache-Control', 'no-store');
    }

    /** Zjistí u banky stav podpisu a provedení plateb; nic nezakládá ani neposílá znovu. */
    public function refresh(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request) || !$this->mayWrite($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $submission = $this->signatures->refresh(SupplierGuard::currentId($request), (int) ($args['orderId'] ?? 0), 'purchase_invoice');
        } catch (BankConnectorOperationException $e) {
            return $this->failure($response, $e);
        }
        return Json::ok($response, ['submission' => $submission])->withHeader('Cache-Control', 'no-store');
    }

    /** Položky nejasného nebo přerušeného odeslání ke kontrole v George (částka, účet, VS, identifikátor banky). */
    public function items(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)
            || !RequestAuthorization::allows($request, 'purchase_invoices.payment_orders', AccessLevel::READ)
            || !RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $items = $this->signatures->items(SupplierGuard::currentId($request), (int) ($args['orderId'] ?? 0), 'purchase_invoice');
        } catch (BankConnectorOperationException $e) {
            return $this->failure($response, $e);
        }
        return Json::ok($response, ['items' => $items])->withHeader('Cache-Control', 'no-store');
    }

    private function mayWrite(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'purchase_invoices.payment_orders', AccessLevel::WRITE)
            && RequestAuthorization::allows($request, 'settings.bank_accounts', AccessLevel::WRITE);
    }

    private function failure(Response $response, BankConnectorOperationException $e): Response
    {
        return Json::error(
            $response,
            $e->errorCode,
            'Platební příkaz nelze bezpečně předat bance.',
            BankPaymentSubmissionHttp::forError($e->errorCode),
            $e->details,
        );
    }
}
