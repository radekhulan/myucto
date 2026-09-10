<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\FulfillmentService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FulfillmentAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly FulfillmentService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->enabled($request, $response, $err)) {
            return $err;
        }
        $limit = max(1, min(500, (int) ($request->getQueryParams()['limit'] ?? 100)));
        return Json::ok($response, ['items' => $this->service->history($this->currentSupplierId($request), $limit)]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->enabled($request, $response, $err)) {
            return $err;
        }
        $task = $this->service->find($this->currentSupplierId($request), (int) $args['id']);
        return $task === null
            ? Json::error($response, 'stock.error.fulfillment_task_not_found', 'Vychystávací úloha nebyla nalezena.', 404)
            : Json::ok($response, $task);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->write($request, $response, function (int $supplierId, array $body) use ($request): array {
            $result = $this->service->createTask(
                $supplierId,
                (string) ($body['source_type'] ?? 'stock_issue_draft'),
                (string) ($body['source_id'] ?? ''),
                $this->userId($request),
            );
            $this->log($request, 'stock.fulfillment_created', (int) $result['id'], ['source_type' => $result['source_type']]);
            return [$result, 201];
        });
    }

    public function scan(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, array $body) use ($request, $args): array {
            $canOverride = RequestAuthorization::allows($request, 'stock.fulfillment.override', AccessLevel::WRITE);
            $result = $this->service->scan($supplierId, (int) $args['id'], $body, $this->userId($request), $canOverride);
            return [$result, 200];
        });
    }

    public function createShipment(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, array $body) use ($request, $args): array {
            $result = $this->service->createShipment($supplierId, (int) $args['id'], $body, $this->userId($request));
            $this->log($request, 'stock.fulfillment_shipment_created', (int) $result['id'], ['task_id' => (int) $args['id']]);
            return [$result, 201];
        });
    }

    public function dispatch(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, array $_body) use ($request, $args): array {
            $task = $this->service->dispatch($supplierId, (int) $args['shipmentId'], $this->userId($request));
            $this->log($request, 'stock.fulfillment_dispatched', (int) $args['shipmentId'], ['task_id' => (int) $task['id']]);
            return [$task, 200];
        });
    }

    public function receiveReturn(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, array $body) use ($request, $args): array {
            $result = $this->service->receiveReturn($supplierId, (int) $args['shipmentId'], $body, $this->userId($request));
            $this->log($request, 'stock.fulfillment_return_received', (int) $result['id'], ['disposition' => $result['disposition']]);
            return [$result, 201];
        });
    }

    /** @param callable(int,array<string,mixed>):array{0:array<string,mixed>,1:int} $operation */
    private function write(Request $request, Response $response, callable $operation): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.fulfillment.write', AccessLevel::WRITE, $err)) {
            return $err;
        }
        if (!$this->enabled($request, $response, $err)) {
            return $err;
        }
        try {
            [$data, $status] = $operation($this->currentSupplierId($request), (array) ($request->getParsedBody() ?? []));
            return Json::ok($response, $data, $status);
        } catch (\Throwable $e) {
            if ($e instanceof StockException) {
                return Json::error($response, 'stock.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus, ['items' => $e->details]);
            }
            throw $e;
        }
    }

    private function enabled(Request $request, Response $response, ?Response &$err): bool
    {
        return $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $err);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'fulfillment',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
