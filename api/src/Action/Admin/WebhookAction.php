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
use MyInvoice\Service\Integration\WebhookEndpoints;
use MyInvoice\Service\Integration\WebhookPolicy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class WebhookAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookPolicy $policy,
        private readonly ActivityLogger $audit,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId < 1 || !RequestAuthorization::canManageWebhooks($request)) {
            return Json::error($response, 'forbidden', 'Správa webhooků vyžaduje přihlášeného správce firmy.', 403);
        }

        $policy = $this->policy->mode();
        $licensed = $this->policy->licensed();
        $method = $request->getMethod();
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($method === 'GET' && $id === null) {
            return Json::ok($response, [
                'available' => $licensed,
                'policy' => $policy,
                'endpoints' => $licensed ? $this->endpoints->list($supplierId) : [],
            ])->withHeader('Cache-Control', 'no-store');
        }
        if (!$licensed) {
            return Json::error($response, 'webhooks_license_required', 'Webhooky vyžadují platnou placenou licenci.', 403);
        }
        if ($method === 'GET') {
            if ($this->endpoints->find($supplierId, $id) === null) {
                return Json::error($response, 'not_found', 'Webhook nenalezen.', 404);
            }
            $before = $request->getQueryParams()['before'] ?? PHP_INT_MAX;
            if (!is_scalar($before) || filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                return Json::error($response, 'invalid_cursor', 'Neplatný kurzor historie.', 422);
            }
            return Json::ok($response, $this->endpoints->history($supplierId, $id, (int) $before));
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if ($policy === 'disabled' && $method !== 'DELETE' && !($method === 'PATCH' && ($body['enabled'] ?? null) === false)) {
            return Json::error($response, 'webhooks_disabled', 'Odchozí webhooky jsou na instalaci vypnuté.', 403);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $this->mutate($request, $response, $supplierId, $id, $args, $body);
            $pdo->commit();
            return $result;
        } catch (\InvalidArgumentException|OutboundRequestException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error($response, 'invalid_webhook', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function mutate(Request $request, Response $response, int $supplierId, ?int $id, array $args, array $body): Response
    {
        $method = $request->getMethod();
        $operation = $args['operation'] ?? '';
        $status = 200;
        if ($id === null) {
            $output = $this->endpoints->create($supplierId, $body);
            $id = $output['id'];
            $operation = 'created';
            $status = 201;
        } else {
            $endpoint = $this->endpoints->find($supplierId, $id, lock: true);
            if ($endpoint === null) {
                return Json::error($response, 'not_found', 'Webhook nenalezen.', 404);
            }

            if ($method === 'DELETE') {
                $this->endpoints->delete($supplierId, $id);
                $output = ['deleted' => true];
                $operation = 'deleted';
            } elseif ($method === 'PATCH') {
                $this->endpoints->update($supplierId, $endpoint, $body);
                $output = ['id' => $id];
                $operation = 'updated';
            } elseif ($operation === 'rotate') {
                $output = ['secret' => $this->endpoints->rotate($supplierId, $id)];
                $operation = 'rotated';
            } elseif (!$endpoint['enabled']) {
                return Json::error($response, 'webhook_paused', 'Webhook je pozastavený.', 409);
            } elseif ($operation === 'test') {
                $eventId = $this->endpoints->enqueueTest($supplierId, $id);
                if ($eventId === null) {
                    return Json::error($response, 'webhook_test_rate_limit', 'Test lze odeslat jednou za minutu.', 429);
                }
                $output = ['event_id' => $eventId];
                $operation = 'tested';
                $status = 202;
            } else {
                if (!$this->endpoints->retry($supplierId, $id, (int) $args['deliveryId'])) {
                    return Json::error($response, 'delivery_conflict', 'Zprávu nelze opakovat.', 409);
                }
                $output = ['retried' => true];
                $operation = 'retried';
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->audit->log('webhook.' . $operation, (int) ($user['id'] ?? 0), 'webhook', $id, supplierId: $supplierId);
        return Json::ok($response, $output, $status)->withHeader('Cache-Control', 'no-store');
    }
}
