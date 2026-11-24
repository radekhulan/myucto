<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Integration\InvoicePaidWebhooks;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InvoicePaidWebhookAction
{
    public function __construct(
        private readonly InvoicePaidWebhooks $hooks,
        private readonly ActivityLogger $audit,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId < 1 || !RequestAuthorization::canManageInvoiceWebhooks($request)) {
            return Json::error($response, 'forbidden', 'Správa webhooků vyžaduje správce firmy.', 403);
        }
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null && $id < 1) return Json::error($response, 'not_found', 'Webhook nenalezen.', 404);
        $read = in_array($request->getMethod(), ['GET', 'HEAD'], true);
        $body = $read ? $request->getQueryParams() : (array) ($request->getParsedBody() ?? []);
        $templateId = $body['template_id'] ?? null;
        if ($templateId !== null && (!is_int($templateId) && !is_string($templateId)
            || !filter_var($templateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            return Json::error($response, 'invalid_template', 'Neplatná šablona.', 422);
        }
        $templateId = $templateId === null ? null : (int) $templateId;
        if ($read) {
            return Json::ok($response, $id === null
                ? $this->hooks->list($supplierId, $templateId)
                : $this->hooks->history($supplierId, $id, (int) ($body['before'] ?? PHP_INT_MAX)));
        }
        // Klíč a audit se musí uložit společně. Při chybě auditu zůstane starý klíč platný.
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $this->mutate($request, $response, $supplierId, $id, $templateId, $body, $args);
            $pdo->commit();
            return $result;
        } catch (OutboundRequestException|\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'invalid_webhook', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function mutate(Request $request, Response $response, int $supplierId, ?int $id, ?int $templateId, array $body, array $args): Response
    {
        if ($id === null) {
            if (!is_string($body['url'] ?? null) || !is_bool($body['include_advance'] ?? false)) {
                return Json::error($response, 'invalid_webhook', 'Neplatné nastavení webhooku.', 422);
            }
            $result = $this->hooks->register($supplierId, $templateId, trim($body['url']), $body['include_advance'] ?? false);
            $id = $result['id'];
            $action = 'created';
        } elseif ($request->getMethod() === 'DELETE') {
            if (!$this->hooks->remove($supplierId, $id)) return Json::error($response, 'not_found', 'Webhook nenalezen.', 404);
            $action = 'deleted';
            $result = ['deleted' => true];
        } elseif (isset($args['deliveryId'])) {
            if (!$this->hooks->retry($supplierId, $id, (int) $args['deliveryId'])) {
                return Json::error($response, 'delivery_conflict', 'Zprávu nelze opakovat.', 409);
            }
            $action = 'retried';
            $result = ['retried' => true];
        } else {
            $secret = $this->hooks->rotate($supplierId, $id);
            if ($secret === null) return Json::error($response, 'not_found', 'Webhook nenalezen.', 404);
            $action = 'rotated';
            $result = ['secret' => $secret];
        }
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $this->audit->log('invoice_webhook.' . $action, (int) $user['id'], 'invoice_webhook', $id, supplierId: $supplierId);
        return Json::ok($response, $result, $action === 'created' ? 201 : 200);
    }
}
